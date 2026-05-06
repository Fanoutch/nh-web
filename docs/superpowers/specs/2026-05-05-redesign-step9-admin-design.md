# Design — Étape 9 / Refonte `/admin/*`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`

## 1. Contexte

Pages admin (ajoutées hors-spec proto par l'utilisateur en parallèle du redesign) :
- `/admin/users` : gestion des rôles (admin, super admin) avec recherche + actions toggle.
- `/admin/audit-log` : journal d'activité Spatie ActivityLog avec filtres module + action.

Les vues actuelles utilisent slate/blue/purple/yellow Tailwind par défaut. Cette étape les harmonise avec la palette redesign (amber/ink/ok/danger/warn) et la nouvelle structure (`<x-section-label>`, `<x-card>`, `<x-badge>`).

Ces pages ne sont pas dans le proto Claude Design — design libre, mais cohérent avec les autres pages refaites (étapes 4 / 6 / 7).

## 2. Objectifs

- Refondre `admin/users.blade.php` et `admin/audit-log.blade.php` (headers section-label).
- Refondre `livewire/admin-users-table.blade.php` (search input, badges rôles, table avec pagination ghost custom, boutons toggle role).
- Refondre `livewire/audit-log-table.blade.php` (filtres select, badges sémantiques pour events, table avec diff visuel, pagination ghost custom).
- Mapping rôles :
  - **Super Admin** : violet inline `bg-[#ede9fe] text-[#6d28d9] border-[#c4b5fd]` (rare, palette spécifique non globale).
  - **Admin** : `<x-badge variant="amber">` (warn doré).
  - **Utilisateur** : plain text muted (pas de badge).
- Mapping events audit log :
  - `created` → `<x-badge variant="ok">`.
  - `updated` → `<x-badge variant="amber">`.
  - `deleted` → `<x-badge variant="error">`.
  - autre → `<x-badge variant="pending">`.

**Non-objectifs** :
- Modifications PHP des composants Livewire (`AdminUsersTable.php`, `AuditLogTable.php`) — uniquement les vues.
- Modifications de la logique de rôles, des migrations, ou du middleware `EnsureAdmin`.
- Ajout de fonctionnalités (sort, tri, export).

## 3. Architecture / fichiers touchés

```
nh-web/
└── resources/views/
    ├── admin/
    │   ├── users.blade.php                      (refonte header)
    │   └── audit-log.blade.php                  (refonte header)
    └── livewire/
        ├── admin-users-table.blade.php          (refonte complète)
        └── audit-log-table.blade.php            (refonte complète)
```

4 fichiers Blade. Aucun nouveau composant. Aucune modification PHP.

## 4. Vue `admin/users.blade.php`

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Administration</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Gestion des utilisateurs</h1>
    </div>
    <livewire:admin-users-table />
</x-app-layout>
```

## 5. Vue `admin/audit-log.blade.php`

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Administration</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Journal d'audit</h1>
    </div>
    <livewire:audit-log-table />
</x-app-layout>
```

## 6. Livewire `admin-users-table.blade.php`

```blade
<div class="space-y-4">
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="px-4 py-3 bg-ok-soft border border-ok-border text-ok rounded text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="px-4 py-3 bg-danger-soft border border-danger-border text-danger rounded text-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- Search + role info --}}
    <div class="flex justify-between items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (nom, email)"
               class="w-96 bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />

        <div class="flex items-center gap-3 text-xs">
            @if ($currentIsSuperAdmin)
                <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] px-2 py-1 rounded font-mono font-medium">
                    Vous êtes super admin
                </span>
            @else
                <span class="inline-flex items-center gap-1 bg-neutral-soft text-neutral border border-neutral-border px-2 py-1 rounded font-mono">
                    Vous êtes admin (lecture seule)
                </span>
            @endif
            <span class="text-ink-muted">
                {{ $users->total() }} utilisateur{{ $users->total() > 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-12">ID</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Nom</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Email</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-40">Statut</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-72">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors" wire:key="user-{{ $u->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-muted">{{ $u->id }}</td>
                            <td class="px-4 py-2.5 font-medium text-ink-primary">{{ $u->name }}</td>
                            <td class="px-4 py-2.5 text-ink-secondary">{{ $u->email }}</td>
                            <td class="px-4 py-2.5">
                                @if ($u->is_super_admin)
                                    <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                        Super Admin
                                    </span>
                                @elseif ($u->is_admin)
                                    <span class="inline-flex items-center gap-1 bg-accent-soft-strong text-warn text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                        Admin
                                    </span>
                                @else
                                    <span class="text-ink-muted text-xs">Utilisateur</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($u->id === auth()->id())
                                    <span class="text-xs text-ink-muted italic">vous-même</span>
                                @elseif (!$currentIsSuperAdmin)
                                    <span class="text-xs text-ink-muted italic">—</span>
                                @else
                                    <div class="flex gap-1.5 flex-wrap">
                                        @if ($u->is_super_admin)
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Retirer le statut super admin de {{ $u->name }} ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                Retirer super admin
                                            </button>
                                        @else
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Promouvoir {{ $u->name }} super admin ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-[#ede9fe] border border-[#c4b5fd] text-[#6d28d9] text-[11px] font-medium hover:bg-[#ddd6fe] transition">
                                                Promouvoir super admin
                                            </button>
                                        @endif

                                        @if (!$u->is_super_admin)
                                            @if ($u->is_admin)
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Retirer le statut admin de {{ $u->name }} ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                    Retirer admin
                                                </button>
                                            @else
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Rendre {{ $u->name }} admin ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-ok-soft border border-ok-border text-ok text-[11px] font-medium hover:bg-ok-border transition">
                                                    Promouvoir admin
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun utilisateur trouvé.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $users->firstItem() }}–{{ $users->lastItem() }} de {{ $users->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($users->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</button>
                    @endif
                    @if ($users->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</button>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</div>
```

Notes :
- Pagination via `wire:click="previousPage"` / `nextPage` (méthodes Livewire automatiques pour `WithPagination` trait).
- Boutons confirm via `wire:confirm` (Livewire 3) — natif, pas besoin de modal custom.

## 7. Livewire `audit-log-table.blade.php`

```blade
<div class="space-y-4">
    {{-- Filtres --}}
    <div class="flex items-center gap-3">
        <select wire:model.live="logName"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Tous les modules</option>
            @foreach ($logNames as $ln)
                <option value="{{ $ln }}">{{ ucfirst($ln) }}</option>
            @endforeach
        </select>

        <select wire:model.live="event"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Toutes les actions</option>
            @foreach ($events as $ev)
                <option value="{{ $ev }}">{{ ucfirst($ev) }}</option>
            @endforeach
        </select>

        <span class="text-xs text-ink-muted ml-auto">
            {{ $activities->total() }} entrée{{ $activities->total() > 1 ? 's' : '' }}
        </span>
    </div>

    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-44">Date</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-48">Utilisateur</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-32">Module</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-32">Action</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Cible</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Changements</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activities as $a)
                        @php
                            $eventVariant = match ($a->event) {
                                'created' => 'ok',
                                'updated' => 'amber',
                                'deleted' => 'error',
                                default => 'pending',
                            };
                        @endphp
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors align-top" wire:key="act-{{ $a->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary whitespace-nowrap">
                                {{ $a->created_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($a->causer)
                                    <div class="font-medium text-ink-primary">{{ $a->causer->name }}</div>
                                    <div class="text-[11px] text-ink-muted">{{ $a->causer->email }}</div>
                                @else
                                    <span class="text-ink-muted italic">système</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge variant="amber">{{ $a->log_name ?? '—' }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :variant="$eventVariant">{{ $a->event ?? '—' }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($a->subject_type)
                                    <span class="font-mono text-xs text-ink-secondary">
                                        {{ class_basename($a->subject_type) }}#{{ $a->subject_id }}
                                    </span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @php
                                    $old = data_get($a->properties, 'old', []);
                                    $new = data_get($a->properties, 'attributes', []);
                                    $keys = array_unique(array_merge(array_keys((array) $old), array_keys((array) $new)));
                                @endphp
                                @if (!empty($keys))
                                    <ul class="space-y-0.5 text-[11px] font-mono">
                                        @foreach ($keys as $k)
                                            <li>
                                                <span class="text-ink-muted">{{ $k }}:</span>
                                                <span class="text-danger line-through">{{ data_get($old, $k) ?? '∅' }}</span>
                                                <span class="text-ink-muted">→</span>
                                                <span class="text-ok font-medium">{{ data_get($new, $k) ?? '∅' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucune activité enregistrée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($activities->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $activities->firstItem() }}–{{ $activities->lastItem() }} de {{ $activities->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($activities->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</button>
                    @endif
                    @if ($activities->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</button>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</div>
```

## 8. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test` reste vert.
- **Smoke Playwright** :
  - `/admin/users` (en tant que super admin) :
    - Header section-label "ADMINISTRATION" + h1 "Gestion des utilisateurs"
    - Search input à gauche, info rôle "Vous êtes super admin" en violet à droite
    - Card avec table : colonnes ID/Nom/Email/Statut/Actions
    - Statuts : Super Admin violet / Admin amber / Utilisateur muted plain
    - Boutons toggle role pour les autres users (rouge soft pour retirer, vert ok soft pour promouvoir admin, violet pour promouvoir super admin)
    - Pagination ghost custom en bas si > 10 users
  - `/admin/audit-log` :
    - Header section-label "ADMINISTRATION" + h1 "Journal d'audit"
    - 2 filtres select (modules / actions) avec focus accent
    - Card avec table : colonnes Date/Utilisateur/Module/Action/Cible/Changements
    - Module = badge amber, Action = badge color-coded (created vert / updated amber / deleted rouge)
    - Diff visuel : `key: old (line-through danger) → new (font-medium ok)` mono

## 9. Hors-scope

Aucune étape ultérieure prévue après l'étape 9. Cela conclut le redesign complet.
