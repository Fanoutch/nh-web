<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentFichierReference;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Assigner un agent du catalogue = en donner une COPIE à la personne : ses fichiers et ses exécutions restent à elle.
 * La copie garde le lien avec le modèle (slug + empreinte) pour pouvoir recevoir les corrections.
 */
class AssignationAgents
{
    public function __construct(private CatalogueAgents $catalogue)
    {
    }

    /** Donne l'agent à la personne. Déjà donné : la copie existante est mise à jour (ou réactivée si elle était retirée). */
    public function assigner(string $slug, User $user): Agent
    {
        $definition = $this->catalogue->trouver($slug)
            ?? throw new AssignationImpossible('Cet agent n\'existe plus dans le catalogue.');
        $manquantes = $this->catalogue->referencesManquantes($slug);
        if ($manquantes !== []) {
            throw new AssignationImpossible('Il manque sur le serveur '.(count($manquantes) > 1 ? 'les fichiers' : 'le fichier').' de référence : '
                .implode(', ', $manquantes).'. Déposez-'.(count($manquantes) > 1 ? 'les' : 'le').' dans storage/app/private/'
                .$this->catalogue->dossierReferences($slug).'/ puis recommencez.');
        }

        $agent = Agent::where('modele_slug', $slug)->where('user_id', $user->id)->first();
        $agent ??= new Agent(['user_id' => $user->id, 'modele_slug' => $slug, 'nom' => $definition['nom']]);
        $agent->retire_at = null;
        $agent->etat = Agent::ACTIF;
        $agent->save();
        $this->appliquer($agent, $definition);

        return $agent;
    }

    /**
     * @return array{assignes: list<string>, deja: list<string>, inconnus: list<string>}
     */
    public function assignerParEmails(string $slug, string $saisie): array
    {
        $adresses = array_values(array_unique(array_filter(array_map(
            fn ($a) => strtolower(trim($a)), preg_split('/[,;\s]+/', $saisie) ?: []))));
        if ($adresses === []) {
            throw new AssignationImpossible('Saisissez au moins une adresse e-mail.');
        }
        $resultat = ['assignes' => [], 'deja' => [], 'inconnus' => []];
        foreach ($adresses as $adresse) {
            $user = User::whereRaw('lower(email) = ?', [$adresse])->first();
            if ($user === null) {
                $resultat['inconnus'][] = $adresse;

                continue;
            }
            $existait = Agent::where('modele_slug', $slug)->where('user_id', $user->id)->whereNull('retire_at')->exists();
            $this->assigner($slug, $user);
            $resultat[$existait ? 'deja' : 'assignes'][] = $user->name;
        }

        return $resultat;
    }

    /** Masque la copie ; l'historique et les fichiers restent. */
    public function retirer(Agent $agent): void
    {
        if ($agent->estDuCatalogue()) {
            $agent->update(['retire_at' => now()]);
        }
    }

    /** @return int nombre de copies mises à jour */
    public function mettreAJourCopies(string $slug): int
    {
        $definition = $this->catalogue->trouver($slug)
            ?? throw new AssignationImpossible('Cet agent n\'existe plus dans le catalogue.');
        if ($this->catalogue->referencesManquantes($slug) !== []) {
            throw new AssignationImpossible('Il manque des fichiers de référence sur le serveur : la mise à jour est annulée.');
        }
        $n = 0;
        foreach ($this->catalogue->copies($slug)->get() as $agent) {
            $this->appliquer($agent, $definition);
            $n++;
        }

        return $n;
    }

    /** Recopie la définition et les références. Les documents déposés par l'utilisateur et l'historique ne sont pas touchés. */
    private function appliquer(Agent $agent, array $definition): void
    {
        $agent->update([
            'nom' => $definition['nom'], 'description' => $definition['description'] ?: null,
            'consignes' => $definition['consignes'] ?: null, 'outils' => $definition['outils'],
            'recette' => $definition['recette'], 'format_sortie' => $definition['format_sortie'],
            'modele_version' => $this->catalogue->version($definition['slug']),
        ]);

        $disque = Storage::disk('local');
        $attendus = array_map('basename', $definition['references']);
        foreach ($agent->fichiersReference()->get() as $ancien) {            // une référence retirée du modèle disparaît de la copie
            if (! in_array($ancien->nom, $attendus, true)) {
                $disque->delete($ancien->chemin);
                $ancien->delete();
            }
        }
        foreach ($attendus as $nom) {
            $source = $this->catalogue->dossierReferences($definition['slug']).'/'.$nom;
            $chemin = $agent->dossier().'/references/'.$nom;
            $disque->makeDirectory($agent->dossier().'/references');
            $disque->put($chemin, $disque->get($source));
            $agent->tousFichiers()->updateOrCreate(
                ['nom' => $nom],
                ['type' => AgentFichierReference::REFERENCE, 'chemin' => $chemin, 'taille' => (int) $disque->size($chemin)],
            );
        }
    }
}
