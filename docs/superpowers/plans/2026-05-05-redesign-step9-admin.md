# Redesign Étape 9 — `/admin/*` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre les vues admin (`/admin/users` + `/admin/audit-log`) selon la palette redesign : section-labels, badges sémantiques (super admin violet inline, admin amber, events ok/warn/danger), pagination ghost custom. Aucune modification PHP.

**Architecture:** 4 fichiers Blade modifiés (2 vues + 2 partials Livewire). Les composants `<x-card>`, `<x-section-label>`, `<x-badge>` (créés étape 1) sont réutilisés. Pagination Livewire native via `wire:click="previousPage"` / `nextPage`.

**Tech Stack:** Laravel 12, Blade, Livewire 3 (WithPagination), Tailwind CSS, Pest 3, Playwright MCP.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step9-admin-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step9-admin-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step9-admin.md`

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??` (le spec déjà créé en pause + le plan qu'on vient d'écrire).

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step9-admin-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step9-admin.md
git commit -m "docs: add spec and plan for redesign step 9 (/admin/*)"
```

---

## Task 2: Refondre `admin/users.blade.php` (header)

**Files:**
- Modify: `resources/views/admin/users.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Administration</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Gestion des utilisateurs</h1>
    </div>
    <livewire:admin-users-table />
</x-app-layout>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/users.blade.php
git commit -m "feat(admin): redesign /admin/users header with section-label"
```

---

## Task 3: Refondre `admin/audit-log.blade.php` (header)

**Files:**
- Modify: `resources/views/admin/audit-log.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Administration</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Journal d'audit</h1>
    </div>
    <livewire:audit-log-table />
</x-app-layout>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/admin/audit-log.blade.php
git commit -m "feat(admin): redesign /admin/audit-log header with section-label"
```

---

## Task 4: Refondre `livewire/admin-users-table.blade.php`

**Files:**
- Modify: `resources/views/livewire/admin-users-table.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/admin-users-table.blade.php
git commit -m "feat(admin): redesign users table with sem badges and ghost pagination"
```

---

## Task 5: Refondre `livewire/audit-log-table.blade.php`

**Files:**
- Modify: `resources/views/livewire/audit-log-table.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/audit-log-table.blade.php
git commit -m "feat(admin): redesign audit log table with sem event badges and diff display"
```

---

## Task 6: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests finaux**

```bash
npm run build
php artisan test
```

Expected: tout vert, 59 tests passants.

- [ ] **Step 2: Promouvoir l'utilisateur de test en super admin (pour voir les actions)**

```bash
php artisan tinker --execute="\App\Models\User::where('email', 'test@nh.local')->update(['is_super_admin' => true, 'is_admin' => true]);"
```

(Si déjà fait, sans effet.)

- [ ] **Step 3: Screenshot Playwright `/admin/users`**

Naviguer `http://127.0.0.1:8000/admin/users` (en tant que super admin). Capture viewport 1440×900.

Vérifier :
- Header section-label "ADMINISTRATION" + h1 "Gestion des utilisateurs"
- Search input avec focus accent au focus
- Pill "Vous êtes super admin" en violet à droite
- Tableau colonnes ID/Nom/Email/Statut/Actions
- Statuts : badges colorés (Super Admin violet, Admin amber, Utilisateur muted plain)
- Actions : boutons toggle role color-coded (rouge soft pour retirer, vert ok soft pour promouvoir admin, violet pour super admin)
- Pagination ghost custom en bas si > 10 users

- [ ] **Step 4: Screenshot Playwright `/admin/audit-log`**

Naviguer `http://127.0.0.1:8000/admin/audit-log`. Vérifier :
- Header section-label "ADMINISTRATION" + h1 "Journal d'audit"
- 2 selects filtres (modules / actions) + count à droite
- Tableau colonnes Date / Utilisateur / Module / Action / Cible / Changements
- Module = badge amber, Action = badge color-coded selon event (created vert / updated amber / deleted rouge)
- Diff visuel mono : `key: ∅ (line-through danger) → new (font-medium ok)`

- [ ] **Step 5: Vérifier console JS**

`browser_console_messages` level=error → 0 erreur.

---

## Notes / pièges connus

- **Pagination Livewire 3** : le composant utilise `WithPagination` trait → les méthodes `previousPage()` et `nextPage()` sont automatiques. Pas de code PHP à ajouter.
- **`wire:confirm`** (Livewire 3) : popup natif de confirmation sans modal custom. Texte interpolé dans le template Blade.
- **Couleur violette inline `[#ede9fe]`** : pas dans la palette globale `tailwind.config.js`. Utilisée seulement pour le rôle Super Admin (rare). Si plus de besoins violets ailleurs, on l'ajoutera comme couleur sémantique `superadmin` plus tard. YAGNI pour l'instant.
- **Spec déjà commité ? Non** : le spec est sur disque mais untracked depuis qu'il a été écrit puis l'étape mise en pause. La Task 1 commite spec + plan ensemble.
- **`<x-badge>` ne supporte pas un texte dynamique avec accents/casse libre directement** : `{{ ucfirst($a->log_name) }}` → l'output passe dans le slot, OK. Pas de souci de variant.
- **Test Pest** : aucun test admin spécifique dans la suite. Si on regarde `php artisan test`, les tests touchant les routes admin (s'il y en a) doivent toujours passer. Ne touche pas à la logique PHP.
- **Hover row** : `hover:bg-app-bg transition-colors` sur `<tr>`. Cohérent avec /admin/users/show (étape 4) et /imports (étape 6).
- **Empty state** : `<td colspan="..." class="px-4 py-12 text-center text-ink-muted">` — pattern réutilisé partout.
