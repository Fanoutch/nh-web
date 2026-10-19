<?php

namespace App\Services;

use App\Models\AssistantDocument;
use App\Models\AssistantPieceJointe;
use App\Models\Secteur;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client du service Python nh-assistant (127.0.0.1, jeton X-Assistant-Jeton).
 * Les droits sont vérifiés AVANT d'appeler ce client (SecteurPolicy).
 */
class AssistantClient
{
    use Concerns\ParleAuService;

    public const INDISPONIBLE = "L'assistant est indisponible pour le moment, réessayez plus tard.";

    /** @return list<string> extensions acceptées, ex. ['docx', 'pdf', 'txt'] */
    public function formats(): array
    {
        return Cache::remember('assistant.formats', 600, function () {
            $reponse = $this->envoyer(fn () => $this->http(10)->get('/formats'));

            return $reponse->json('formats', []);
        });
    }

    public function indexer(AssistantDocument $document): int
    {
        $reponse = $this->envoyer(fn () => $this->http(config('assistant.timeout_indexation'))->post('/documents', [
            'secteur' => $document->secteur->slug,
            'document_id' => $document->id,
            'chemin' => $document->cheminAbsolu(),
            'nom' => $document->nom,
        ]), accepter422: true);

        if ($reponse->status() === 422) {
            throw new DocumentIllisible(
                (string) $reponse->json('detail.message', 'Le document est illisible.'),
                (string) $reponse->json('detail.detail', ''),
            );
        }

        return (int) $reponse->json('nb_morceaux');
    }

    public function supprimer(AssistantDocument $document): void
    {
        $this->envoyer(fn () => $this->http(30)
            ->delete('/documents/'.$document->id.'?secteur='.urlencode($document->secteur->slug)));
    }

    /**
     * Pose une question ; $surEvenement reçoit chaque événement du flux
     * (sources, texte, fin ou erreur) dès qu'il arrive.
     *
     * Si $doitArreter est fourni et renvoie true (juste après un événement transmis),
     * la lecture du flux s'arrête, le corps de la réponse est fermé et la méthode sort
     * immédiatement, sans lever d'exception (utilisé par le bouton « Arrêter »).
     *
     * @param  list<array{role: string, content: string}>  $historique
     * @param  callable(array): void  $surEvenement
     * @param  list<int>  $documentsPrioritaires  identifiants de documents à privilégier dans la recherche
     * @param  ?callable(): bool  $doitArreter
     */
    public function poserQuestion(
        Secteur $secteur,
        string $question,
        array $historique,
        callable $surEvenement,
        array $documentsPrioritaires = [],
        ?int $conversationId = null,
        ?callable $doitArreter = null,
    ): void {
        $corpsRequete = [
            'secteur' => $secteur->slug,
            'nom_secteur' => $secteur->nom,
            'question' => $question,
            'historique' => $historique,
        ];
        if ($documentsPrioritaires !== []) {
            $corpsRequete['documents_prioritaires'] = $documentsPrioritaires;
        }
        if ($conversationId !== null) {
            $corpsRequete['conversation'] = $conversationId;
        }

        $reponse = $this->envoyer(fn () => $this->http(config('assistant.timeout'))
            ->withOptions(['stream' => true])
            ->post('/questions', $corpsRequete));

        $this->lireFlux($reponse, $surEvenement, $doitArreter);
    }

    /** Indexe une pièce jointe déposée dans une conversation ; retourne le nombre de morceaux créés. */
    public function indexerPieceJointe(AssistantPieceJointe $piece): int
    {
        $reponse = $this->envoyer(fn () => $this->http(config('assistant.timeout_indexation'))->post('/pieces-jointes', [
            'conversation_id' => $piece->conversation_id,
            'piece_id' => $piece->id,
            'chemin' => $piece->cheminAbsolu(),
            'nom' => $piece->nom,
        ]), accepter422: true);

        if ($reponse->status() === 422) {
            throw new DocumentIllisible(
                (string) $reponse->json('detail.message', 'Le document est illisible.'),
                (string) $reponse->json('detail.detail', ''),
            );
        }

        return (int) $reponse->json('nb_morceaux');
    }

    /** Supprime les vecteurs d'UNE SEULE pièce jointe, sans toucher aux autres pièces de la conversation. */
    public function supprimerPieceJointe(AssistantPieceJointe $piece): void
    {
        $this->envoyer(fn () => $this->http(30)
            ->delete('/pieces-jointes/'.$piece->id.'?conversation_id='.$piece->conversation_id));
    }

    /** Supprime les vecteurs de tous les documents (et pièces jointes) d'une conversation. */
    public function supprimerConversation(int $conversationId): void
    {
        $this->envoyer(fn () => $this->http(30)->delete('/conversations/'.$conversationId));
    }
}
