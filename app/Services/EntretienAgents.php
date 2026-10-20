<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\Storage;

/**
 * Entretien quotidien des agents : mise en veille de ceux qui n'ont pas été LANCÉS depuis 3 mois, et ménage des
 * brouillons jamais utilisés. Rien d'autre n'est supprimé : tant qu'un agent est actif, ses fichiers et son
 * historique restent (même au-delà de 30 jours).
 */
class EntretienAgents
{
    public function __construct(private GestionFichiersAgent $fichiers)
    {
    }

    /** @return int nombre d'agents mis en veille */
    public function mettreEnVeille(): int
    {
        $n = 0;
        foreach (Agent::where('etat', Agent::ACTIF)->get() as $agent) {
            if ($agent->veilleLe()->isFuture()) {
                continue;
            }
            // Rappels coupés, fichiers de travail supprimés ; recette, consignes, références et historique conservés.
            $agent->rappel()?->delete();
            foreach ($agent->apports()->get() as $apport) {
                $this->fichiers->supprimerFichier($apport);
            }
            $this->fichiers->viderFichiersDeTravail($agent);
            $agent->update(['etat' => Agent::EN_VEILLE, 'en_veille_at' => now()]);
            $n++;
        }

        return $n;
    }

    /** Brouillons sans aucun échange depuis plus de 7 jours. @return int nombre supprimés */
    public function nettoyerBrouillons(): int
    {
        $n = 0;
        foreach (Agent::where('etat', Agent::BROUILLON)->where('created_at', '<', now()->subDays(7))->whereDoesntHave('executions')->get() as $agent) {
            Storage::disk('local')->deleteDirectory($agent->dossier());
            $agent->delete();
            $n++;
        }

        return $n;
    }
}
