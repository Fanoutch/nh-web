<?php

use App\Support\FichierEnv;

/*
|--------------------------------------------------------------------------
| Assistant IA — service Python nh-assistant
|--------------------------------------------------------------------------
|
| Lu dans assistant/reglages.env (partagé avec le service Python) — modèle :
| assistant/reglages.env.example. Priorité : variable d'environnement > fichier
| > défaut. ASSISTANT_REGLAGES désigne un autre fichier ; « aucun » l'ignore
| (tests). Les connexions aux modèles sont dans llm.env (lues par le service).
|
*/

$fichier = getenv('ASSISTANT_REGLAGES') ?: base_path('assistant/reglages.env');
$valeurs = FichierEnv::lire($fichier);

$lire = function (string $cle, $defaut = null) use ($valeurs) {
    $env = getenv($cle);
    if ($env !== false && $env !== '') {
        return $env;
    }

    return ($valeurs[$cle] ?? '') !== '' ? $valeurs[$cle] : $defaut;
};

return [
    'url' => 'http://'.$lire('ASSISTANT_HOTE', '127.0.0.1').':'.$lire('ASSISTANT_PORT', '8765'),
    'jeton' => (string) $lire('ASSISTANT_JETON', ''),
    'timeout' => 180,             // une question (réponse en flux comprise), en secondes
    'timeout_indexation' => 600,  // un document de 50 Mo
    'taille_max_mo' => (int) $lire('ASSISTANT_TAILLE_MAX_MO', 50),
    'historique_tours' => (int) $lire('ASSISTANT_HISTORIQUE_TOURS', 3),
];
