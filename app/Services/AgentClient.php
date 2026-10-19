<?php

namespace App\Services;

use App\Models\AgentExecution;
use Illuminate\Support\Facades\Storage;

/**
 * Client des routes /agents/* du service Python nh-assistant.
 * Les droits (AgentPolicy) sont vérifiés AVANT d'appeler ce client.
 */
class AgentClient
{
    use Concerns\ParleAuService;

    /**
     * Lance l'exécution ; $surEvenement reçoit chaque événement (« etape », puis « fin ») dès qu'il arrive.
     * Les fichiers à lire doivent déjà être dans le dossier d'entrée de l'exécution.
     *
     * @param  callable(array): void  $surEvenement
     * @param  ?callable(): bool  $doitArreter
     */
    public function executer(AgentExecution $execution, callable $surEvenement, ?callable $doitArreter = null): void
    {
        $agent = $execution->agent;
        $disque = Storage::disk('local');
        $disque->makeDirectory($execution->dossierSortie());

        $reponse = $this->envoyer(fn () => $this->http(config('agents.timeout'))
            ->withOptions(['stream' => true])
            ->post('/agents/executions', [
                'execution_id' => $execution->id,
                'consignes' => (string) $agent->consignes,
                'demande' => $execution->demande,
                'dossier_execution' => $disque->path($execution->dossierEntree()),
                'fichiers' => array_map('basename', $disque->files($execution->dossierEntree())),
                'dossier_sortie' => $disque->path($execution->dossierSortie()),
                'outils' => $agent->outilsAutorises(),
            ]));

        $this->lireFlux($reponse, $surEvenement, $doitArreter);
    }

    /** Demande l'arrêt d'une exécution en cours (bouton « Arrêter »). */
    public function arreter(AgentExecution $execution): void
    {
        $this->envoyer(fn () => $this->http(10)->post('/agents/executions/'.$execution->id.'/arret'));
    }
}
