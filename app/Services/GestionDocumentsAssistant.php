<?php

namespace App\Services;

use App\Models\AssistantDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GestionDocumentsAssistant
{
    public function __construct(private AssistantClient $client)
    {
    }

    /** Vecteurs (service), puis fichier, puis ligne. Rien n'est supprimé si le service ne répond pas. */
    public function supprimer(AssistantDocument $document): void
    {
        $this->client->supprimer($document);
        Storage::disk('local')->delete($document->chemin);
        $document->delete();
    }

    /** Vrai si un document du même nom (insensible à la casse et aux accents) existe déjà dans ce dossier. */
    public function nomExisteDejaDansDossier(int $secteurId, ?int $dossierId, string $nom, ?int $ignorerDocumentId = null): bool
    {
        $nomNormalise = Str::lower(Str::ascii($nom));

        return AssistantDocument::where('secteur_id', $secteurId)
            ->where('dossier_id', $dossierId)
            ->when($ignorerDocumentId !== null, fn ($requete) => $requete->where('id', '!=', $ignorerDocumentId))
            ->get()
            ->contains(fn (AssistantDocument $d) => Str::lower(Str::ascii($d->nom)) === $nomNormalise);
    }
}
