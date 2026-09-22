<?php

namespace App\Services;

use App\Models\AssistantConversation;
use App\Models\AssistantPieceJointe;
use Illuminate\Support\Facades\Storage;

/**
 * Suppression d'une conversation avec l'assistant IA (et nettoyage des conversations abandonnées).
 */
class GestionConversationsAssistant
{
    public function __construct(private AssistantClient $client)
    {
    }

    /**
     * Vecteurs (service), puis fichiers des pièces jointes sur le disque, puis la ligne (cascade sur
     * les messages et les pièces jointes). Rien n'est supprimé si le service ne répond pas.
     */
    public function supprimer(AssistantConversation $conversation): void
    {
        $this->client->supprimerConversation($conversation->id);

        foreach ($conversation->piecesJointes as $piece) {
            Storage::disk('local')->delete($piece->chemin);
        }

        $conversation->delete();
    }

    /**
     * Conversations sans aucun message, créées il y a plus de 24 h (une pièce jointe déposée avant la
     * première question crée la conversation — voir AssistantChat::updatedPieces — mais celle-ci ne
     * doit pas traîner indéfiniment si elle n'est jamais utilisée). Exclut les conversations dont une
     * pièce est encore `en_attente`/`en_cours` : une file de jobs engorgée plus de 24 h ne doit pas
     * faire disparaître silencieusement la conversation, ses pièces et leurs fichiers pendant
     * l'indexation. Appelée une fois par visite de l'onglet Assistant IA, pour l'utilisateur courant.
     */
    public function nettoyerVides(int $userId, int $secteurId): void
    {
        $conversations = AssistantConversation::where('user_id', $userId)
            ->where('secteur_id', $secteurId)
            ->whereDoesntHave('messages')
            ->whereDoesntHave('piecesJointes', fn ($q) => $q->whereIn('statut', [
                AssistantPieceJointe::EN_ATTENTE, AssistantPieceJointe::EN_COURS,
            ]))
            ->where('created_at', '<', now()->subDay())
            ->get();

        foreach ($conversations as $conversation) {
            try {
                $this->supprimer($conversation);
            } catch (AssistantIndisponible) {
                // Service indisponible : on retentera au prochain chargement de l'onglet.
            }
        }
    }
}
