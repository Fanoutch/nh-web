<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentFichierReference;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Stockage privé des fichiers d'un agent : références, dossier de chaque exécution, taille totale.
 * Aucune suppression automatique tant que l'agent est actif ; seule la mise en veille vide les fichiers de travail.
 */
class GestionFichiersAgent
{
    /** Contrôle l'extension et la taille ; renvoie le nom de fichier à garder. */
    public function valider(UploadedFile $fichier): string
    {
        $nom = basename(str_replace('\\', '/', $fichier->getClientOriginalName()));
        $extension = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        if ($nom === '' || str_starts_with($nom, '.') || ! in_array($extension, config('agents.extensions'), true)) {
            throw new FichierAgentRefuse(
                'Le fichier « '.$nom.' » n\'est pas accepté. Déposez un classeur '
                .implode(', ', array_map(fn ($e) => '.'.$e, config('agents.extensions'))).'.'
            );
        }
        $maxMo = (int) config('agents.taille_max_mo');
        if ($fichier->getSize() > $maxMo * 1024 * 1024) {
            throw new FichierAgentRefuse('Le fichier « '.$nom.' » dépasse '.$maxMo.' Mo. Réduisez-le puis déposez-le de nouveau.');
        }

        return $nom;
    }

    public function deposerReference(Agent $agent, UploadedFile $fichier, ?string $role = null): AgentFichierReference
    {
        $nom = $this->valider($fichier);
        if ($agent->fichiersReference()->where('nom', $nom)->exists()) {
            throw new FichierAgentRefuse('Un fichier de référence nommé « '.$nom.' » existe déjà pour cet agent. Supprimez-le d\'abord ou renommez le nouveau.');
        }
        $chemin = $fichier->storeAs($agent->dossier().'/references', $nom, 'local');

        return $agent->fichiersReference()->create([
            'nom' => $nom, 'chemin' => $chemin, 'taille' => $fichier->getSize(), 'role' => $role,
        ]);
    }

    public function supprimerReference(AgentFichierReference $reference): void
    {
        Storage::disk('local')->delete($reference->chemin);
        $reference->delete();
    }

    /**
     * Crée l'exécution et son dossier d'entrée : copie des références, puis dépôt des fichiers apportés.
     * Les originaux ne sont jamais touchés (l'agent lit une copie).
     *
     * @param  list<UploadedFile>  $apports
     */
    public function preparerExecution(Agent $agent, User $user, string $mode, string $demande, array $apports = []): AgentExecution
    {
        $noms = [];
        foreach ($apports as $apport) {
            $nom = $this->valider($apport);
            if (in_array(strtolower($nom), array_map('strtolower', $noms), true)
                || $agent->fichiersReference()->whereRaw('lower(nom) = ?', [strtolower($nom)])->exists()) {
                throw new FichierAgentRefuse('Le nom « '.$nom.' » est déjà pris par un autre fichier de cet agent. Renommez le fichier puis déposez-le de nouveau.');
            }
            $noms[] = $nom;
        }

        $execution = $agent->executions()->create([
            'user_id' => $user->id,
            'mode' => $mode,
            'etat' => AgentExecution::EN_COURS,
            'demande' => $demande,
            'fichiers_apportes' => $noms,
            'debut_at' => now(),
        ]);

        $disque = Storage::disk('local');
        $disque->makeDirectory($execution->dossierEntree());
        foreach ($agent->fichiersReference as $reference) {
            $disque->copy($reference->chemin, $execution->dossierEntree().'/'.$reference->nom);
        }
        foreach ($apports as $i => $apport) {
            $apport->storeAs($execution->dossierEntree(), $noms[$i], 'local');
        }

        return $execution;
    }

    /** Taille de tout ce que l'agent garde sur le disque, en octets. */
    public function tailleOctets(Agent $agent): int
    {
        $disque = Storage::disk('local');

        return (int) collect($disque->allFiles($agent->dossier()))->sum(fn ($f) => $disque->size($f));
    }

    /** Vrai à partir de 500 Mo : on prévient l'utilisateur, on ne supprime rien. */
    public function alerteTaille(Agent $agent): bool
    {
        return $this->tailleOctets($agent) >= (int) config('agents.alerte_mo') * 1024 * 1024;
    }

    /** Mise en veille : supprime les fichiers de travail (exécutions) ; références et historique sont gardés. */
    public function viderFichiersDeTravail(Agent $agent): void
    {
        Storage::disk('local')->deleteDirectory($agent->dossier().'/executions');
    }
}
