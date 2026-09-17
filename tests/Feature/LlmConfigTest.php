<?php

/**
 * config/llm.php lit llm.env (racine du projet) : réglages du LLM partagés entre
 * l'envoi des HIL (pipeline bmn/) et l'assistant IA. Le .env de Laravel n'est pas utilisé.
 */
function chargerConfigLlm(string $contenu): array
{
    $fichier = storage_path('app/llm_test_' . uniqid() . '.env');
    file_put_contents($fichier, $contenu);
    putenv("LLM_ENV={$fichier}");
    try {
        return require config_path('llm.php');
    } finally {
        putenv('LLM_ENV');
        @unlink($fichier);
    }
}

it('reads the shared llm settings from llm.env', function () {
    $conf = chargerConfigLlm(
        "# commentaire\nLLM_ACTIF=true\nLLM_BASE_URL=https://llm.chutes.ai/v1\n"
        . "LLM_MODELE=unsloth/Mistral-Nemo-Instruct-2407-TEE\nLLM_CLE_API=\"sk-test\"\nLLM_TIMEOUT=30\nLLM_TEMPERATURE=0.2\n"
    );

    expect($conf)->toBe([
        'actif' => true,
        'base_url' => 'https://llm.chutes.ai/v1',
        'modele' => 'unsloth/Mistral-Nemo-Instruct-2407-TEE',
        'cle_api' => 'sk-test',
        'timeout' => 30,
        'temperature' => 0.2,
    ]);
});

it('falls back to safe defaults when llm.env is missing', function () {
    putenv('LLM_ENV=' . storage_path('app/absent_' . uniqid() . '.env'));
    try {
        $conf = require config_path('llm.php');
    } finally {
        putenv('LLM_ENV');
    }

    expect($conf['actif'])->toBeFalse()
        ->and($conf['cle_api'])->toBe('')
        ->and($conf['timeout'])->toBe(120);
});

it('keeps llm.env out of git', function () {
    exec('git -C ' . escapeshellarg(base_path()) . ' check-ignore -q llm.env', $sortie, $code);
    expect($code)->toBe(0);
});
