<?php

namespace App\Services;

use App\Models\AssistantDocument;
use App\Models\AssistantDossier;
use App\Models\Secteur;
use Illuminate\Support\Str;

/** Gère l'arborescence de dossiers de l'onglet « Documents » d'un secteur (création, renommage, déplacement, suppression). */
class GestionDossiersAssistant
{
    private const PROFONDEUR_MAX = 10;

    public function __construct(private GestionDocumentsAssistant $gestionDocuments)
    {
    }

    public function creer(Secteur $secteur, ?int $parentId, string $nom): AssistantDossier
    {
        $nom = $this->validerNom($nom);
        $parent = $this->resoudreDossier($secteur, $parentId);

        $profondeur = $parent !== null ? $parent->profondeur() + 1 : 1;
        if ($profondeur > self::PROFONDEUR_MAX) {
            throw new ActionDossierRefusee('Arborescence trop profonde (10 niveaux maximum).');
        }

        $this->verifierDoublonDossier($secteur->id, $parentId, $nom);

        return AssistantDossier::create([
            'secteur_id' => $secteur->id,
            'parent_id' => $parentId,
            'nom' => $nom,
        ]);
    }

    public function renommer(AssistantDossier $dossier, string $nom): void
    {
        $nom = $this->validerNom($nom);
        $this->verifierDoublonDossier($dossier->secteur_id, $dossier->parent_id, $nom, ignorerDossierId: $dossier->id);

        $dossier->update(['nom' => $nom]);
    }

    public function deplacerDossier(AssistantDossier $dossier, ?int $destinationId): void
    {
        $destination = $this->resoudreDossier($dossier->secteur, $destinationId);

        if ($destination !== null
            && ($destination->id === $dossier->id || $this->estDescendantDe($dossier, $destination))
        ) {
            throw new ActionDossierRefusee('Un dossier ne peut pas être déplacé dans lui-même.');
        }

        $nouvelleProfondeur = $destination !== null ? $destination->profondeur() + 1 : 1;
        $decalage = $this->profondeurMaxSousArbre($dossier) - $dossier->profondeur();
        if ($nouvelleProfondeur + $decalage > self::PROFONDEUR_MAX) {
            throw new ActionDossierRefusee('Arborescence trop profonde (10 niveaux maximum).');
        }

        $this->verifierDoublonDossier($dossier->secteur_id, $destinationId, $dossier->nom, ignorerDossierId: $dossier->id);

        $dossier->update(['parent_id' => $destinationId]);
    }

    public function deplacerDocument(AssistantDocument $document, ?int $destinationId): void
    {
        // Vérifie que le dossier de destination existe et appartient bien au même secteur.
        $this->resoudreDossier($document->secteur, $destinationId);

        if ($this->gestionDocuments->nomExisteDejaDansDossier(
            $document->secteur_id, $destinationId, $document->nom, ignorerDocumentId: $document->id
        )) {
            throw new ActionDossierRefusee("Un document « {$document->nom} » existe déjà dans ce dossier.");
        }

        $document->update(['dossier_id' => $destinationId]);
    }

    /**
     * Supprime le dossier et tout son sous-arbre : d'abord tous les documents (vecteurs, fichier, ligne),
     * puis les dossiers du plus profond vers le parent. La première indisponibilité du service interrompt
     * tout avant qu'un seul dossier ne soit supprimé en base : un secteur ne doit jamais garder de vecteurs
     * orphelins.
     *
     * @return array{dossiers: int, documents: int}
     */
    public function supprimer(AssistantDossier $dossier): array
    {
        $compte = $this->compter($dossier);
        $chemin = $dossier->chemin();

        $tousLesDossiers = $dossier->descendants()->push($dossier);
        $ids = $tousLesDossiers->pluck('id');

        $documents = AssistantDocument::whereIn('dossier_id', $ids)->orderBy('id')->get();
        foreach ($documents as $document) {
            $this->gestionDocuments->supprimer($document);
        }

        $tousLesDossiers->sortByDesc(fn (AssistantDossier $d) => $d->profondeur())
            ->each(fn (AssistantDossier $d) => $d->delete());

        activity('assistant')
            ->event('suppression_dossier')
            ->performedOn($dossier)
            ->withProperties([
                'secteur' => $dossier->secteur->slug,
                'dossier' => $chemin,
                'sous_dossiers' => $compte['dossiers'],
                'documents' => $compte['documents'],
            ])
            ->log("Dossier {$chemin} supprimé ({$compte['dossiers']} sous-dossiers, {$compte['documents']} documents)");

        return $compte;
    }

    /** @return array{dossiers: int, documents: int} nombre de sous-dossiers et de documents du sous-arbre (dossier lui-même exclu). */
    public function compter(AssistantDossier $dossier): array
    {
        $descendants = $dossier->descendants();
        $ids = $descendants->pluck('id')->push($dossier->id);

        return [
            'dossiers' => $descendants->count(),
            'documents' => AssistantDocument::whereIn('dossier_id', $ids)->count(),
        ];
    }

    /** Coupe les espaces superflus puis valide le nom ; renvoie le nom coupé, à utiliser pour la création/le renommage. */
    private function validerNom(string $nom): string
    {
        $nom = trim($nom);

        if (mb_strlen($nom) < 1 || mb_strlen($nom) > 100) {
            throw new ActionDossierRefusee('Le nom du dossier doit faire entre 1 et 100 caractères.');
        }

        if (str_contains($nom, '›')) {
            throw new ActionDossierRefusee('Le nom du dossier ne peut pas contenir le caractère « › ».');
        }

        return $nom;
    }

    private function verifierDoublonDossier(int $secteurId, ?int $parentId, string $nom, ?int $ignorerDossierId = null): void
    {
        $nomNormalise = Str::lower(Str::ascii($nom));

        $existe = AssistantDossier::where('secteur_id', $secteurId)
            ->where('parent_id', $parentId)
            ->when($ignorerDossierId !== null, fn ($requete) => $requete->where('id', '!=', $ignorerDossierId))
            ->get()
            ->contains(fn (AssistantDossier $d) => Str::lower(Str::ascii($d->nom)) === $nomNormalise);

        if ($existe) {
            throw new ActionDossierRefusee("Un dossier « {$nom} » existe déjà ici.");
        }
    }

    /** Résout un identifiant de dossier de destination dans le secteur donné (null = racine). */
    private function resoudreDossier(Secteur $secteur, ?int $dossierId): ?AssistantDossier
    {
        if ($dossierId === null) {
            return null;
        }

        $dossier = AssistantDossier::where('id', $dossierId)->where('secteur_id', $secteur->id)->first();
        if ($dossier === null) {
            throw new ActionDossierRefusee('Dossier de destination introuvable.');
        }

        return $dossier;
    }

    private function estDescendantDe(AssistantDossier $dossier, AssistantDossier $eventuelDescendant): bool
    {
        return $dossier->descendants()->contains(fn (AssistantDossier $d) => $d->id === $eventuelDescendant->id);
    }

    /** Profondeur du nœud le plus profond du sous-arbre (dossier compris). */
    private function profondeurMaxSousArbre(AssistantDossier $dossier): int
    {
        $descendants = $dossier->descendants();
        if ($descendants->isEmpty()) {
            return $dossier->profondeur();
        }

        return $descendants->max(fn (AssistantDossier $d) => $d->profondeur());
    }
}
