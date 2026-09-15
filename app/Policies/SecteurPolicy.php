<?php

namespace App\Policies;

use App\Models\Secteur;
use App\Models\User;

/**
 * Source unique des droits d'un secteur. Admins = droits de chef partout, sans être membres.
 */
class SecteurPolicy
{
    /** Voir le secteur : carte, page, onglets. */
    public function view(User $user, Secteur $secteur): bool
    {
        return $user->isAdmin() || $user->roleDans($secteur) !== null;
    }

    /** Consulter l'historique des dispos et les télécharger. */
    public function consulterDispos(User $user, Secteur $secteur): bool
    {
        return $this->view($user, $secteur);
    }

    /** Générer une dispo, supprimer une dispo. */
    public function gererDispos(User $user, Secteur $secteur): bool
    {
        return $user->isAdmin() || $user->roleDans($secteur) === Secteur::ROLE_CHEF;
    }
}
