# Redesign Étape 4 — `/machines/{HcId}` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre `/machines/{HcId}` selon le proto : breadcrumb ghost, header HcId mono ambre 28px + 3 KPI boxes encadrés, tabs underline amber, tableau cliquable avec badges color-coded, pagination ghost custom. Étendre `<x-counter-pill>` avec un prop `boxed`.

**Architecture:** Touche 3 fichiers — composant `<x-counter-pill>`, contrôleur `MachineController@show` (déplace `$counts` de la vue au contrôleur + ajoute `$totalCount`), vue `machines/show.blade.php` refonte complète. Aucune nouvelle migration, aucun nouveau composant.

**Tech Stack:** Laravel 12, Blade, Tailwind CSS, Pest 3, Playwright MCP pour smoke browser.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step4-machine-detail-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step4-machine-detail-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step4-machine-detail.md` (ce fichier)

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??` (spec + plan).

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step4-machine-detail-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step4-machine-detail.md
git commit -m "docs: add spec and plan for redesign step 4 (/machines/{HcId})"
```

---

## Task 2: Étendre `<x-counter-pill>` avec prop `boxed`

**Files:**
- Modify: `resources/views/components/counter-pill.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/components/counter-pill.blade.php` with**

```blade
@props(['value', 'label', 'variant' => 'default', 'boxed' => false])

@php
    $valueClass = match ($variant) {
        'danger'    => 'text-danger',
        'secondary' => 'text-ink-secondary',
        default     => 'text-ink-primary',
    };
    $labelClass = $variant === 'danger' ? 'text-danger' : 'text-ink-muted';
    $wrapperClass = $boxed
        ? ($variant === 'danger'
            ? 'flex flex-col items-center px-4 py-1.5 bg-danger-soft border border-danger-border rounded-md'
            : 'flex flex-col items-center px-4 py-1.5 bg-app-card border border-app-border rounded-md')
        : 'flex flex-col items-center';
@endphp

<div class="{{ $wrapperClass }}">
    <p class="font-mono text-lg font-medium tabular-nums leading-none {{ $valueClass }}">{{ $value }}</p>
    <p class="text-[10px] uppercase tracking-wide font-medium mt-1 {{ $labelClass }}">{{ $label }}</p>
</div>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès, aucune erreur sur `bg-danger-soft`, `border-danger-border`, `bg-app-card`, `border-app-border` (tokens étape 1 disponibles).

- [ ] **Step 3: Run Pest test suite (vérifier non-régression sur usages existants)**

```bash
php artisan test
```

Expected: 59 verts. La page `/machines` continue de fonctionner avec `boxed=false` par défaut.

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/counter-pill.blade.php
git commit -m "feat(components): add boxed prop to x-counter-pill for KPI box rendering"
```

---

## Task 3: Modifier `MachineController@show`

**Files:**
- Modify: `app/Http/Controllers/MachineController.php`

- [ ] **Step 1: Lire le fichier actuel pour repérer la méthode show()**

```bash
cat app/Http/Controllers/MachineController.php
```

Repérer `public function show(string $hcId, Request $request)`.

- [ ] **Step 2: Remplacer la méthode `show()` complète**

Remplacer la méthode :

```php
    public function show(string $hcId, Request $request)
    {
        $machine = Machine::where('hc_id', $hcId)->firstOrFail();
        $tab = $request->get('tab', 'vols');

        $query = $machine->flights()->orderByDesc('start_datetime');
        $query->when($tab === 'vols', fn ($q) => $q->where('is_non_vol', false));
        $query->when($tab === 'non-vols', fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false));
        $query->when($tab === 'erreurs', fn ($q) => $q->where('flagged_as_error', true));

        $flights = $query->withCount([
            'technicalEvents as conservees_count' => fn ($q) => $q->where('status', 'conservee'),
        ])->paginate(25);

        return view('machines.show', compact('machine', 'tab', 'flights'));
    }
```

par :

```php
    public function show(string $hcId, Request $request)
    {
        $machine = Machine::where('hc_id', $hcId)->firstOrFail();
        $tab = $request->get('tab', 'vols');

        $counts = [
            'vols'     => $machine->flights()->where('is_non_vol', false)->count(),
            'non-vols' => $machine->flights()->where('is_non_vol', true)->where('flagged_as_error', false)->count(),
            'erreurs'  => $machine->flights()->where('flagged_as_error', true)->count(),
        ];
        $totalCount = array_sum($counts);

        $query = $machine->flights()->orderByDesc('start_datetime');
        $query->when($tab === 'vols', fn ($q) => $q->where('is_non_vol', false));
        $query->when($tab === 'non-vols', fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false));
        $query->when($tab === 'erreurs', fn ($q) => $q->where('flagged_as_error', true));

        $flights = $query->withCount([
            'technicalEvents as conservees_count' => fn ($q) => $q->where('status', 'conservee'),
        ])->paginate(25);

        return view('machines.show', compact('machine', 'tab', 'counts', 'totalCount', 'flights'));
    }
```

- [ ] **Step 3: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 verts. La vue actuelle calcule encore `$counts` inline mais le contrôleur passe maintenant `$counts` aussi → la vue ignore la version contrôleur (recompute inline). Pas de régression.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/MachineController.php
git commit -m "feat(controller): pass counts and totalCount to machines.show view"
```

---

## Task 4: Refondre `machines/show.blade.php`

**Files:**
- Modify: `resources/views/machines/show.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/machines/show.blade.php` with**

```blade
<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.index') }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Machines
        </a>
    </div>

    {{-- Header --}}
    <div class="flex items-baseline gap-4 mb-6">
        <h1 class="font-mono text-[28px] font-medium text-accent">{{ $machine->hc_id }}</h1>
        <div class="text-[13px] text-ink-muted">{{ $totalCount }} enregistrement(s)</div>
        <div class="flex-1"></div>
        <div class="flex items-center gap-2.5">
            <x-counter-pill :value="$counts['vols']" label="Vols" boxed />
            <x-counter-pill :value="$counts['non-vols']" label="Non-Vols" variant="secondary" boxed />
            <x-counter-pill :value="$counts['erreurs']" label="Erreurs" :variant="$counts['erreurs'] > 0 ? 'danger' : 'secondary'" boxed />
        </div>
    </div>

    @php
        $labels = ['vols' => 'Vols', 'non-vols' => 'Non-Vols', 'erreurs' => 'Erreurs'];
    @endphp

    {{-- Tabs --}}
    <div class="border-b border-app-border mb-0">
        <nav class="flex gap-1">
            @foreach ($labels as $key => $label)
                @php $isActive = $tab === $key; @endphp
                <a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => $key]) }}"
                   @class([
                       'px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors',
                       'text-accent-pressed border-accent' => $isActive,
                       'text-ink-secondary border-transparent hover:text-ink-primary' => !$isActive,
                   ])>
                    {{ $label }} ({{ $counts[$key] }})
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Tableau --}}
    <x-card class="rounded-t-none border-t-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Date</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">DSN</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Num</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Type</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Heures vol</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Pannes</th>
                        @if ($tab === 'erreurs')
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Signalé par</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Signalé le</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($flights as $flight)
                        @php
                            $route = $tab === 'non-vols'
                                ? route('flights.non-vol', $flight)
                                : route('flights.show', $flight);
                            $pannesCount = $flight->conservees_count ?? 0;
                            $pannesBadgeVariant = $pannesCount === 0 ? 'pending'
                                : ($pannesCount > 5 ? 'error' : 'amber');
                        @endphp
                        <tr class="border-b border-app-border-soft hover:bg-app-bg cursor-pointer transition-colors"
                            onclick="window.location='{{ $route }}'">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary">
                                {{ $flight->start_datetime->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $flight->dsn }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary">{{ $flight->num }}</td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($tab === 'erreurs')
                                    <x-badge variant="error">Erreur</x-badge>
                                @elseif ($tab === 'non-vols')
                                    <x-badge variant="nonvol">Non-Vol</x-badge>
                                @else
                                    <span class="text-ink-secondary">Normal</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($tab === 'vols')
                                    {{ number_format($flight->flight_hours, 1) }}h
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($tab === 'vols')
                                    <x-badge :variant="$pannesBadgeVariant">{{ $pannesCount }}</x-badge>
                                @else
                                    <span class="font-mono text-xs text-ink-muted">—</span>
                                @endif
                            </td>
                            @if ($tab === 'erreurs')
                                <td class="px-4 py-2.5 text-xs text-ink-secondary">{{ $flight->flaggedBy?->email ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-ink-secondary font-mono">{{ $flight->flagged_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'erreurs' ? 8 : 6 }}" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun vol dans cet onglet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($flights->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $flights->firstItem() }}–{{ $flights->lastItem() }} de {{ $flights->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($flights->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <a href="{{ $flights->previousPageUrl() }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</a>
                    @endif
                    @if ($flights->hasMorePages())
                        <a href="{{ $flights->nextPageUrl() }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</a>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</x-app-layout>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 verts.

- [ ] **Step 4: Commit**

```bash
git add resources/views/machines/show.blade.php
git commit -m "feat(machines): redesign /machines/{HcId} with KPI boxes, amber tabs, ghost pagination"
```

---

## Task 5: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests**

```bash
npm run build
php artisan test
```

Expected: tout vert, 59 passants.

- [ ] **Step 2: Screenshot Playwright `/machines/NH03`**

Naviguer `http://127.0.0.1:8000/machines/NH03` (machine existante avec des vols, NH03 vu sur les screenshots étape 3). Capture viewport 1440×900.

Vérifier :
- Breadcrumb : bouton ghost "← Machines" en haut
- Header :
  - HcId "NH03" mono ambre 28px
  - "X enregistrement(s)" muted à côté
  - 3 KPI boxes encadrés à droite (Vols / Non-Vols / Erreurs)
- Tabs : Vols / Non-Vols / Erreurs avec count entre parenthèses
  - Onglet Vols actif : `text-accent-pressed` + border-bottom amber
- Tableau :
  - Headers uppercase tracking-wider muted
  - Rows hover bg-app-bg + cursor-pointer
  - Date / DSN / Num en mono
  - Type "Normal" plain text pour vols
  - Heures vol décimal "X.Xh"
  - Badge Pannes color-coded (gris si 0, amber si 1-4, rouge si >5)
- Pagination : "Affichage X–Y de Z" + boutons ghost "← Préc." / "Suiv. →"

- [ ] **Step 3: Cliquer sur l'onglet Non-Vols**

URL devient `/machines/NH03?tab=non-vols`. Vérifier :
- Onglet Non-Vols actif
- Type col affiche `<x-badge variant="nonvol">Non-Vol</x-badge>`
- Heures vol et Pannes affichent "—"

- [ ] **Step 4: Cliquer sur l'onglet Erreurs**

URL devient `/machines/NH03?tab=erreurs`. Vérifier :
- Onglet Erreurs actif
- Type col affiche `<x-badge variant="error">Erreur</x-badge>`
- 2 colonnes additionnelles "Signalé par" / "Signalé le"

- [ ] **Step 5: Cliquer sur une ligne (onglet Vols)**

Navigation vers `/flights/{id}`. Pour cette étape, la page `/flights/{id}` est encore l'ancien design (sera refait à l'étape 5) — c'est attendu.

- [ ] **Step 6: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error.

---

## Notes / pièges connus

- **`<x-counter-pill>` rétrocompatibilité** : la prop `boxed` a une valeur par défaut `false`, donc tous les usages existants à `/machines` continuent de rendre les pills nus inchangés.
- **Tabs underline `text-accent-pressed`** : on utilise `#c49010` (accent-pressed) au lieu de `text-accent` pur (`#f5b731`) pour assurer un meilleur contraste sur fond clair `#eef1f6`. Couleur définie dans `tailwind.config.js` étape 1.
- **`text-accent` sur HcId du header** : OK ici car la taille (28px) compense le contraste plus faible de la couleur amber pure.
- **Heures vol décimal** : format `number_format($flight->flight_hours, 1) . 'h'` → "2.5h", "0.0h", "12.4h". Convention aviation.
- **Lignes onglet Erreurs cliquables** : redirige vers `flights.show` (pas non-vol) car l'utilisateur veut voir le détail du vol mal classé.
- **Pagination disabled** : `opacity-50 cursor-not-allowed` sur les `<span>` (pas `<a>`) quand on est sur la première / dernière page. Pas de href donc inerte.
- **Type "Normal" plain text** : matche le proto qui montre les vols comme "Normal" en text plain ink-secondary, pas un badge.
- **Pas de test Pest dédié** : aucun test ne cible `MachineController@show` actuellement. Si on voulait en ajouter, ce serait un `MachineDetailPageTest` avec `actingAs($user)->get('/machines/NH03')->assertOk()->assertSee('NH03')` et variations sur tabs. YAGNI pour cette étape — la suite existante reste verte et le smoke Playwright valide visuellement.
