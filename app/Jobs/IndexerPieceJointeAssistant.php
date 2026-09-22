<?php

namespace App\Jobs;

use App\Models\AssistantPieceJointe;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\DocumentIllisible;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Prépare une pièce jointe de conversation pour l'assistant (lecture, découpage, embedding par le
 * service) — même structure que IndexerDocumentAssistant. Pas de « remplacement » ici : une pièce
 * jointe n'est jamais remplacée, seulement ajoutée ou retirée.
 */
class IndexerPieceJointeAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $pieceId)
    {
    }

    public function handle(AssistantClient $client): void
    {
        $piece = AssistantPieceJointe::find($this->pieceId);
        if ($piece === null) {
            return;
        }

        $piece->update(['statut' => AssistantPieceJointe::EN_COURS, 'message' => null, 'detail' => null]);

        try {
            $nb = $client->indexerPieceJointe($piece);
        } catch (DocumentIllisible $e) {
            $piece->update(['statut' => AssistantPieceJointe::ERREUR, 'message' => $e->getMessage(), 'detail' => $e->detail]);

            return;
        } catch (AssistantIndisponible $e) {
            // Le service a pu enregistrer une partie des morceaux avant l'échec : on tente de les
            // retirer au mieux. Contrairement à un document, il n'existe pas de retrait par pièce
            // jointe (seulement par conversation entière, supprimerConversation) : cet appel efface
            // aussi les vecteurs des AUTRES pièces déjà prêtes de cette même conversation. C'est le
            // seul outil disponible ; le risque de collatéral reste faible en pratique (l'échec vient
            // le plus souvent d'une panne du service, auquel cas cet appel échoue lui aussi et ne fait
            // rien — voir le catch ci-dessous).
            $this->retirerVecteurs($client, $piece);
            $piece->update(['statut' => AssistantPieceJointe::ERREUR, 'message' => $e->getMessage(), 'detail' => $e->detail]);

            return;
        }

        if ($piece->fresh() === null) {
            // Pièce jointe supprimée pendant son indexation : ses vecteurs ne doivent pas rester orphelins.
            $this->retirerVecteurs($client, $piece);

            return;
        }

        $piece->update(['statut' => AssistantPieceJointe::PRET, 'nb_morceaux' => $nb]);
    }

    /** Échec du job lui-même (délai du worker dépassé, plantage) : la pièce ne reste pas « En préparation ». */
    public function failed(Throwable $e): void
    {
        AssistantPieceJointe::whereKey($this->pieceId)->update([
            'statut' => AssistantPieceJointe::ERREUR,
            'message' => AssistantClient::INDISPONIBLE,
            'detail' => $e::class.': '.$e->getMessage(),
        ]);
    }

    /** Suppression « au mieux » de l'espace de recherche de la conversation (le service peut être indisponible). */
    private function retirerVecteurs(AssistantClient $client, AssistantPieceJointe $piece): void
    {
        try {
            $client->supprimerConversation($piece->conversation_id);
        } catch (AssistantIndisponible) {
            // Tant pis : rien de plus à faire ici.
        }
    }
}
