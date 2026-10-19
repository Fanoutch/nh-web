<?php

namespace App\Services\Concerns;

use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Appels HTTP au service Python nh-assistant (127.0.0.1, jeton X-Assistant-Jeton), communs à l'assistant et aux agents.
 */
trait ParleAuService
{
    /**
     * Lit un flux NDJSON jusqu'à son événement final (« fin » ou « erreur »).
     * Si $doitArreter renvoie true après un événement, le corps est fermé et la lecture s'arrête sans erreur.
     *
     * @param  callable(array): void  $surEvenement
     * @param  ?callable(): bool  $doitArreter
     */
    protected function lireFlux(Response $reponse, callable $surEvenement, ?callable $doitArreter = null): void
    {
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
            throw new AssistantIndisponible(AssistantClient::INDISPONIBLE, 'Flux interrompu : '.$e->getMessage());
        }

        if (! $terminee) {
            throw new AssistantIndisponible(AssistantClient::INDISPONIBLE, 'Flux interrompu : fin de réponse manquante');
        }
    }

    protected function transmettre(string $ligne, callable $surEvenement): void
    {
        $ligne = trim($ligne);
        if ($ligne !== '' && is_array($evenement = json_decode($ligne, true))) {
            $surEvenement($evenement);
        }
    }

    protected function http(int $timeout): PendingRequest
    {
        return Http::baseUrl(config('assistant.url'))
            ->withHeaders(['X-Assistant-Jeton' => (string) config('assistant.jeton')])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    /** @param  callable(): Response  $requete */
    protected function envoyer(callable $requete, bool $accepter422 = false): Response
    {
        try {
            $reponse = $requete();
        } catch (ConnectionException $e) {
            throw new AssistantIndisponible(AssistantClient::INDISPONIBLE, $e->getMessage());
        }

        if ($reponse->successful() || ($accepter422 && $reponse->status() === 422)) {
            return $reponse;
        }

        throw new AssistantIndisponible(
            AssistantClient::INDISPONIBLE,
            'HTTP '.$reponse->status().' : '.mb_substr((string) ($reponse->json('detail.detail') ?? $reponse->body()), 0, 500),
        );
    }
}
