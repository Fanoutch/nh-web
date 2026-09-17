<?php

/*
|--------------------------------------------------------------------------
| Réglages du LLM (partagés)
|--------------------------------------------------------------------------
|
| Lus dans llm.env, à la racine du projet — PAS dans le .env de Laravel — pour
| que l'envoi des HIL (pipeline bmn/, qui lit le même fichier) et l'assistant
| IA utilisent une seule et même configuration. Modèle : llm.env.example.
|
| Priorité : variable d'environnement du même nom > llm.env > valeur par défaut.
| LLM_ENV peut désigner un autre fichier.
|
*/

$fichier = getenv('LLM_ENV') ?: base_path('llm.env');

$valeurs = [];
if (is_file($fichier)) {
    foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || str_starts_with($ligne, '#') || !str_contains($ligne, '=')) {
            continue;
        }
        [$cle, $valeur] = explode('=', $ligne, 2);
        $valeur = explode(' #', $valeur, 2)[0];              // commentaire en fin de ligne
        $valeurs[trim($cle)] = trim(trim($valeur), "\"'");
    }
}

$lire = function (string $cle, $defaut = null) use ($valeurs) {
    $env = getenv($cle);
    if ($env !== false && $env !== '') {
        return $env;
    }
    return ($valeurs[$cle] ?? '') !== '' ? $valeurs[$cle] : $defaut;
};

return [
    'actif' => filter_var($lire('LLM_ACTIF', false), FILTER_VALIDATE_BOOLEAN),
    'base_url' => $lire('LLM_BASE_URL', 'https://api.openai.com/v1'),
    'modele' => $lire('LLM_MODELE', ''),
    'cle_api' => $lire('LLM_CLE_API', ''),
    'timeout' => (int) $lire('LLM_TIMEOUT', 120),
    'temperature' => (float) $lire('LLM_TEMPERATURE', 0),
];
