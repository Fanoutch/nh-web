<?php

namespace App\Services;

use RuntimeException;

/** Un fichier déposé pour un agent est refusé ; le message est lisible tel quel par l'utilisateur. */
class FichierAgentRefuse extends RuntimeException
{
}
