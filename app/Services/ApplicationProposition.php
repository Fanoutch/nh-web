<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentRappel;

/**
 * Applique (ou refuse) un changement proposé par l'agent. C'est le SEUL endroit où une proposition devient
 * un enregistrement : le service Python ne touche jamais à la base. Droits vérifiés par l'appelant (AgentPolicy).
 */
class ApplicationProposition
{
    /** Étapes qui ne font que regarder ou gérer : jamais figées dans la recette. */
    private const HORS_RECETTE = ['excel_decrire', 'word_decrire', 'agent_proposer', 'agent_historique'];

    /** @return string phrase de confirmation, affichée dans le chat */
    public function appliquer(AgentExecution $execution): string
    {
        $proposition = $this->enAttente($execution);
        $agent = $execution->agent;

        $phrase = match ($proposition['type'] ?? '') {
            'enregistrer' => $this->enregistrer($agent, $execution, $proposition),
            'consignes' => $this->consignes($agent, $proposition),
            'rappel' => $this->rappel($agent, $proposition),
            default => throw new LancementImpossible('Cette proposition n\'est pas reconnue.'),
        };
        $execution->update(['proposition_etat' => AgentExecution::ACCEPTEE]);

        return $phrase;
    }

    public function refuser(AgentExecution $execution): void
    {
        $this->enAttente($execution);
        $execution->update(['proposition_etat' => AgentExecution::REFUSEE]);
    }

    /** @return array<string, mixed> */
    private function enAttente(AgentExecution $execution): array
    {
        if ($execution->proposition_etat !== AgentExecution::EN_ATTENTE || ! is_array($execution->proposition)) {
            throw new LancementImpossible('Cette proposition a déjà reçu une réponse.');
        }

        return $execution->proposition;
    }

    private function enregistrer(Agent $agent, AgentExecution $courante, array $p): string
    {
        $source = $agent->executions()->reorder()->orderByDesc('id')
            ->where('id', '<=', $courante->id)->where('mode', AgentExecution::CONCEPTION)->get()
            ->first(fn (AgentExecution $e) => ! empty($e->fichiers_produits));
        if ($source === null) {
            throw new LancementImpossible('Il n\'y a encore aucun résultat à enregistrer. Demandez d\'abord à l\'agent de produire un fichier, vérifiez-le, puis enregistrez.');
        }
        $recette = collect($source->etapes ?? [])
            ->filter(fn ($e) => ($e['statut'] ?? '') === 'ok' && ! in_array($e['outil'] ?? '', self::HORS_RECETTE, true))
            ->map(fn ($e) => ['outil' => $e['outil'], 'arguments' => $e['arguments'] ?? [], 'resume' => $e['resume'] ?? ''])
            ->values()->all();

        $agent->update([
            'nom' => mb_substr((string) $p['nom'], 0, 100),
            'description' => ($p['description'] ?? '') !== '' ? $p['description'] : $agent->description,
            'consignes' => ($p['consignes'] ?? '') !== '' ? $p['consignes'] : $agent->consignes,
            'recette' => $recette,
            'etat' => Agent::ACTIF,
        ]);

        return 'C\'est enregistré : l\'agent « '.$agent->nom.' » est prêt. Pour le relancer, déposez de nouveaux documents puis cliquez sur « Lancer l\'agent ».';
    }

    private function consignes(Agent $agent, array $p): string
    {
        $agent->update(['consignes' => $p['consignes']]);

        return 'C\'est enregistré : les nouvelles consignes s\'appliquent dès le prochain lancement.';
    }

    private function rappel(Agent $agent, array $p): string
    {
        if ($agent->etat !== Agent::ACTIF) {
            throw new LancementImpossible('Enregistrez d\'abord l\'agent : un rappel ne se règle que sur un agent enregistré.');
        }
        if (($p['periodicite'] ?? '') === 'aucun') {
            $agent->rappel()?->delete();

            return 'C\'est enregistré : plus aucun rappel pour cet agent.';
        }
        $echeance = match ($p['periodicite'] ?? '') {
            'hebdomadaire' => now()->addWeek(),
            'mensuel' => now()->addMonth(),
            'trimestriel' => now()->addMonths(3),
            default => throw new LancementImpossible('Cette périodicité n\'est pas reconnue.'),
        };
        AgentRappel::updateOrCreate(['agent_id' => $agent->id], [
            'periodicite' => $p['periodicite'], 'prochaine_echeance' => $echeance->toDateString(), 'notifie_at' => null,
        ]);

        return 'C\'est enregistré : rappel '.$p['periodicite'].', prochaine échéance le '.$echeance->format('d/m/Y').'.';
    }
}
