<?php

namespace App\Livewire;

use App\Models\User;
use Livewire\Component;
use Livewire\WithPagination;

class AdminUsersTable extends Component
{
    use WithPagination;

    public string $search = '';

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

    public function render()
    {
        $query = User::orderByDesc('is_super_admin')
            ->orderByDesc('is_admin')
            ->orderBy('id');

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
        ]);
    }
}
