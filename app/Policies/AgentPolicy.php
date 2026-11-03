<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

/**
 * Agents personnels : seul le propriétaire a des droits, les admins n'y voient rien de plus.
 */
class AgentPolicy
{
    public function view(User $user, Agent $agent): bool
    {
        return $agent->user_id === $user->id && $agent->retire_at === null;
    }

    /** Une copie du catalogue (assignée par le super admin) se lance, mais ne se modifie ni ne se supprime. */
    public function update(User $user, Agent $agent): bool
    {
        return $this->view($user, $agent) && ! $agent->estDuCatalogue();
    }

    public function delete(User $user, Agent $agent): bool
    {
        return $this->view($user, $agent) && ! $agent->estDuCatalogue();
    }

    /** Lancer, arrêter, déposer des fichiers. Un agent en veille doit d'abord être réactivé. */
    public function lancer(User $user, Agent $agent): bool
    {
        return $this->view($user, $agent) && ! $agent->estEnVeille();
    }
}
