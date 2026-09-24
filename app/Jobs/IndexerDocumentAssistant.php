<?php

namespace App\Jobs;

use App\Models\AssistantDocument;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\DocumentIllisible;
use App\Services\GestionDocumentsAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Prépare un document pour l'assistant (lecture, découpage, embedding par le service).
 * « Remplacer » : l'ancien document n'est supprimé qu'une fois le nouveau prêt.
 */
class IndexerDocumentAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $documentId, public ?int $remplaceId = null)
    {
    }

    public function handle(AssistantClient $client, GestionDocumentsAssistant $gestion): void
    {
        $document = AssistantDocument::find($this->documentId);
        if ($document === null) {
            return;
        }

        $document->update(['statut' => AssistantDocument::EN_COURS, 'message' => null, 'detail' => null]);

        try {
            $nb = $client->indexer($document);
        } catch (DocumentIllisible $e) {
            $document->update(['statut' => AssistantDocument::ERREUR, 'message' => $e->getMessage(), 'detail' => $e->detail]);

            return;
        } catch (AssistantIndisponible $e) {
            // Le service a pu enregistrer une partie des morceaux avant l'échec : on tente de les retirer.
            $this->retirerVecteurs($client, $document);
            $document->update(['statut' => AssistantDocument::ERREUR, 'message' => $e->getMessage(), 'detail' => $e->detail]);

            return;
        }

        if ($document->fresh() === null) {
            // Document supprimé pendant son indexation : ses vecteurs ne doivent pas rester orphelins.
            $this->retirerVecteurs($client, $document);

            return;
        }

        $document->update(['statut' => AssistantDocument::PRET, 'nb_morceaux' => $nb]);

        $ancien = $this->remplaceId ? AssistantDocument::find($this->remplaceId) : null;
        if ($ancien !== null && $ancien->secteur_id === $document->secteur_id) {
            try {
                $gestion->supprimer($ancien);
            } catch (AssistantIndisponible) {
                // Service momentanément indisponible : l'ancien reste, le chef pourra le supprimer (✕).
            }
        }
    }

    /** Échec du job lui-même (délai du worker dépassé, plantage) : le document ne reste pas « En cours… ». */
    public function failed(Throwable $e): void
    {
        AssistantDocument::whereKey($this->documentId)->update([
            'statut' => AssistantDocument::ERREUR,
            'message' => AssistantClient::INDISPONIBLE,
            'detail' => $e::class.': '.$e->getMessage(),
        ]);
    }

    /** Suppression « au mieux » des vecteurs du document (le service peut être indisponible). */
    private function retirerVecteurs(AssistantClient $client, AssistantDocument $document): void
    {
        try {
            $client->supprimer($document);
        } catch (AssistantIndisponible) {
            // Tant pis : rien de plus à faire ici.
        }
    }
}
