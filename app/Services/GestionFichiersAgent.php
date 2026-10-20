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
                'Le fichier « '.$nom.' » n\'est pas accepté. Déposez un classeur ou un document Word : '
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
        if ($this->nomPris($agent, $nom)) {
            throw new FichierAgentRefuse('Un fichier nommé « '.$nom.' » existe déjà pour cet agent. Supprimez-le d\'abord ou renommez le nouveau.');
        }
        $chemin = $fichier->storeAs($agent->dossier().'/references', $nom, 'local');

        return $agent->tousFichiers()->create([
            'type' => AgentFichierReference::REFERENCE, 'nom' => $nom, 'chemin' => $chemin,
            'taille' => $fichier->getSize(), 'role' => $role,
        ]);
    }

    /**
     * Dépose un document en attente du prochain lancement. Redéposer un document du même nom le REMPLACE
     * (l'utilisateur a corrigé son fichier) ; un nom déjà pris par une référence est refusé.
     */
    public function deposerApport(Agent $agent, UploadedFile $fichier): AgentFichierReference
    {
        $nom = $this->valider($fichier);
        $ancien = $agent->apports()->whereRaw('lower(nom) = ?', [strtolower($nom)])->first();
        if ($ancien === null && $this->nomPris($agent, $nom)) {
            throw new FichierAgentRefuse('Le nom « '.$nom.' » est déjà pris par un fichier de référence de cet agent. Renommez le document puis déposez-le de nouveau.');
        }
        if ($ancien !== null) {
            $this->supprimerFichier($ancien);
        }
        $chemin = $fichier->storeAs($agent->dossier().'/apports', $nom, 'local');

        return $agent->tousFichiers()->create([
            'type' => AgentFichierReference::APPORT, 'nom' => $nom, 'chemin' => $chemin, 'taille' => $fichier->getSize(),
        ]);
    }

    private function nomPris(Agent $agent, string $nom): bool
    {
        return $agent->tousFichiers()->whereRaw('lower(nom) = ?', [strtolower($nom)])->exists();
    }

    public function supprimerReference(AgentFichierReference $reference): void
    {
        $this->supprimerFichier($reference);
    }

    public function supprimerFichier(AgentFichierReference $fichier): void
    {
        Storage::disk('local')->delete($fichier->chemin);
        $fichier->delete();
    }

    /**
     * Confirmation de lecture des documents en attente : le service lit la STRUCTURE (feuilles, lignes, colonnes),
     * jamais les valeurs, et le résultat est gardé sur chaque document. Un service indisponible laisse la lecture vide.
     */
    public function lireApports(Agent $agent, AgentClient $client): void
    {
        $apports = $agent->apports()->get();
        if ($apports->isEmpty()) {
            return;
        }
        $fiches = collect($client->lireFichiers(Storage::disk('local')->path($agent->dossier().'/apports'), $apports->pluck('nom')->all()))
            ->keyBy('nom');
        foreach ($apports as $apport) {
            $apport->update(['lecture' => $fiches->get($apport->nom)]);
        }
    }

    /**
     * Crée l'exécution et son dossier d'entrée : copie des références puis des documents en attente.
     * Les originaux ne sont jamais touchés (l'agent lit une copie).
     */
    public function preparerExecution(Agent $agent, User $user, string $mode, string $demande): AgentExecution
    {
        $execution = $agent->executions()->create([
            'user_id' => $user->id,
            'mode' => $mode,
            'etat' => AgentExecution::EN_COURS,
            'demande' => $demande,
            'fichiers_apportes' => $agent->apports()->pluck('nom')->all(),
            'debut_at' => now(),
        ]);

        $disque = Storage::disk('local');
        $disque->makeDirectory($execution->dossierEntree());
        foreach ($agent->tousFichiers()->get() as $fichier) {
            $disque->copy($fichier->chemin, $execution->dossierEntree().'/'.$fichier->nom);
        }

        return $execution;
    }

    /** Les documents en attente ont servi à un lancement réussi : on les retire (la copie reste dans l'exécution). */
    public function consommerApports(Agent $agent): void
    {
        foreach ($agent->apports()->get() as $apport) {
            $this->supprimerFichier($apport);
        }
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
