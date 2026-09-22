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

        $terminee = false;
        $surEvenementSuivi = function (array $evenement) use ($surEvenement, &$terminee) {
            if (in_array($evenement['type'] ?? null, ['fin', 'erreur'], true)) {
                $terminee = true;
            }
            $surEvenement($evenement);
        };

        try {
            $corps = $reponse->toPsrResponse()->getBody();
            $tampon = '';
            while (! $corps->eof()) {
                $tampon .= $corps->read(1024);
                while (($fin = strpos($tampon, "\n")) !== false) {
                    $this->transmettre(substr($tampon, 0, $fin), $surEvenementSuivi);
                    $tampon = substr($tampon, $fin + 1);
                    if ($doitArreter !== null && $doitArreter()) {
                        $corps->close();

                        return;
                    }
                }
            }
            $this->transmettre($tampon, $surEvenementSuivi);
            if ($doitArreter !== null && $doitArreter()) {
                $corps->close();

                return;
            }
        } catch (Throwable $e) {
            throw new AssistantIndisponible(self::INDISPONIBLE, 'Flux interrompu : '.$e->getMessage());
        }

        if (! $terminee) {
            throw new AssistantIndisponible(self::INDISPONIBLE, 'Flux interrompu : fin de réponse manquante');
        }
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

    /** Supprime les vecteurs de tous les documents (et pièces jointes) d'une conversation. */
    public function supprimerConversation(int $conversationId): void
    {
        $this->envoyer(fn () => $this->http(30)->delete('/conversations/'.$conversationId));
    }

    private function transmettre(string $ligne, callable $surEvenement): void
    {
        $ligne = trim($ligne);
        if ($ligne !== '' && is_array($evenement = json_decode($ligne, true))) {
            $surEvenement($evenement);
        }
    }

    private function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(config('assistant.url'))
            ->withHeaders(['X-Assistant-Jeton' => (string) config('assistant.jeton')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    /** @param  callable(): Response  $requete */
    private function envoyer(callable $requete, bool $accepter422 = false): Response
    {
        try {
            $reponse = $requete();
        } catch (ConnectionException $e) {
            throw new AssistantIndisponible(self::INDISPONIBLE, $e->getMessage());
        }

        if ($reponse->successful() || ($accepter422 && $reponse->status() === 422)) {
            return $reponse;
        }

        throw new AssistantIndisponible(
            self::INDISPONIBLE,
            'HTTP '.$reponse->status().' : '.mb_substr((string) ($reponse->json('detail.detail') ?? $reponse->body()), 0, 500),
        );
    }
}
