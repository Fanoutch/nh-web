<?php

namespace App\Support;

/**
 * Lit un fichier de réglages « CLE=valeur » (llm.env, assistant/reglages.env) :
 * lignes # ignorées, guillemets et « # commentaire » de fin de ligne retirés.
 */
final class FichierEnv
{
    /** @return array<string, string> */
    public static function lire(string $chemin): array
    {
        if (! is_file($chemin)) {
            return [];
        }

        $valeurs = [];
        foreach (file($chemin, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
            $ligne = trim($ligne);
            if ($ligne === '' || str_starts_with($ligne, '#') || ! str_contains($ligne, '=')) {
                continue;
            }
            [$cle, $valeur] = explode('=', $ligne, 2);
            $valeur = explode(' #', $valeur, 2)[0];
            $valeurs[trim($cle)] = trim(trim($valeur), "\"'");
        }

        return $valeurs;
    }
}
