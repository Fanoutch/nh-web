<?php

namespace App\Services;

use RuntimeException;

/** Le document ne peut pas être lu (scan sans texte, format, fichier endommagé). Message affichable. */
class DocumentIllisible extends RuntimeException
{
    public function __construct(string $message, public readonly string $detail = '')
    {
        parent::__construct($message);
    }
}
