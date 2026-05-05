# Design — Étape 4 / Refonte `/machines/{HcId}`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — section "/machines/{HcId}"

## 1. Contexte

Page détail d'un hélicoptère : breadcrumb + header HcId + 3 onglets (Vols / Non-Vols / Erreurs) + tableau paginé. Actuellement stylée avec slate/blue de Breeze. Cette étape la refait selon le proto avec accent amber, KPI boxes encadrés, tabs underline amber, badges Pannes color-coded, pagination ghost custom.

Réutilise `<x-counter-pill>` créé étape 3, qu'on étend avec un prop `boxed` pour un rendu encadré (KPI box) au lieu du pill nu utilisé sur /machines.

## 2. Objectifs

- Étendre `<x-counter-pill>` avec un prop `boxed` (false par défaut). Sur /machines : `boxed=false` (inchangé). Sur /machines/{HcId} : `boxed=true` (avec wrapper `bg-app-card border border-app-border rounded-md px-4 py-1.5`, ou variante danger avec `bg-danger-soft border-danger-border` si `variant=danger`).
- Refondre `resources/views/machines/show.blade.php` :
  - Breadcrumb : bouton ghost "← Machines" (style `<x-secondary-button>`).
  - Header : HcId mono ambre 28px + "X enregistrement(s)" muted + 3 KPI boxes à droite.
  - Tabs : underline `border-accent` sur active, text `text-accent-pressed` ; counts entre parenthèses (ex `Vols (184)`).
  - Tableau : Date mono / DSN mono / Num mono / Type (text plain pour vols, badge `nonvol` ou `error` sinon) / Heures vol décimal "2.5h" / Pannes badge color-coded ; lignes cliquables, hover `bg-app-bg`.
  - Pagination ghost custom : "Affichage X–Y de Z" + boutons "← Préc." / "Suiv. →".
- Modifier `MachineController@show` : déplacer le calcul de `$counts` (actuellement inline dans la vue) dans le contrôleur ; ajouter `$totalCount`.

**Non-objectifs** :
- Modifier le modèle `Flight` ou la migration.
- Ajouter de nouvelles fonctionnalités (filtre, tri custom).
- Refonte de `/flights/{id}` ou des sous-pages (étape 5).
- Modifications de la pagination Laravel par défaut au niveau global (juste cette page pour l'instant).

## 3. Architecture / fichiers touchés

```
nh-web/
├── app/Http/Controllers/MachineController.php       (modifié — show())
└── resources/views/
    ├── components/counter-pill.blade.php             (étendu — prop boxed)
    └── machines/show.blade.php                       (refonte complète)
```

3 fichiers. Aucun nouveau composant créé.

## 4. Composant `<x-counter-pill>` étendu

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

Pas de breaking change : les usages existants (`<x-counter-pill :value :label />`) gardent le rendu actuel (boxed=false).

## 5. Contrôleur `MachineController@show`

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

Différences vs actuel :
- `$counts` calculé dans le contrôleur (au lieu de inline `@php` dans la vue).
- `$totalCount` ajouté (utilisé dans le header).
- `$flights` est inchangé (paginate avec withCount).

## 6. Vue `machines/show.blade.php`

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

## 7. Détails de design

- **Breadcrumb** : bouton ghost simple "← Machines" (matche le proto qui montre `← Machines` button-style en haut).
- **Header HcId** : font-mono `text-[28px]` ambre, baseline-aligned avec "X enregistrement(s)" muted.
- **KPI boxes** : 3 `<x-counter-pill boxed>` à droite. Le 3e (Erreurs) en variant `danger` si > 0, sinon `secondary`.
- **Tabs** : `text-accent-pressed` (couleur amber pressée `#c49010`) sur l'onglet actif pour meilleur contraste sur fond clair (le pure `text-accent` `#f5b731` est trop pâle pour du texte sur fond `#eef1f6`). Border-bottom amber.
- **Lignes tableau cliquables** : `cursor-pointer` + `onclick="window.location=..."`. Hover `bg-app-bg` (gris très clair). Border-bottom `border-app-border-soft`.
- **Type column** :
  - Vols : `<span class="text-ink-secondary">Normal</span>` (text plain)
  - Non-Vols : `<x-badge variant="nonvol">Non-Vol</x-badge>`
  - Erreurs : `<x-badge variant="error">Erreur</x-badge>`
- **Heures vol / Pannes** : "—" pour non-vols et erreurs (pas pertinent). Décimal "2.5h" + badge color-coded pour vols.
- **Lignes onglet Erreurs** : cliquables vers `flights.show` (l'utilisateur veut voir le vol mal classé pour comprendre).
- **Pagination** : disabled state avec `opacity-50 cursor-not-allowed` sur les liens "Préc." / "Suiv." quand respectivement on est sur la première / dernière page.

## 8. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test` reste vert. Aucun test ne cible la page show actuellement (la suite Pest existante teste login, machines index, ProcessXmlJob, etc.).
- **Smoke Playwright** :
  - `/machines/NH03` (machine existante avec des vols).
  - Vérifier :
    - Breadcrumb bouton "← Machines" ghost
    - Header : NH03 mono ambre 28px + "X enregistrement(s)" muted + 3 KPI boxes encadrés à droite
    - Tabs : Vols / Non-Vols / Erreurs avec border-bottom amber sur Vols (active)
    - Tableau : colonnes Date/DSN/Num en mono, Type "Normal" plain text, Heures vol décimal, badge Pannes color-coded
    - Pagination en bas avec "Affichage X–Y de Z" + boutons ghost
  - Cliquer sur l'onglet Non-Vols → URL avec `?tab=non-vols`, badge Type devient "Non-Vol".
  - Cliquer sur une ligne du tableau → navigue vers le détail vol.
  - 0 erreur console.

## 9. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 5 | `/flights/*` (show + 3 sous-pages) |
| 6 | `/upload` + `/imports` |
| 7 | `/dashboards` |
| 8 | `/profile` |
| 9 | `/admin/users` + `/admin/audit-log` |

Pagination Laravel globale : sera customisée à l'étape 9 (admin) où plusieurs pages paginent. Pour cette étape, pagination inline dans show uniquement.
