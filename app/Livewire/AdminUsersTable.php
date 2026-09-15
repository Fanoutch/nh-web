<?php

namespace App\Livewire;

use App\Models\Secteur;
use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class AdminUsersTable extends Component
{
    use WithPagination;

    public string $search = '';

    public string $roleFilter = 'all';

    public function toggleAdmin(int $userId): void
    {
        if (!auth()->user()->isSuperAdmin()) {
            session()->flash('error', 'Seul un super admin peut modifier les statuts admin.');
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Vous ne pouvez pas modifier votre propre statut.');
            return;
        }

        if ($user->is_super_admin) {
            session()->flash('error', 'Impossible de modifier le statut admin d\'un super admin. Retire d\'abord le statut super admin.');
            return;
        }

        $user->is_admin = !$user->is_admin;
        $user->save();

        session()->flash(
            'success',
            $user->is_admin
                ? "{$user->name} est maintenant admin."
                : "{$user->name} n'est plus admin."
        );
    }

    public function toggleSuperAdmin(int $userId): void
    {
        if (!auth()->user()->isSuperAdmin()) {
            session()->flash('error', 'Seul un super admin peut promouvoir/retirer un super admin.');
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Vous ne pouvez pas modifier votre propre statut super admin.');
            return;
        }

        if ($user->is_super_admin && User::where('is_super_admin', true)->count() <= 1) {
            session()->flash('error', 'Impossible de retirer le dernier super admin du systeme.');
            return;
        }

        $user->is_super_admin = !$user->is_super_admin;
        // Un super admin est aussi admin par definition
        if ($user->is_super_admin) {
            $user->is_admin = true;
        }
        $user->save();

        session()->flash(
            'success',
            $user->is_super_admin
                ? "{$user->name} est maintenant super admin."
                : "{$user->name} n'est plus super admin."
        );
    }

    public function togglePersonnelNavigant(int $userId): void
    {
        if (!auth()->user()->isSuperAdmin()) {
            session()->flash('error', 'Seul un super admin peut modifier le role metier.');
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Vous ne pouvez pas modifier votre propre role metier.');
            return;
        }

        $user->is_personnel_navigant = !$user->is_personnel_navigant;
        $user->save();

        session()->flash(
            'success',
            $user->is_personnel_navigant
                ? "{$user->name} est maintenant Personnel Navigant."
                : "{$user->name} est maintenant Technicien."
        );
    }

    public function setRoleSecteur(int $userId, int $secteurId, string $role): void
    {
        if (!auth()->user()->isSuperAdmin()) {
            session()->flash('error', 'Seul un super admin peut modifier les rôles de secteur.');
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'Vous ne pouvez pas modifier vos propres rôles de secteur.');
            return;
        }

        if (!in_array($role, ['aucun', Secteur::ROLE_UTILISATEUR, Secteur::ROLE_CHEF], true)) {
            session()->flash('error', 'Rôle de secteur inconnu.');
            return;
        }

        $secteur = Secteur::findOrFail($secteurId);

        if ($role === 'aucun') {
            $user->secteurs()->detach($secteur->id);
            session()->flash('success', "{$user->name} n'a plus accès à {$secteur->nom}.");
            return;
        }

        $user->secteurs()->syncWithoutDetaching([$secteur->id => ['role' => $role]]);
        session()->flash('success', "{$user->name} est maintenant {$role} {$secteur->nom}.");
    }

    public function render()
    {
        $query = User::with('secteurs')
            ->orderByDesc('is_super_admin')
            ->orderByDesc('is_admin')
            ->orderByDesc('is_personnel_navigant')
            ->orderBy('id');

        if ($this->roleFilter === 'technicien') {
            $query->where('is_personnel_navigant', false)
                  ->where('is_admin', false)
                  ->where('is_super_admin', false);
        } elseif ($this->roleFilter === 'pn') {
            $query->where('is_personnel_navigant', true);
        } elseif ($this->roleFilter === 'admin') {
            $query->where(function ($q) {
                $q->where('is_admin', true)->orWhere('is_super_admin', true);
            });
        } elseif (str_starts_with($this->roleFilter, 'secteur-')) {
            $secteurId = (int) substr($this->roleFilter, strlen('secteur-'));
            $query->whereHas('secteurs', fn ($q) => $q->where('secteurs.id', $secteurId));
        }

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', "%{$s}%")
                  ->orWhere('email', 'ilike', "%{$s}%");
            });
        }

        return view('livewire.admin-users-table', [
            'users' => $query->paginate(20),
            'currentIsSuperAdmin' => auth()->user()->isSuperAdmin(),
            'secteurs' => Secteur::orderBy('nom')->get(),
        ]);
    }
}
