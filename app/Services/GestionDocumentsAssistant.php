<?php

namespace App\Services;

use App\Models\AssistantDocument;
use Illuminate\Support\Facades\Storage;

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
}
