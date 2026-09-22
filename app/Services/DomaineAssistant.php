<?php

namespace App\Services;

use App\Models\AssistantDocument;
use App\Models\AssistantDossier;
use App\Models\Secteur;
use Illuminate\Support\Str;

/**
 * Déduit les dossiers nommés dans la question posée, pour PRIORISER leurs documents dans la
 * recherche du service Python — jamais pour la restreindre : principe validé par le client, la
 * recherche porte TOUJOURS sur tout le secteur, un dossier reconnu ne fait qu'ajouter des
 * passages, pour qu'un document mal rangé reste trouvable.
 */
class DomaineAssistant
{
    /** Au-delà, la liste ne veut plus rien dire de « ciblé » : autant laisser chercher partout. */
    private const MAX_DOCUMENTS = 200;

    /** Longueur minimale (strictement) du nom d'un dossier pour être reconnu dans la question. */
    private const LONGUEUR_MIN_NOM = 3;

    /**
     * Identifiants des documents PRÊTS des dossiers reconnus dans la question (et de leurs
     * sous-dossiers, à tous les niveaux) ; [] si aucun dossier n'est reconnu, ou si la liste
     * dépasse MAX_DOCUMENTS identifiants (plus « ciblée », un filtre aussi large ne veut plus rien
     * dire de prioritaire).
     *
     * @return list<int>
     */
    public function documentsPrioritaires(Secteur $secteur, string $question): array
    {
        $mots = $this->normaliserEnMots($question);
        if ($mots === []) {
            return [];
        }

        $dossiers = AssistantDossier::where('secteur_id', $secteur->id)->get();
        // Relation "enfants" pré-remplie en mémoire (à partir de la même collection) : descendants()
        // peut alors parcourir toute l'arborescence sans requête supplémentaire.
        $parParent = $dossiers->groupBy('parent_id');
        foreach ($dossiers as $dossier) {
            $dossier->setRelation('enfants', $parParent->get($dossier->id, collect()));
        }

        $reconnus = $dossiers->filter(fn (AssistantDossier $dossier) => $this->estReconnu($dossier, $mots));
        if ($reconnus->isEmpty()) {
            return [];
        }

        $idsDossiers = $reconnus
            ->flatMap(fn (AssistantDossier $dossier) => collect([$dossier])->merge($dossier->descendants()))
            ->pluck('id')->unique()->values();

        $idsDocuments = AssistantDocument::where('secteur_id', $secteur->id)
            ->where('statut', AssistantDocument::PRET)
            ->whereIn('dossier_id', $idsDossiers)
            ->pluck('id');

        if ($idsDocuments->count() > self::MAX_DOCUMENTS) {
            return [];
        }

        return $idsDocuments->values()->all();
    }

    /** @param  list<string>  $mots */
    private function estReconnu(AssistantDossier $dossier, array $mots): bool
    {
        $nom = $this->normaliser($dossier->nom);
        if (mb_strlen($nom) <= self::LONGUEUR_MIN_NOM) {
            return false;
        }
        $nomSansS = Str::endsWith($nom, 's') ? mb_substr($nom, 0, -1) : $nom;

        return in_array($nom, $mots, true) || in_array($nomSansS, $mots, true);
    }

    /** @return list<string> */
    private function normaliserEnMots(string $texte): array
    {
        return preg_split('/[^a-z0-9]+/', $this->normaliser($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Sans accents ni casse, pour une comparaison stable (« Entretien » ≡ « entretien » ≡ « ENTRETIEN »). */
    private function normaliser(string $valeur): string
    {
        return Str::lower(Str::ascii($valeur));
    }
}
