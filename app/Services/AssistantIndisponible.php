<?php

namespace App\Services;

use RuntimeException;

/** Le service de l'assistant (ou un modèle derrière lui) ne répond pas. */
class AssistantIndisponible extends RuntimeException
{
    public function __construct(string $message = AssistantClient::INDISPONIBLE, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}
