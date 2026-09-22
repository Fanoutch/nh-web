<?php

namespace App\Services;

use App\Models\AssistantDocument;
use App\Models\Secteur;
use Illuminate\Support\Str;

/**
 * Enrichit les sources d'une réponse (chemin du dossier du document, FIGÉ au moment d'enregistrer
 * le message : renommer le dossier plus tard ne réécrit pas les anciennes réponses) et prépare le
 * surlignage de l'extrait affiché dans l'encart « Passage utilisé ».
 */
class SourcesAssistant
{
    /** Longueur minimale (strictement) d'un mot de la question pour être surligné dans l'extrait. */
    private const LONGUEUR_MIN_MOT = 3;

    /**
     * Ajoute `chemin` à chaque source : chemin du dossier du document (« » à la racine du secteur,
     * « » aussi pour une pièce jointe — qui n'appartient à aucun dossier du secteur). Les documents
     * cités sont chargés en une seule requête.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public function enrichir(Secteur $secteur, array $sources): array
    {
        $idsDocuments = collect($sources)->pluck('document_id')->filter()->unique()->values();
        $documents = AssistantDocument::whereIn('id', $idsDocuments)
            ->where('secteur_id', $secteur->id)
            ->with('dossier')
            ->get()->keyBy('id');

        return array_map(function (array $source) use ($documents) {
            if (! empty($source['pj'])) {
                $source['chemin'] = '';

                return $source;
            }

            $document = $documents->get($source['document_id'] ?? null);
            $source['chemin'] = $document?->dossier?->chemin() ?? '';

            return $source;
        }, $sources);
    }

    /**
     * Surligne (balise <mark>) les mots de plus de 3 caractères de la question dans l'extrait,
     * sans accent ni casse. L'extrait vient du modèle et des documents (jamais du HTML de
     * confiance) : il est échappé AVANT le surlignage, seules les balises <mark> ajoutées ici sont
     * du HTML.
     */
    public function surligner(string $extrait, string $question): string
    {
        $html = e($extrait);
        $mots = $this->normaliserEnMots($question);
        if ($mots === []) {
            return $html;
        }

        return preg_replace_callback('/[\p{L}\p{N}]+/u', function (array $correspondance) use ($mots) {
            $mot = $correspondance[0];
            $normalise = Str::lower(Str::ascii($mot));

            return in_array($normalise, $mots, true) ? '<mark>'.$mot.'</mark>' : $mot;
        }, $html);
    }

    /** @return list<string> mots normalisés de plus de 3 caractères */
    private function normaliserEnMots(string $question): array
    {
        $normalisee = Str::lower(Str::ascii($question));
        $mots = preg_split('/[^a-z0-9]+/', $normalisee, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($mots, fn ($mot) => mb_strlen($mot) > self::LONGUEUR_MIN_MOT)));
    }
}
