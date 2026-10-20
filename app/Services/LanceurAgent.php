<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentRappel;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Un tour de chat avec un agent : prépare le dossier, interroge le service, enregistre les étapes et le résultat.
 * Les droits (AgentPolicy) sont vérifiés AVANT d'appeler ce service.
 */
class LanceurAgent
{
    public const DEMANDE_RECETTE = 'Exécutez la recette validée sur les documents disponibles.';

    public function __construct(
        private AgentClient $client,
        private GestionFichiersAgent $fichiers,
    ) {
    }

    /**
     * @param  bool  $rejouerRecette  vrai pour le bouton « Lancer l'agent » : mode exécution, seul usage qui compte pour la veille
     * @param  callable(array): void  $surEvenement  reçoit chaque événement du service (« etape », « fin »)
     * @param  ?callable(): bool  $doitArreter
     */
    public function lancer(Agent $agent, User $user, string $demande, bool $rejouerRecette, callable $surEvenement, ?callable $doitArreter = null): AgentExecution
    {
        if ($rejouerRecette) {
            if ($agent->etat !== Agent::ACTIF || empty($agent->recette)) {
                throw new LancementImpossible('Cet agent n\'a pas encore de recette enregistrée. Mettez-le au point dans le chat, puis demandez-lui de l\'enregistrer.');
            }
            if ($agent->apports()->doesntExist()) {
                throw new LancementImpossible('Déposez d\'abord les documents du lancement dans le panneau de droite.');
            }
        }

        $execution = $this->fichiers->preparerExecution(
            $agent, $user, $rejouerRecette ? AgentExecution::EXECUTION : AgentExecution::CONCEPTION,
            $demande !== '' ? $demande : self::DEMANDE_RECETTE,
        );

        $consignes = (string) $agent->consignes;
        if ($rejouerRecette) {
            $consignes .= "\n\nRECETTE VALIDÉE à rejouer dans l'ordre, sans improviser. Adaptez seulement les noms de fichiers aux "
                ."documents disponibles ; si une colonne ou une feuille attendue est absente, arrêtez-vous et dites-le :\n"
                .$agent->recetteEnTexte();
        }

        $etapes = [];
        $fin = null;
        $arretDemande = false;
        $arret = function () use ($doitArreter, &$arretDemande) {
            if ($doitArreter !== null && $doitArreter()) {
                $arretDemande = true;

                return true;
            }

            return false;
        };

        try {
            $this->client->executer($execution, function (array $evenement) use ($execution, &$etapes, &$fin, $surEvenement) {
                if (($evenement['type'] ?? null) === 'etape') {
                    $etapes[] = [
                        'outil' => $evenement['outil'] ?? '?', 'arguments' => $evenement['arguments'] ?? [],
                        'statut' => $evenement['statut'] ?? '', 'resume' => $evenement['resume'] ?? '',
                    ];
                    if (isset($evenement['proposition'])) {
                        $execution->proposition = $evenement['proposition'];
                        $execution->proposition_etat = AgentExecution::EN_ATTENTE;
                    }
                } elseif (($evenement['type'] ?? null) === 'fin') {
                    $fin = $evenement;
                }
                $surEvenement($evenement);
            }, $arret, [
                'consignes' => $consignes,
                'historique' => $this->historique($agent, $execution),
                'donnees_agent' => ['historique' => $this->resumeHistorique($agent, $execution)],
            ]);
        } catch (AssistantIndisponible $e) {
            $fin = ['statut' => 'arret', 'message' => $e->getMessage(), 'detail' => $e->detail, 'fichiers' => []];
        } catch (Throwable $e) {
            $fin = ['statut' => 'arret', 'message' => AssistantClient::INDISPONIBLE, 'detail' => get_class($e).' : '.$e->getMessage(), 'fichiers' => []];
        }

        if ($arretDemande) {
            try {
                $this->client->arreter($execution);
            } catch (Throwable) {
                // Le flux est déjà fermé côté site : le service finira de lui-même au prochain tour.
            }
            $fin = ['statut' => 'arret', 'message' => 'Arrêt demandé.', 'fichiers' => $fin['fichiers'] ?? []];
        }
        $fin ??= ['statut' => 'arret', 'message' => AssistantClient::INDISPONIBLE, 'detail' => 'Flux interrompu : fin de réponse manquante', 'fichiers' => []];

        $produits = array_values(array_filter((array) ($fin['fichiers'] ?? []),
            fn ($nom) => Storage::disk('local')->exists($execution->dossierSortie().'/'.$nom)));
        $reussi = ($fin['statut'] ?? '') === 'termine';

        $execution->fill([
            'etat' => $reussi ? AgentExecution::TERMINE : AgentExecution::ARRET,
            'message' => (string) ($fin['message'] ?? ''),
            'detail' => $fin['detail'] ?? null,
            'etapes' => $etapes,
            'fichiers_produits' => $produits,
            'fin_at' => now(),
        ])->save();

        if ($rejouerRecette && $reussi) {
            $agent->update(['dernier_lancement_at' => now(), 'veille_prevenue_at' => null]);
            $this->fichiers->consommerApports($agent);
            // Le rappel est honoré : la prochaine échéance repart d'aujourd'hui (l'agent sort de « À faire »).
            $rappel = $agent->rappel;
            if ($rappel !== null && ($prochaine = AgentRappel::echeanceApres($rappel->periodicite)) !== null) {
                $rappel->update(['prochaine_echeance' => $prochaine->toDateString(), 'notifie_at' => null]);
            }
        }

        return $execution;
    }

    /**
     * Derniers échanges terminés de CET agent, du plus ancien au plus récent (alternance utilisateur / assistant).
     *
     * @return list<array{role: string, content: string}>
     */
    private function historique(Agent $agent, AgentExecution $courante): array
    {
        $tours = max(0, (int) config('assistant.historique_tours'));
        $precedentes = $agent->executions()->reorder()->orderByDesc('id')
            ->where('id', '<', $courante->id)->where('etat', AgentExecution::TERMINE)
            ->where('message', '!=', '')->limit($tours)->get()->reverse()->values();

        $messages = [];
        foreach ($precedentes as $e) {
            $messages[] = ['role' => 'user', 'content' => $e->demande];
            $messages[] = ['role' => 'assistant', 'content' => (string) $e->message];
        }

        return $messages;
    }

    /** @return list<array<string, string>> */
    private function resumeHistorique(Agent $agent, AgentExecution $courante): array
    {
        return $agent->executions()->reorder()->orderByDesc('id')->where('id', '<', $courante->id)->limit(10)->get()
            ->map(fn (AgentExecution $e) => [
                'date' => $e->created_at->format('Y-m-d H:i'), 'mode' => $e->mode, 'etat' => $e->etat,
                'demande' => mb_substr($e->demande, 0, 150), 'resume' => mb_substr((string) $e->message, 0, 200),
                'fichiers_apportes' => implode(', ', $e->fichiers_apportes ?? []),
                'fichiers_produits' => implode(', ', $e->fichiers_produits ?? []),
            ])->all();
    }
}
