<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Storage;

/**
 * Catalogue des agents développés par le super admin : un fichier `<slug>/agent.php` par agent (versionné dans git),
 * qui renvoie nom, description, consignes, outils, recette, format_sortie et la liste des fichiers de référence attendus.
 * Les fichiers de référence réels (ex. un formulaire du service) ne vont JAMAIS dans git : ils sont déposés sur le
 * serveur, dans `storage/app/private/<catalogue_references>/<slug>/references/`.
 */
class CatalogueAgents
{
    /** @return array<string, array> définitions indexées par slug, triées par nom */
    public function tous(): array
    {
        $liste = [];
        foreach (glob(rtrim((string) config('agents.catalogue_definitions'), '/').'/*/agent.php') ?: [] as $fichier) {
            $slug = basename(dirname($fichier));
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                continue;
            }
            $definition = require $fichier;
            if (! is_array($definition) || trim((string) ($definition['nom'] ?? '')) === '') {
                continue;
            }
            $liste[$slug] = [
                'slug' => $slug,
                'nom' => (string) $definition['nom'],
                'description' => (string) ($definition['description'] ?? ''),
                'consignes' => (string) ($definition['consignes'] ?? ''),
                'outils' => $definition['outils'] ?? null,
                'recette' => $definition['recette'] ?? null,
                'format_sortie' => $definition['format_sortie'] ?? null,
                'references' => array_values((array) ($definition['references'] ?? [])),
            ];
        }
        uasort($liste, fn ($a, $b) => strcasecmp($a['nom'], $b['nom']));

        return $liste;
    }

    public function trouver(string $slug): ?array
    {
        return $this->tous()[$slug] ?? null;
    }

    public function dossierReferences(string $slug): string
    {
        return trim((string) config('agents.catalogue_references'), '/').'/'.$slug.'/references';
    }

    /** @return list<string> fichiers de référence attendus mais absents du serveur */
    public function referencesManquantes(string $slug): array
    {
        $definition = $this->trouver($slug);

        return array_values(array_filter($definition['references'] ?? [],
            fn ($nom) => ! Storage::disk('local')->exists($this->dossierReferences($slug).'/'.basename($nom))));
    }

    /** Empreinte de ce que contient une copie : change dès que la définition ou un fichier de référence change. */
    public function version(string $slug): string
    {
        $definition = $this->trouver($slug) ?? [];
        $empreinte = [$definition];
        foreach ($definition['references'] ?? [] as $nom) {
            $chemin = $this->dossierReferences($slug).'/'.basename($nom);
            $empreinte[] = Storage::disk('local')->exists($chemin) ? sha1_file(Storage::disk('local')->path($chemin)) : null;
        }

        return sha1(json_encode($empreinte));
    }

    /** Copies non retirées d'un agent du catalogue. */
    public function copies(string $slug)
    {
        return Agent::where('modele_slug', $slug)->whereNull('retire_at');
    }

    public function nombreAMettreAJour(string $slug): int
    {
        return $this->copies($slug)->where(fn ($q) => $q->whereNull('modele_version')->orWhere('modele_version', '!=', $this->version($slug)))->count();
    }
}
