<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\UploadedFile;
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

    /** Taille maximale d'un fichier de référence déposé depuis le site (en octets). */
    public const TAILLE_MAX = 20 * 1024 * 1024;

    /**
     * État des fichiers de référence attendus par un agent : nom imposé, présence, taille, date de dépôt.
     * Jamais le contenu.
     *
     * @return list<array{nom: string, present: bool, taille: ?int, date: ?\Illuminate\Support\Carbon}>
     */
    public function etatReferences(string $slug): array
    {
        $etat = [];
        foreach ($this->trouver($slug)['references'] ?? [] as $nom) {
            $chemin = $this->dossierReferences($slug).'/'.basename($nom);
            $present = Storage::disk('local')->exists($chemin);
            $etat[] = [
                'nom' => basename($nom),
                'present' => $present,
                'taille' => $present ? Storage::disk('local')->size($chemin) : null,
                'date' => $present ? \Illuminate\Support\Carbon::createFromTimestamp(Storage::disk('local')->lastModified($chemin)) : null,
            ];
        }

        return $etat;
    }

    /**
     * Dépose (ou remplace) un fichier de référence attendu par un agent, sous le nom EXACT imposé par la définition.
     * Seuls .docx et .xlsx (des archives zip) sont acceptés.
     *
     * @throws AssignationImpossible
     */
    public function deposerReference(string $slug, string $nom, UploadedFile $fichier): void
    {
        $definition = $this->trouver($slug);
        if ($definition === null || ! in_array($nom, array_map('basename', $definition['references']), true)) {
            throw new AssignationImpossible('Ce fichier n\'est pas attendu par cet agent.');
        }
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if (! in_array($extension, ['docx', 'xlsx'], true)) {
            throw new AssignationImpossible('Seuls les fichiers Word (.docx) et Excel (.xlsx) peuvent être déposés ici.');
        }
        if (strtolower($fichier->getClientOriginalExtension()) !== $extension) {
            throw new AssignationImpossible("Le fichier attendu est un .{$extension} : celui que vous avez choisi n'en est pas un.");
        }
        if ($fichier->getSize() > self::TAILLE_MAX) {
            throw new AssignationImpossible('Ce fichier est trop volumineux (20 Mo au plus).');
        }
        $debut = (string) file_get_contents($fichier->getRealPath(), false, null, 0, 4);
        if ($debut !== "PK\x03\x04") {
            throw new AssignationImpossible("Ce fichier n'est pas un vrai .{$extension} (il ne s'ouvre pas comme un document Office). Réenregistrez-le depuis Word ou Excel.");
        }
        Storage::disk('local')->putFileAs($this->dossierReferences($slug), $fichier, basename($nom));
    }
}
