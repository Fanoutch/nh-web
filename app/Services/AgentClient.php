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
     * @param  array{consignes?: string, historique?: list<array{role: string, content: string}>, donnees_agent?: array}  $options
     */
    public function executer(AgentExecution $execution, callable $surEvenement, ?callable $doitArreter = null, array $options = []): void
    {
        $agent = $execution->agent;
        $disque = Storage::disk('local');
        $disque->makeDirectory($execution->dossierSortie());

        $reponse = $this->envoyer(fn () => $this->http(config('agents.timeout'))
            ->withOptions(['stream' => true])
            ->post('/agents/executions', [
                'execution_id' => $execution->id,
                'consignes' => (string) ($options['consignes'] ?? $agent->consignes),
                'historique' => $options['historique'] ?? [],
                'donnees_agent' => (object) ($options['donnees_agent'] ?? []),
                'demande' => $execution->demande,
                'dossier_execution' => $disque->path($execution->dossierEntree()),
                'fichiers' => array_map('basename', $disque->files($execution->dossierEntree())),
                'dossier_sortie' => $disque->path($execution->dossierSortie()),
                'outils' => $agent->outilsAutorises(),
            ]));

        $this->lireFlux($reponse, $surEvenement, $doitArreter);
    }

    /**
     * Confirmation de lecture : structure de chaque fichier d'un dossier (feuilles, lignes, colonnes ; jamais les valeurs).
     *
     * @param  list<string>  $noms
     * @return list<array<string, mixed>>
     */
    public function lireFichiers(string $dossierAbsolu, array $noms): array
    {
        $reponse = $this->envoyer(fn () => $this->http(60)->post('/agents/fichiers/lire', [
            'dossier' => $dossierAbsolu, 'fichiers' => $noms,
        ]));

        return $reponse->json('fichiers', []);
    }

    /** Demande l'arrêt d'une exécution en cours (bouton « Arrêter »). */
    public function arreter(AgentExecution $execution): void
    {
        $this->envoyer(fn () => $this->http(10)->post('/agents/executions/'.$execution->id.'/arret'));
    }
}
