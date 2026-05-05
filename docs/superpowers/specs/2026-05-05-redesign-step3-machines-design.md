# Design — Étape 3 / Refonte `/machines`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — section "/machines"

## 1. Contexte

La page `/machines` est l'**écran principal** de l'application : liste des hélicoptères avec compteurs (Vols/Non-Vols/Erreurs) et 2 widgets par hélico (pannes occurrentes actives + pannes du dernier vol). Elle a été modifiée à l'étape pannes-occurrentes pour intégrer la feature, et utilise actuellement les couleurs slate/blue de Breeze.

Cette étape la refait visuellement selon le proto : cards verticales avec dividers, accent amber sur le HcId, badges status semantic, modals avec overlay backdrop-blur et border-left coloré par score. Pas de modification logique côté contrôleur ou modèle.

## 2. Objectifs

- Refondre `resources/views/machines/index.blade.php` selon le proto :
  - Header : section-label "Flotte" + h1 "Machines" + nb hélicos + bouton "Uploader XML" amber.
  - Cards verticales (1 par hélico, gap-3 entre).
  - Card header : HcId mono ambre + nb enregistrements + 3 compteurs séparés par dividers verticaux + badge "Nominal" si 0 erreurs/0 actives + bouton "Voir détail →" ghost.
  - Widgets row : grid 2 colonnes avec divider vertical entre.
- Refondre les 4 partials existants :
  - `widget-recurrent.blade.php` (badge ×score à gauche, color-coded par score).
  - `widget-last-flight.blade.php` (badge ×nombre_occurrences à gauche, color-coded par count).
  - `modal-recurrent.blade.php` (border-left coloré par score, ghost close button).
  - `modal-last-flight.blade.php` (idem).
- Adapter `<x-modal>` Breeze : overlay `bg-ink-primary/50 backdrop-blur-sm`, box `bg-app-card border border-app-border rounded-xl`, max-width 560px.
- Créer `<x-counter-pill>` pour les compteurs (réutilisé étape 4).
- Empty state amber CTA.

**Non-objectifs** :
- Modifications du contrôleur `MachineController@index` (eager-loading et tri restent inchangés).
- Modifications des modèles ou de la BDD.
- Filtre "machines actives" (gardé pour étape 7 `/dashboards`).
- Refonte de `/machines/{HcId}` (étape 4).

## 3. Architecture / fichiers touchés

```
nh-web/
├── resources/views/
│   ├── components/
│   │   ├── modal.blade.php              (modifié — Breeze, classes visuelles uniquement)
│   │   └── counter-pill.blade.php       (NOUVEAU)
│   └── machines/
│       ├── index.blade.php              (refonte complète)
│       └── partials/
│           ├── widget-recurrent.blade.php       (refonte)
│           ├── widget-last-flight.blade.php     (refonte)
│           ├── modal-recurrent.blade.php        (refonte)
│           └── modal-last-flight.blade.php      (refonte)
```

Aucun fichier PHP non-vue n'est touché. Le contrôleur fournit déjà : `$machines` avec `vols_count`, `non_vols_count`, `erreurs_count`, `active_count`, eager loaded `recurrentFailures` (sorted by score desc) et `latestFlight` (avec `technicalEvents` conservée sorted by nombre_occurrences desc).

## 4. Composant `<x-counter-pill>` (NEW)

`resources/views/components/counter-pill.blade.php`

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

Variants :
- `default` : valeur ink-primary (compteur principal, ex Vols)
- `secondary` : valeur ink-secondary (compteur secondaire, ex Non-Vols)
- `danger` : valeur + label en danger (ex Erreurs > 0)

Usage :
```blade
<x-counter-pill value="184" label="Vols" />
<x-counter-pill value="41" label="Non-Vols" variant="secondary" />
<x-counter-pill value="22" label="Erreurs" variant="danger" />
```

## 5. Composant `<x-modal>` adapté

Modification ciblée du wrapper visuel (les comportements Alpine restent inchangés). Les changements concernent uniquement les classes Tailwind appliquées sur l'overlay et la box.

**Overlay** :
- Avant : `<div class="absolute inset-0 bg-gray-500 opacity-75"></div>`
- Après : `<div class="absolute inset-0 bg-ink-primary/50 backdrop-blur-sm"></div>`

**Box** :
- Avant : `class="mb-6 bg-white rounded-lg overflow-hidden shadow-xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"` (where `$maxWidth='sm:max-w-2xl'` for `2xl`)
- Après : `class="mb-6 bg-app-card border border-app-border rounded-xl overflow-hidden shadow-2xl transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto"`

Le `maxWidth` map gagne une nouvelle valeur `'sm' => 'sm:max-w-sm'`, `'md' => 'sm:max-w-md'`, `'lg' => 'sm:max-w-lg'`, `'xl' => 'sm:max-w-xl'`, `'2xl' => 'sm:max-w-[560px]'` (560px au lieu de 672px pour matcher le proto). Les autres tailles restent disponibles si besoin futur.

Affecte aussi : `/profile` delete account modal (sera mieux stylé jusqu'à l'étape 8).

## 6. Page `machines/index.blade.php`

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
            <x-primary-button onclick="window.location='{{ route('upload.index') }}'">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M6 1v7M3 4l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    <path d="M1 9v1.5a.5.5 0 00.5.5h9a.5.5 0 00.5-.5V9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
                Uploader XML
            </x-primary-button>
        </div>
    </div>

    {{-- Empty state --}}
    @if ($machines->isEmpty())
        <x-card class="p-12 text-center">
            <p class="text-ink-muted mb-4">Aucune machine en base.</p>
            <x-primary-button onclick="window.location='{{ route('upload.index') }}'">
                + Uploader un XML
            </x-primary-button>
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

Notes :
- Le bouton "Voir détail →" est inline (pas un composant) car sa structure est différente des composants existants — c'est un `<a>` styled comme un button. On garde `<x-secondary-button>` pour les vrais boutons (au lieu d'utiliser un attribut `href` non-supporté).
- Le badge "Nominal" apparaît uniquement si `erreurs_count == 0 ET active_count == 0` (vraiment rien à signaler).

## 7. Partial `widget-recurrent.blade.php`

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

**Color rule** : score 3 → `error` (red), score 1-2 → `amber` (warn).

## 8. Partial `widget-last-flight.blade.php`

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

**Color rule** : ≥3 → `error`, =2 → `amber`, =1 → `pending` (gray).

## 9. Partial `modal-recurrent.blade.php`

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

## 10. Partial `modal-last-flight.blade.php`

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

## 11. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test` reste vert.
  - `MachinesIndexPageTest` :
    - `it renders /machines with active recurrent failures eager-loaded` → cherche `assertSee('NH50')` + `assertSee('RECURRENT EVENT 1')`. Le HcId reste rendu ; `te_description` est rendu dans le widget → vert.
    - `it renders pannes from the last flight in the row` → cherche `assertSee('NH51')`, `assertSee('LAST FLIGHT EVENT 1')`, `assertSee('NH52')`, `assertSee('LAST FLIGHT EVENT 2')`. Le contenu reste rendu → vert.
- **Smoke Playwright** :
  - Ouvrir `/machines` (connecté).
  - Vérifier visuellement :
    - Header avec "Flotte" section-label + h1 + count + bouton "Uploader XML" amber.
    - Cards verticales avec gap.
    - Card header : HcId mono ambre (cliquable), 3 compteurs séparés par dividers, badge "Nominal" si applicable, bouton "Voir détail →" ghost.
    - Widgets row avec divider central.
    - Items pannes avec badge ×N à gauche color-coded.
    - Cliquer "voir plus" → modal avec overlay backdrop-blur + box border + border-left coloré.
    - Cliquer "Fermer" → modal disparaît.
  - 0 erreur console.

## 12. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 4 | `/machines/{HcId}` (réutilise `<x-counter-pill>`) |
| 5 | `/flights/*` |
| 6 | `/upload` + `/imports` |
| 7 | `/dashboards` (avec filtre machines actives) |
| 8 | `/profile` |
| 9 | `/admin/users` + `/admin/audit-log` |
