<?php

namespace App\Services;

use RuntimeException;

/** Un lancement ou une proposition ne peut pas être appliqué ; le message est lisible tel quel par l'utilisateur. */
class LancementImpossible extends RuntimeException
{
}
