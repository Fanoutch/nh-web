# Redesign Étape 3 — `/machines` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre visuellement la page `/machines` selon le proto Claude Design : header avec section-label "Flotte", cards verticales par hélico (HcId mono ambre + compteurs séparés par dividers + badge "Nominal" + bouton "Voir détail →" ghost), widgets row avec divider central, badges color-coded sur les pannes (×score / ×nombre_occurrences), modals avec overlay backdrop-blur et border-left coloré. Créer `<x-counter-pill>`, adapter `<x-modal>` Breeze.

**Architecture:** Aucune modif PHP métier. Touche uniquement les vues : `<x-modal>`, nouveau `<x-counter-pill>`, `machines/index.blade.php` + 4 partials. Les classes Tailwind utilisées (`bg-app-card`, `text-accent`, etc.) sont déjà disponibles depuis l'étape 1 (Foundation).

**Tech Stack:** Laravel 12, Blade, Tailwind CSS, Alpine.js (via Breeze), Pest 3, Playwright MCP pour smoke browser.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step3-machines-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step3-machines-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step3-machines.md` (ce fichier)

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??` (spec et plan).

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step3-machines-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step3-machines.md
git commit -m "docs: add spec and plan for redesign step 3 (/machines)"
```

---

## Task 2: Créer `<x-counter-pill>` (NEW)

**Files:**
- Create: `resources/views/components/counter-pill.blade.php`

- [ ] **Step 1: Créer le fichier avec ce contenu exact**

```blade
@props(['value', 'label', 'variant' => 'default'])

@php
    $valueClass = match ($variant) {
        'danger'    => 'text-danger',
        'secondary' => 'text-ink-secondary',
        default     => 'text-ink-primary',
    };
    $labelClass = $variant === 'danger' ? 'text-danger' : 'text-ink-muted';
@endphp

<div class="flex flex-col items-center">
    <p class="font-mono text-lg font-medium tabular-nums leading-none {{ $valueClass }}">{{ $value }}</p>
    <p class="text-[10px] uppercase tracking-wide font-medium mt-1 {{ $labelClass }}">{{ $label }}</p>
</div>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès, aucune erreur sur `text-danger` / `text-ink-secondary` / `text-ink-primary` / `text-ink-muted` (tokens étape 1).

- [ ] **Step 3: Commit (uniquement le nouveau fichier)**

```bash
git add resources/views/components/counter-pill.blade.php
git commit -m "feat(components): add x-counter-pill for value+label triplets"
```

---

## Task 3: Adapter `<x-modal>` Breeze (overlay + box visuels)

**Files:**
- Modify: `resources/views/components/modal.blade.php`

- [ ] **Step 1: Lire le fichier actuel pour identifier les 2 lignes à modifier**

```bash
cat resources/views/components/modal.blade.php
```

Identifier :
- Ligne ~14 : la map `$maxWidth` qui définit `'2xl' => 'sm:max-w-2xl'`
- Ligne ~63 : `<div class="absolute inset-0 bg-gray-500 opacity-75"></div>`
- Ligne ~68 : `class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl ...{{ $maxWidth }} sm:mx-auto"`

- [ ] **Step 2: Modifier la map `$maxWidth`**

Remplacer la map dans le bloc `@php` :

```php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
][$maxWidth];
```

par :

```php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-[560px]',
][$maxWidth];
```

(`2xl` passe à 560px pour matcher le proto.)

- [ ] **Step 3: Modifier l'overlay**

Remplacer :

```blade
<div class="absolute inset-0 bg-gray-500 opacity-75"></div>
```

par :

```blade
<div class="absolute inset-0 bg-ink-primary/50 backdrop-blur-sm"></div>
```

- [ ] **Step 4: Modifier la box**

Remplacer la classe de la box (la deuxième `<div x-show="show">` qui contient le slot) :

```
class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
```

par :

```
class="mb-6 bg-app-card border border-app-border rounded-xl overflow-hidden shadow-2xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"
```

- [ ] **Step 5: Build Vite**

```bash
npm run build
```

Expected: succès, classes `bg-ink-primary/50`, `bg-app-card`, `border-app-border` reconnues.

- [ ] **Step 6: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 verts.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/modal.blade.php
git commit -m "refactor(modal): apply redesign palette to x-modal wrapper (overlay + box)"
```

---

## Task 4: Refondre `widget-recurrent.blade.php`

**Files:**
- Modify: `resources/views/machines/partials/widget-recurrent.blade.php`

- [ ] **Step 1: Remplacer le contenu complet**

```blade
@php
    $actives = $machine->recurrentFailures;
    $count = $actives->count();
    $top = $actives->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-recurrent-{$machine->hc_id}";
@endphp

<div class="px-5 py-4 flex flex-col">
    <div class="flex items-center justify-between mb-3">
        <x-section-label>Pannes occurrentes actives</x-section-label>
        <div class="flex items-center gap-3">
            <span class="text-sm font-mono font-semibold text-ink-secondary tabular-nums">{{ $count }}</span>
            @if ($rest > 0)
                <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                        class="text-[11px] text-accent hover:text-accent-pressed font-medium transition-colors">
                    voir plus
                </button>
            @endif
        </div>
    </div>

    @if ($count === 0)
        <p class="text-[11px] text-ink-muted italic py-2">— Aucune panne occurrente active —</p>
    @else
        <div class="flex flex-col gap-1.5">
            @foreach ($top as $rf)
                @php $badgeVariant = $rf->score >= 3 ? 'error' : 'amber'; @endphp
                <div class="flex items-center gap-2 px-2 py-1.5 bg-app-bg rounded">
                    <x-badge :variant="$badgeVariant" class="shrink-0">×{{ $rf->score }}</x-badge>
                    <div class="min-w-0">
                        <div class="text-xs text-ink-primary truncate" title="{{ $rf->te_description ?? $rf->technical_event_id }}">
                            {{ $rf->te_description ?? $rf->technical_event_id }}
                        </div>
                        <div class="text-[10px] text-ink-muted font-mono truncate">
                            {{ $rf->system_description ?? '—' }} · {{ $rf->type_description ?? '—' }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($rest > 0)
            @include('machines.partials.modal-recurrent', ['machine' => $machine, 'actives' => $actives, 'modalId' => $modalId])
        @endif
    @endif
</div>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit**

```bash
git add resources/views/machines/partials/widget-recurrent.blade.php
git commit -m "feat(widgets): redesign recurrent failures widget with score-coded badges"
```

---

## Task 5: Refondre `widget-last-flight.blade.php`

**Files:**
- Modify: `resources/views/machines/partials/widget-last-flight.blade.php`

- [ ] **Step 1: Remplacer le contenu complet**

```blade
@php
    $lastFlight = $machine->latestFlight;
    $pannes = $lastFlight?->technicalEvents ?? collect();
    $count = $pannes->count();
    $top = $pannes->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-last-flight-{$machine->hc_id}";
    $flightDate = $lastFlight?->start_datetime?->format('d/m/Y');
@endphp

<div class="px-5 py-4 flex flex-col">
    <div class="flex items-start justify-between mb-3">
        <div>
            <x-section-label>Pannes du dernier vol</x-section-label>
            @if ($flightDate)
                <div class="text-[10px] text-ink-muted font-mono mt-0.5">vol du {{ $flightDate }}</div>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm font-mono font-semibold text-ink-secondary tabular-nums">{{ $count }}</span>
            @if ($rest > 0)
                <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                        class="text-[11px] text-accent hover:text-accent-pressed font-medium transition-colors">
                    voir plus
                </button>
            @endif
        </div>
    </div>

    @if ($count === 0)
        <p class="text-[11px] text-ink-muted italic py-2">— Aucune panne sur le dernier vol —</p>
    @else
        <div class="flex flex-col gap-1.5">
            @foreach ($top as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                    $occ = $te->nombre_occurrences;
                    $badgeVariant = $occ >= 3 ? 'error' : ($occ === 2 ? 'amber' : 'pending');
                @endphp
                <div class="flex items-center gap-2 px-2 py-1.5 bg-app-bg rounded">
                    <x-badge :variant="$badgeVariant" class="shrink-0">×{{ $occ }}</x-badge>
                    <div class="min-w-0">
                        <div class="text-xs text-ink-primary truncate" title="{{ $teDesc }}">{{ $teDesc }}</div>
                        <div class="text-[10px] text-ink-muted font-mono truncate">
                            {{ $sysDesc }} · {{ $typeDesc }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($rest > 0)
            @include('machines.partials.modal-last-flight', [
                'machine' => $machine,
                'flightDate' => $flightDate,
                'pannes' => $pannes,
                'modalId' => $modalId,
            ])
        @endif
    @endif
</div>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit**

```bash
git add resources/views/machines/partials/widget-last-flight.blade.php
git commit -m "feat(widgets): redesign last-flight widget with occurrence-coded badges"
```

---

## Task 6: Refondre `modal-recurrent.blade.php`

**Files:**
- Modify: `resources/views/machines/partials/modal-recurrent.blade.php`

- [ ] **Step 1: Remplacer le contenu complet**

```blade
<x-modal name="{{ $modalId }}" maxWidth="2xl">
    {{-- Header --}}
    <div class="px-5 py-4 border-b border-app-border flex items-start justify-between">
        <div>
            <x-section-label class="mb-0.5">{{ $machine->hc_id }}</x-section-label>
            <h2 class="text-[15px] font-semibold text-ink-primary">Pannes occurrentes actives</h2>
        </div>
        <button x-on:click="$dispatch('close')"
                class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
    </div>

    {{-- Body --}}
    <div class="p-5 flex flex-col gap-2 max-h-[60vh] overflow-y-auto">
        @foreach ($actives as $rf)
            @php
                $borderColor = $rf->score >= 3 ? 'border-l-danger' : 'border-l-accent';
                $badgeVariant = $rf->score >= 3 ? 'error' : 'amber';
            @endphp
            <div class="px-3.5 py-3 bg-app-bg rounded-md border-l-[3px] {{ $borderColor }}">
                <div class="flex items-center justify-between mb-1.5">
                    <x-badge :variant="$badgeVariant">Score {{ $rf->score }}/3</x-badge>
                    <span class="font-mono text-[10px] text-ink-muted">
                        {{ $rf->system_description ?? '—' }} · {{ $rf->type_description ?? '—' }}
                    </span>
                </div>
                <div class="text-[13px] font-medium text-ink-primary">
                    {{ $rf->te_description ?? $rf->technical_event_id }}
                </div>
                @if ($rf->description)
                    <div class="text-[11px] text-ink-muted mt-1">{{ $rf->description }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="px-5 py-3 border-t border-app-border-soft flex justify-end">
        <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
    </div>
</x-modal>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès. Les classes `border-l-danger`, `border-l-accent`, `border-l-app-border` doivent exister (Tailwind génère `border-l-{color}` automatiquement à partir des couleurs étendues).

- [ ] **Step 3: Commit**

```bash
git add resources/views/machines/partials/modal-recurrent.blade.php
git commit -m "feat(modals): redesign recurrent failures modal with proto layout"
```

---

## Task 7: Refondre `modal-last-flight.blade.php`

**Files:**
- Modify: `resources/views/machines/partials/modal-last-flight.blade.php`

- [ ] **Step 1: Remplacer le contenu complet**

```blade
<x-modal name="{{ $modalId }}" maxWidth="2xl">
    {{-- Header --}}
    <div class="px-5 py-4 border-b border-app-border flex items-start justify-between">
        <div>
            <x-section-label class="mb-0.5">{{ $machine->hc_id }} · {{ $flightDate }}</x-section-label>
            <h2 class="text-[15px] font-semibold text-ink-primary">Pannes du dernier vol</h2>
        </div>
        <button x-on:click="$dispatch('close')"
                class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
    </div>

    {{-- Body --}}
    <div class="p-5 flex flex-col gap-2 max-h-[60vh] overflow-y-auto">
        @foreach ($pannes as $te)
            @php
                $d = is_array($te->details) ? $te->details : [];
                $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                $desc = $d['Description'] ?? '';
                $sysDesc = $d['SystemDescription'] ?? '';
                $typeDesc = $d['TypeDescription'] ?? '';
                $occ = $te->nombre_occurrences;
                $borderColor = $occ >= 3 ? 'border-l-danger' : ($occ === 2 ? 'border-l-accent' : 'border-l-app-border');
                $badgeVariant = $occ >= 3 ? 'error' : ($occ === 2 ? 'amber' : 'pending');
            @endphp
            <div class="px-3.5 py-3 bg-app-bg rounded-md border-l-[3px] {{ $borderColor }}">
                <div class="flex items-center justify-between mb-1.5">
                    <x-badge :variant="$badgeVariant">×{{ $occ }} occurrence{{ $occ > 1 ? 's' : '' }}</x-badge>
                    <span class="font-mono text-[10px] text-ink-muted">{{ $sysDesc }} · {{ $typeDesc }}</span>
                </div>
                <div class="text-[13px] font-medium text-ink-primary">{{ $teDesc }}</div>
                @if ($desc)
                    <div class="text-[11px] text-ink-muted mt-1">{{ $desc }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="px-5 py-3 border-t border-app-border-soft flex justify-end">
        <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
    </div>
</x-modal>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit**

```bash
git add resources/views/machines/partials/modal-last-flight.blade.php
git commit -m "feat(modals): redesign last-flight modal with proto layout"
```

---

## Task 8: Refondre `machines/index.blade.php`

**Files:**
- Modify: `resources/views/machines/index.blade.php`

- [ ] **Step 1: Remplacer le contenu complet**

```blade
<x-app-layout>
    {{-- Header --}}
    <div class="flex items-end justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Flotte</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Machines</h1>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-ink-muted">{{ $machines->count() }} hélicoptère(s)</span>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M6 1v7M3 4l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    <path d="M1 9v1.5a.5.5 0 00.5.5h9a.5.5 0 00.5-.5V9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
                Uploader XML
            </a>
        </div>
    </div>

    {{-- Empty state --}}
    @if ($machines->isEmpty())
        <x-card class="p-12 text-center">
            <p class="text-ink-muted mb-4">Aucune machine en base.</p>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover transition">
                + Uploader un XML
            </a>
        </x-card>
    @else
        {{-- Cards machines --}}
        <div class="flex flex-col gap-3">
            @foreach ($machines as $m)
                @php
                    $erreursVariant = $m->erreurs_count > 0 ? 'danger' : 'secondary';
                    $isNominal = $m->erreurs_count === 0 && $m->active_count === 0;
                @endphp
                <x-card>
                    {{-- Header --}}
                    <div class="px-5 py-3.5 border-b border-app-border-soft flex items-center gap-5">
                        {{-- HcId block --}}
                        <a href="{{ route('machines.show', $m->hc_id) }}"
                           class="w-[200px] shrink-0 group">
                            <div class="font-mono text-base font-medium text-accent group-hover:text-accent-pressed transition-colors">
                                {{ $m->hc_id }}
                            </div>
                            <div class="text-[11px] text-ink-muted mt-0.5">
                                {{ $m->vols_count + $m->non_vols_count + $m->erreurs_count }} enregistrement(s)
                            </div>
                        </a>

                        {{-- Compteurs avec dividers verticaux --}}
                        <div class="flex items-center gap-4">
                            <x-counter-pill :value="$m->vols_count" label="Vols" />
                            <div class="w-px h-8 bg-app-border-soft"></div>
                            <x-counter-pill :value="$m->non_vols_count" label="Non-Vols" variant="secondary" />
                            <div class="w-px h-8 bg-app-border-soft"></div>
                            <x-counter-pill :value="$m->erreurs_count" label="Erreurs" :variant="$erreursVariant" />
                        </div>

                        <div class="flex-1"></div>

                        @if ($isNominal)
                            <x-badge variant="ok">Nominal</x-badge>
                        @endif

                        <a href="{{ route('machines.show', $m->hc_id) }}"
                           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
                            Voir détail →
                        </a>
                    </div>

                    {{-- Widgets row --}}
                    <div class="grid grid-cols-2 divide-x divide-app-border-soft">
                        @include('machines.partials.widget-recurrent', ['machine' => $m])
                        @include('machines.partials.widget-last-flight', ['machine' => $m])
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
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

Expected: 59 verts. Le `MachinesIndexPageTest` cherche les textes "NH50", "RECURRENT EVENT 1", "NH51", "LAST FLIGHT EVENT 1", "NH52", "LAST FLIGHT EVENT 2" — tous toujours rendus.

- [ ] **Step 4: Commit**

```bash
git add resources/views/machines/index.blade.php
git commit -m "feat(machines): redesign /machines page with cards, dividers and amber accents"
```

---

## Task 9: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests**

```bash
npm run build
php artisan test
```

Expected: tout vert, 59 passants.

- [ ] **Step 2: Screenshot Playwright `/machines`**

Naviguer `http://127.0.0.1:8000/machines` (connecté). Capture viewport 1440×900.

Vérifier visuellement :
- Header : section-label "Flotte" + h1 "Machines" + count mono à droite + bouton "Uploader XML" amber
- Cards verticales (gap entre elles)
- Card header :
  - HcId mono ambre 16px (cliquable, hover devient accent-pressed)
  - 3 compteurs (Vols / Non-Vols / Erreurs) séparés par dividers verticaux
  - Erreurs > 0 → mono color danger ; sinon ink-secondary
  - Badge "Nominal" (ok green) si erreurs=0 et actives=0
  - Bouton "Voir détail →" ghost à droite
- Widgets row avec divider central (border-x interne)
- Items pannes : badge ×N à gauche color-coded (rouge/amber/pending), description tronquée, system mono underneath
- "voir plus" en haut à droite du widget si > 3 items, color amber

- [ ] **Step 3: Tester le modal**

Cliquer "voir plus" sur un widget. Vérifier :
- Overlay sombre + backdrop-blur visible
- Box `bg-app-card` avec border et rounded-xl, max-width 560px
- Header avec section-label HcId + titre + bouton X
- Items avec border-left coloré (rouge si score 3, amber si <3 pour widget recurrent ; pareil avec border-l-app-border si score 1 pour last-flight)
- Footer avec bouton "Fermer" ghost

Cliquer "Fermer" → modal disparaît.
Cliquer en dehors (overlay) → modal disparaît.
Touche Escape → modal disparaît.

- [ ] **Step 4: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error.

---

## Notes / pièges connus

- **`<x-modal>` partagé** : la modif affecte aussi `/profile` delete account (sera mieux stylé jusqu'à étape 8). Comportement Alpine inchangé, juste classes visuelles.
- **`<x-counter-pill>`** : sera réutilisé étape 4 dans `/machines/{HcId}` pour les KPI boxes du header.
- **Color rules pour les badges** :
  - Widget recurrent : score 3 → `error`, score 1-2 → `amber`
  - Widget last-flight : ≥3 → `error`, =2 → `amber`, =1 → `pending`
  - Modal recurrent : border-left rouge si score 3, amber sinon
  - Modal last-flight : border-left rouge si ≥3, amber si =2, app-border si =1
- **Tailwind classes dynamiques** : les classes `border-l-{color}` sont construites dynamiquement via PHP variables. Tailwind doit pouvoir les détecter via le glob `content` (le fichier `.blade.php` est dans `resources/views/**`). Si Tailwind les purge, ajouter `safelist` dans `tailwind.config.js` — peu probable mais à vérifier au build.
- **Bouton "Voir détail →"** : c'est un `<a>` styled comme un button (pas `<x-secondary-button>` car ce composant rend un `<button>` qui ne supporte pas `href`). Les classes sont copiées du composant pour cohérence.
- **Badge "Nominal"** : affiché uniquement si `erreurs_count == 0 ET active_count == 0` (le `active_count` est déjà eager-loadé par le contrôleur).
- **Empty state** : utilise `<a>` styled comme un button (même logique que "Voir détail").
- **Accent color sur HcId** : clic sur le HcId block (incluant le sous-texte enregistrements) navigue vers `/machines/{HcId}`. Le bouton "Voir détail →" est redondant mais le proto le montre. On garde les deux pour rester proche du proto.
