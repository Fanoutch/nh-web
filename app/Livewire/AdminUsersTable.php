<?php

namespace App\Livewire;

use App\Models\Agent;
use App\Models\Secteur;
use App\Models\User;
use App\Services\AssignationAgents;
use App\Services\AssignationImpossible;
use App\Services\CatalogueAgents;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class AdminUsersTable extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $roleFilter = 'all';

    /** Onglet affiché : « utilisateurs » ou « catalogue » (agents assignables). */
    public string $onglet = 'utilisateurs';

    /** Agent du catalogue dont le volet « Assigner par e-mail » est ouvert. */
    public ?string $ouvert = null;

    public string $saisieMails = '';

    /** Agent du catalogue dont le volet « Références » est ouvert. */
    public ?string $refsOuvert = null;

    /** Fichiers choisis pour le dépôt : $depots[slug][rang du fichier attendu]. */
    public array $depots = [];

    public function ouvrirCatalogue(): void
    {
        $this->onglet = 'catalogue';
    }

    public function ouvrirUtilisateurs(): void
    {
        $this->onglet = 'utilisateurs';
    }

    public function basculerAssignation(string $slug): void
    {
        $this->ouvert = $this->ouvert === $slug ? null : $slug;
        $this->saisieMails = '';
    }

    public function basculerReferences(string $slug): void
    {
        $this->refsOuvert = $this->refsOuvert === $slug ? null : $slug;
        $this->depots = [];
    }

    /** Un fichier vient d'être choisi dans le volet « Références » (clé « slug.rang »). */
    public function updatedDepots($valeur, string $cle, CatalogueAgents $catalogue): void
    {
        [$slug, $rang] = array_pad(explode('.', $cle, 2), 2, null);
        $this->depots = [];
        if (! $this->superAdminRequis() || $valeur === null || $rang === null) {
            return;
        }
        $attendus = $catalogue->trouver((string) $slug)['references'] ?? [];
        $nom = isset($attendus[(int) $rang]) ? basename($attendus[(int) $rang]) : null;
        if ($nom === null) {
            session()->flash('error', 'Ce fichier n\'est pas attendu par cet agent.');

            return;
        }
        try {
            $catalogue->deposerReference((string) $slug, $nom, $valeur);
            session()->flash('success', "« {$nom} » est déposé.".($catalogue->nombreAMettreAJour((string) $slug) > 0 ? ' Pensez à mettre à jour les copies déjà assignées.' : ''));
        } catch (AssignationImpossible $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /** Toute action sur les agents est réservée au super admin ; renvoie faux (avec message) sinon. */
    private function superAdminRequis(): bool
    {
        if (! auth()->user()->isSuperAdmin()) {
            session()->flash('error', 'Seul un super admin peut assigner des agents.');

            return false;
        }

        return true;
    }

    public function assignerParMail(string $slug, AssignationAgents $assignation): void
    {
        if (! $this->superAdminRequis()) {
            return;
        }
        try {
            $r = $assignation->assignerParEmails($slug, $this->saisieMails);
        } catch (AssignationImpossible $e) {
            session()->flash('error', $e->getMessage());

            return;
        }
        $this->saisieMails = '';
        $phrases = [];
        if ($r['assignes']) {
            $phrases[] = 'Agent assigné à : '.implode(', ', $r['assignes']).'.';
        }
        if ($r['deja']) {
            $phrases[] = 'Déjà assigné (copie mise à jour) : '.implode(', ', $r['deja']).'.';
        }
        if ($r['inconnus']) {
            $phrases[] = 'Aucun compte pour : '.implode(', ', $r['inconnus']).'.';
        }
        session()->flash($r['inconnus'] && ! $r['assignes'] && ! $r['deja'] ? 'error' : 'success', implode(' ', $phrases));
    }

    /** Menu « + Ajouter » d'une ligne d'utilisateur. */
    public function ajouterAgent(int $userId, string $slug, AssignationAgents $assignation): void
    {
        if (! $this->superAdminRequis() || $slug === '') {
            return;
        }
        try {
            $assignation->assigner($slug, User::findOrFail($userId));
            session()->flash('success', 'Agent assigné.');
        } catch (AssignationImpossible $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    public function retirerAgent(int $agentId, AssignationAgents $assignation): void
    {
        if (! $this->superAdminRequis()) {
            return;
        }
        $agent = Agent::whereNotNull('modele_slug')->findOrFail($agentId);
        $assignation->retirer($agent);
        session()->flash('success', "« {$agent->nom} » retiré à {$agent->user->name}. Son historique est conservé.");
    }

    public function mettreAJourCopies(string $slug, AssignationAgents $assignation): void
    {
        if (! $this->superAdminRequis()) {
            return;
        }
        try {
            $n = $assignation->mettreAJourCopies($slug);
            session()->flash('success', $n.' copie'.($n > 1 ? 's' : '').' mise'.($n > 1 ? 's' : '').' à jour.');
        } catch (AssignationImpossible $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    /** Bouton « Tester » : crée (ou rouvre) la copie du super admin connecté et l'ouvre comme la verrait un utilisateur. */
    public function tester(string $slug, AssignationAgents $assignation)
    {
        if (! $this->superAdminRequis()) {
            return null;
        }
        try {
            $agent = $assignation->assigner($slug, auth()->user());
        } catch (AssignationImpossible $e) {
            session()->flash('error', $e->getMessage());

            return null;
        }

        return redirect()->route('agents.show', $agent);
    }

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
            'users' => $query->with(['agents' => fn ($q) => $q->whereNotNull('modele_slug')])->paginate(20),
            'currentIsSuperAdmin' => auth()->user()->isSuperAdmin(),
            'secteurs' => Secteur::orderBy('nom')->get(),
            'catalogue' => $catalogue = app(CatalogueAgents::class),
            'modeles' => $catalogue->tous(),
            'copies' => Agent::whereNotNull('modele_slug')->whereNull('retire_at')->with('user:id,name,email')->get()->groupBy('modele_slug'),
        ]);
    }
}
