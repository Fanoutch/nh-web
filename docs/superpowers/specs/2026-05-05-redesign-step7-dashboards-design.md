# Design — Étape 7 / Refonte `/dashboards`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — section `/dashboards`

## 1. Contexte

La page `/dashboards` actuelle propose un seul chart : line chart "Pannes + Heures de vol par semaine" pour UNE machine sur une plage de dates. Le proto la transforme en véritable tableau de bord flotte avec 4 KPIs globaux + chart mensuel multi-machines + widget validation par appareil. La page change de comportement selon l'état des filtres : vue overview par défaut, vue drill-down quand une machine + dates sont sélectionnés.

## 2. Objectifs

- Refondre `/dashboards` en deux vues conditionnelles :
  - **Vue défaut** (aucune machine sélectionnée) : 4 KPI cards + chart "Heures de vol par mois × machines actives (≥3 vols/30j)" + widget validation par appareil.
  - **Vue filtrée** (machine + dates sélectionnés) : mêmes 4 KPI cards + chart "Pannes + FH par semaine pour la machine sélectionnée" (le chart actuel, restylé).
- Ajouter un scope `Machine::scopeActive()` réutilisable (≥ 3 vols réels sur 30 jours glissants).
- Restyler header + filtres (select machine compact + date range) selon le proto.
- Restyler le chart ApexCharts existant (couleurs, fonts, fond) avec la palette redesign.

**Non-objectifs** :
- Trend arrows ("↑ +12% ce mois") sur les KPIs — YAGNI, juste les totaux.
- Charts interactifs (drill-down par clic, tooltips custom) au-delà de ce que ApexCharts fournit par défaut.
- Comparatifs multi-périodes.
- Export du dashboard.

## 3. Architecture / fichiers touchés

```
nh-web/
├── app/
│   ├── Livewire/DashboardChart.php         (refonte majeure)
│   └── Models/Machine.php                   (ajouter scope active)
└── resources/views/
    ├── dashboards.blade.php                 (refonte header)
    └── livewire/dashboard-chart.blade.php   (refonte complète : 2 vues conditionnelles)
```

4 fichiers : 2 PHP (Livewire + Model) + 2 Blade. Pas de nouveau composant Blade.

## 4. Composant `Machine::scopeActive`

Définition : machine ayant **≥ 3 vols réels** (`is_non_vol=false` AND `flagged_as_error=false`) sur les **30 derniers jours glissants**.

```php
// app/Models/Machine.php
public function scopeActive($query, int $threshold = 3, int $days = 30)
{
    return $query->whereHas('flights', function ($q) use ($days) {
        $q->where('is_non_vol', false)
          ->where('flagged_as_error', false)
          ->where('start_datetime', '>=', now()->subDays($days));
    }, '>=', $threshold);
}
```

Réutilisable pour le widget validation et le chart mensuel.

## 5. Composant Livewire `DashboardChart` refondu

```php
namespace App\Livewire;

use App\Models\Flight;
use App\Models\Import;
use App\Models\Machine;
use App\Models\MissingPanne;
use App\Models\TechnicalEvent;
use App\Models\WeeklyAggregate;
use Carbon\Carbon;
use Livewire\Component;

class DashboardChart extends Component
{
    public ?int $machineId = null;
    public ?string $startDate = null;
    public ?string $endDate = null;

    // Vue détaillée (machine sélectionnée) : data du chart hebdo
    public array $chartData = [];
    public string $chartTitle = '';

    public function displayChart(): void
    {
        $this->validate([
            'machineId' => 'required|exists:machines,id',
            'startDate' => 'required|date',
            'endDate'   => 'required|date|after_or_equal:startDate',
        ]);

        $machine = Machine::findOrFail($this->machineId);
        $weekStart = Carbon::parse($this->startDate)->isoFormat('GGGG-[W]WW');
        $weekEnd   = Carbon::parse($this->endDate)->isoFormat('GGGG-[W]WW');

        $aggs = WeeklyAggregate::where('machine_id', $machine->id)
            ->whereBetween('iso_week', [$weekStart, $weekEnd])
            ->orderBy('iso_week')
            ->get()
            ->keyBy('iso_week');

        $weeks = $this->generateAllWeeks($weekStart, $weekEnd);

        $pannes = [];
        $fh = [];
        $noData = [];
        foreach ($weeks as $w) {
            $row = $aggs->get($w);
            $pannesVal = (int) ($row?->total_pannes ?? 0);
            $fhVal = (float) ($row?->total_flight_hours ?? 0);
            $pannes[] = $pannesVal;
            $fh[] = $fhVal;
            if (!$row || ($pannesVal === 0 && $fhVal == 0)) {
                $noData[] = $w;
            }
        }

        $this->chartTitle = "{$machine->hc_id} — {$this->startDate} → {$this->endDate}";
        $this->chartData = compact('weeks', 'pannes', 'fh', 'noData') + ['title' => $this->chartTitle];
        $this->dispatch('chart-data-updated', data: $this->chartData);
    }

    public function clearFilter(): void
    {
        $this->machineId = null;
        $this->startDate = null;
        $this->endDate = null;
        $this->chartData = [];
        $this->chartTitle = '';
        $this->dispatch('chart-data-updated', data: []);
    }

    private function generateAllWeeks(string $start, string $end): array
    {
        $weeks = [];
        $cursor = Carbon::now()->setISODate((int) substr($start, 0, 4), (int) substr($start, 6, 2))->startOfWeek();
        $endCursor = Carbon::now()->setISODate((int) substr($end, 0, 4), (int) substr($end, 6, 2))->startOfWeek();
        while ($cursor->lte($endCursor)) {
            $weeks[] = $cursor->isoFormat('GGGG-[W]WW');
            $cursor->addWeek();
        }
        return $weeks;
    }

    /** Données pour les 4 KPIs (toujours visibles). */
    public function getKpisProperty(): array
    {
        $totalConservees = TechnicalEvent::where('status', 'conservee')->count();
        $validated = TechnicalEvent::where('status', 'conservee')->where('validation_status', 'validated')->count();

        return [
            'total_vols'        => Flight::where('is_non_vol', false)->where('flagged_as_error', false)->count(),
            'total_conservees'  => $totalConservees,
            'taux_validation'   => $totalConservees > 0 ? (int) round($validated / $totalConservees * 100) : 0,
            'erreurs_pipeline'  => Import::where('status', 'error')->count(),
        ];
    }

    /** Chart mensuel multi-machines (vue défaut). */
    public function getMonthlyDataProperty(): array
    {
        // Fenêtre : 4 derniers mois calendaires
        $end = Carbon::now()->endOfMonth();
        $start = Carbon::now()->subMonths(3)->startOfMonth();
        $startWeek = $start->isoFormat('GGGG-[W]WW');
        $endWeek   = $end->isoFormat('GGGG-[W]WW');

        $activeMachines = Machine::active()->orderBy('hc_id')->get();
        if ($activeMachines->isEmpty()) {
            return ['months' => [], 'series' => []];
        }

        $aggs = WeeklyAggregate::whereIn('machine_id', $activeMachines->pluck('id'))
            ->whereBetween('iso_week', [$startWeek, $endWeek])
            ->get();

        // Bucketize par mois calendaire (jeudi de la semaine ISO = mois canonique)
        $buckets = []; // [machine_id][YYYY-MM] => sum FH
        foreach ($aggs as $a) {
            $year = (int) substr($a->iso_week, 0, 4);
            $week = (int) substr($a->iso_week, 6, 2);
            $monthKey = Carbon::now()->setISODate($year, $week)->startOfWeek()->addDays(3)->format('Y-m');
            $buckets[$a->machine_id][$monthKey] = ($buckets[$a->machine_id][$monthKey] ?? 0) + (float) $a->total_flight_hours;
        }

        // Liste de mois de la fenêtre, triée
        $months = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        // Couleurs cyclées (5 explicites pour les premières machines actives, ApexCharts default ensuite)
        $palette = ['#f5b731', '#3dcb7c', '#f05a3a', '#8b95ad', '#a78bfa', '#5fb6e8', '#e87fbe'];

        $series = [];
        foreach ($activeMachines->values() as $i => $m) {
            $series[] = [
                'name'  => $m->hc_id,
                'data'  => array_map(fn ($mk) => round($buckets[$m->id][$mk] ?? 0, 1), $months),
                'color' => $palette[$i % count($palette)],
            ];
        }

        return [
            'months' => array_map(fn ($mk) => Carbon::createFromFormat('Y-m', $mk)->isoFormat('MMM YYYY'), $months),
            'series' => $series,
        ];
    }

    /** Données du widget "Statuts validation par appareil" (vue défaut). */
    public function getValidationBreakdownProperty(): array
    {
        $machines = Machine::active()->orderBy('hc_id')->get();

        return $machines->map(function ($m) {
            $conservees = TechnicalEvent::whereHas('flight', fn ($q) => $q->where('machine_id', $m->id))
                ->where('status', 'conservee');

            $val = (clone $conservees)->where('validation_status', 'validated')->count();
            $rej = (clone $conservees)->where('validation_status', 'rejected')->count();
            $manq = MissingPanne::whereHas('flight', fn ($q) => $q->where('machine_id', $m->id))->count();
            $total = $val + $rej + $manq;

            return [
                'hc_id'     => $m->hc_id,
                'validated' => $val,
                'rejected'  => $rej,
                'missing'   => $manq,
                'total'     => $total,
                'pct_v'     => $total > 0 ? round($val / $total * 100) : 0,
                'pct_r'     => $total > 0 ? round($rej / $total * 100) : 0,
                'pct_m'     => $total > 0 ? round($manq / $total * 100) : 0,
            ];
        })->all();
    }

    public function render()
    {
        return view('livewire.dashboard-chart', [
            'machines'             => Machine::orderBy('hc_id')->get(),
            'kpis'                 => $this->kpis,
            'monthlyData'          => $this->monthlyData,
            'validationBreakdown'  => $this->validationBreakdown,
            'isFiltered'           => !empty($this->chartData) && $this->machineId !== null,
        ]);
    }
}
```

Notes :
- `kpis` calculés à chaque render (4 simples queries COUNT) — peu coûteux.
- `monthlyData` et `validationBreakdown` calculés seulement si vue défaut → mais Livewire évalue toujours les `getXxxProperty` à chaque render. Si performance pose problème, on cachera plus tard.
- `MissingPanne` n'a pas forcément de relation `flight()` directement — vérifier le modèle. Si besoin, fallback vers `flight_id` direct.

## 6. Vue `dashboards.blade.php`

```blade
<x-app-layout>
    <div class="flex items-end justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Analytique</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Dashboards</h1>
        </div>
    </div>
    <livewire:dashboard-chart />
</x-app-layout>
```

## 7. Vue `livewire/dashboard-chart.blade.php` refondue

```blade
<div>
    {{-- Filtres compacts --}}
    <div class="flex items-center gap-3 mb-5 flex-wrap">
        <select wire:model.live="machineId"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Tous les hélicos</option>
            @foreach ($machines as $m)
                <option value="{{ $m->id }}">{{ $m->hc_id }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-2 bg-app-card border border-app-border rounded-md px-3 py-1">
            <input type="date" wire:model.live="startDate"
                   class="bg-transparent border-0 text-ink-primary font-mono text-xs px-0 py-1 focus:outline-none focus:ring-0 w-32" />
            <span class="text-ink-muted text-sm">→</span>
            <input type="date" wire:model.live="endDate"
                   class="bg-transparent border-0 text-ink-primary font-mono text-xs px-0 py-1 focus:outline-none focus:ring-0 w-32" />
        </div>
        @if ($machineId && $startDate && $endDate)
            <x-primary-button wire:click="displayChart">
                Afficher
            </x-primary-button>
            <x-secondary-button wire:click="clearFilter">
                ✕ Réinitialiser
            </x-secondary-button>
        @endif
        @error('machineId')<span class="text-xs text-danger">{{ $message }}</span>@enderror
        @error('startDate')<span class="text-xs text-danger">{{ $message }}</span>@enderror
        @error('endDate')<span class="text-xs text-danger">{{ $message }}</span>@enderror
    </div>

    {{-- KPI cards (toujours visibles) --}}
    <div class="grid grid-cols-4 gap-3 mb-5">
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Total vols</x-section-label>
            <div class="font-mono text-[28px] font-medium text-ink-primary tabular-nums">{{ $kpis['total_vols'] }}</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Pannes conservées</x-section-label>
            <div class="font-mono text-[28px] font-medium text-accent tabular-nums">{{ number_format($kpis['total_conservees'], 0, ',', ' ') }}</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Taux validation</x-section-label>
            <div class="font-mono text-[28px] font-medium text-ok tabular-nums">{{ $kpis['taux_validation'] }}%</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Erreurs pipeline</x-section-label>
            <div class="font-mono text-[28px] font-medium text-danger tabular-nums">{{ $kpis['erreurs_pipeline'] }}</div>
        </x-card>
    </div>

    @if ($isFiltered)
        {{-- Vue filtrée : chart pannes + FH par semaine pour 1 machine --}}
        <x-card class="p-5"
                wire:ignore.self
                x-data="dashboardChart(@js($chartData))"
                x-init="render($wire.chartData)"
                x-on:chart-data-updated.window="render($event.detail.data || $event.detail[0].data)">
            <div class="text-sm font-semibold text-ink-primary mb-1">{{ $chartTitle }}</div>
            <div class="text-[11px] text-ink-muted mb-3">Pannes et heures de vol par semaine ISO</div>
            <div wire:ignore id="dashboard-chart-container" style="min-height: 380px;"></div>
        </x-card>
    @else
        {{-- Vue défaut : chart mensuel multi-machines + widget validation --}}

        {{-- Chart mensuel --}}
        <x-card class="p-5 mb-4">
            <div class="flex items-start justify-between flex-wrap gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold text-ink-primary mb-0.5">Heures de vol par mois</div>
                    <div class="text-[11px] text-ink-muted">{{ count($monthlyData['months'] ?? []) }} derniers mois · machines actives uniquement</div>
                </div>
                @if (!empty($monthlyData['series']))
                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ($monthlyData['series'] as $s)
                            <div class="flex items-center gap-1.5">
                                <div class="w-2.5 h-2.5 rounded-sm" style="background:{{ $s['color'] }}"></div>
                                <span class="text-[10px] font-mono text-ink-secondary">{{ $s['name'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            @if (empty($monthlyData['series']))
                <div class="py-12 text-center text-ink-muted text-sm">
                    Aucune machine active sur les 30 derniers jours.
                </div>
            @else
                <div wire:ignore id="monthly-chart-container"
                     x-data x-init="renderMonthlyChart(@js($monthlyData))"
                     style="min-height: 280px;"></div>
            @endif
        </x-card>

        {{-- Widget validation par appareil --}}
        @if (!empty($validationBreakdown))
            <x-card class="p-5">
                <div class="flex items-start justify-between flex-wrap gap-3 mb-4">
                    <div>
                        <div class="text-sm font-semibold text-ink-primary mb-0.5">Statuts de validation des pannes</div>
                        <div class="text-[11px] text-ink-muted">Par appareil actif</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-ok"></div><span class="text-[10px] text-ink-secondary">Validées</span></div>
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-danger"></div><span class="text-[10px] text-ink-secondary">Rejetées</span></div>
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-accent"></div><span class="text-[10px] text-ink-secondary">Manquantes</span></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
                    @foreach ($validationBreakdown as $row)
                        <div class="px-3 py-3 bg-app-bg border border-app-border-soft rounded-md">
                            <div class="font-mono text-sm font-medium text-accent-pressed mb-2">{{ $row['hc_id'] }}</div>
                            <div class="h-1.5 bg-app-border-soft rounded overflow-hidden flex mb-2.5">
                                @if ($row['total'] > 0)
                                    <div class="h-full bg-ok" style="width:{{ $row['pct_v'] }}%"></div>
                                    <div class="h-full bg-danger" style="width:{{ $row['pct_r'] }}%"></div>
                                    <div class="h-full bg-accent" style="width:{{ $row['pct_m'] }}%"></div>
                                @endif
                            </div>
                            <div class="space-y-0.5 text-[10px] font-mono">
                                <div class="flex justify-between"><span class="text-ink-muted">Val.</span><span class="text-ok font-medium">{{ $row['validated'] }}</span></div>
                                <div class="flex justify-between"><span class="text-ink-muted">Rej.</span><span class="text-danger font-medium">{{ $row['rejected'] }}</span></div>
                                <div class="flex justify-between"><span class="text-ink-muted">Manq.</span><span class="text-warn font-medium">{{ $row['missing'] }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif
    @endif

    {{-- ApexCharts scripts --}}
    @once
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            window.dashboardChart = function (initial) {
                return {
                    chart: null,
                    render(data) {
                        if (!data || !data.weeks || data.weeks.length === 0) return;
                        const noDataAnnotations = (data.noData || []).map(w => ({
                            x: w,
                            borderColor: '#f5b731',
                            fillColor: '#fef9eb',
                            opacity: 0.3,
                            label: { text: 'NO DATA', borderColor: 'transparent',
                                style: { color: '#a07010', background: '#fff', fontSize: '10px', fontFamily: 'DM Mono' } },
                        }));
                        const options = {
                            chart: { type: 'line', height: 380, toolbar: { show: true }, fontFamily: 'DM Sans', background: 'transparent' },
                            series: [
                                { name: 'Pannes',        type: 'line', data: data.pannes },
                                { name: 'Heures de vol', type: 'line', data: data.fh },
                            ],
                            stroke: { width: 2.5, curve: 'smooth' },
                            colors: ['#c0391a', '#f5b731'],
                            markers: { size: 4 },
                            xaxis: {
                                categories: data.weeks,
                                labels: { rotate: -45, style: { fontSize: '10px', fontFamily: 'DM Mono', colors: '#9aa3b8' } },
                                axisBorder: { color: '#dde2ec' },
                                axisTicks: { color: '#dde2ec' },
                            },
                            yaxis: [
                                { seriesName: 'Pannes', title: { text: 'Pannes', style: { color: '#c0391a', fontSize: '11px', fontFamily: 'DM Sans' } },
                                  labels: { style: { colors: '#9aa3b8', fontFamily: 'DM Mono', fontSize: '10px' } }, min: 0 },
                                { seriesName: 'Heures de vol', opposite: true, title: { text: 'Heures', style: { color: '#a07010', fontSize: '11px', fontFamily: 'DM Sans' } },
                                  labels: { style: { colors: '#9aa3b8', fontFamily: 'DM Mono', fontSize: '10px' } }, min: 0 },
                            ],
                            annotations: { xaxis: noDataAnnotations },
                            legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px', fontFamily: 'DM Sans', labels: { colors: '#5a6682' } },
                            tooltip: { shared: true, intersect: false, theme: 'light' },
                            grid: { borderColor: '#dde2ec', strokeDashArray: 3 },
                        };
                        this.$nextTick(() => {
                            const el = document.querySelector('#dashboard-chart-container');
                            if (!el) return;
                            if (this.chart) this.chart.destroy();
                            this.chart = new ApexCharts(el, options);
                            this.chart.render();
                        });
                    },
                };
            };

            window.renderMonthlyChart = function (data) {
                if (!data.series || data.series.length === 0) return;
                const options = {
                    chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'DM Sans', background: 'transparent', stacked: false },
                    series: data.series.map(s => ({ name: s.name, data: s.data })),
                    colors: data.series.map(s => s.color),
                    plotOptions: { bar: { borderRadius: 2, columnWidth: '70%' } },
                    dataLabels: { enabled: false },
                    xaxis: {
                        categories: data.months,
                        labels: { style: { fontFamily: 'DM Mono', fontSize: '10px', colors: '#9aa3b8' } },
                        axisBorder: { color: '#dde2ec' },
                        axisTicks: { color: '#dde2ec' },
                    },
                    yaxis: {
                        title: { text: 'Heures', style: { color: '#5a6682', fontSize: '11px', fontFamily: 'DM Sans' } },
                        labels: { style: { fontFamily: 'DM Mono', fontSize: '10px', colors: '#9aa3b8' }, formatter: (v) => v + 'h' },
                        min: 0,
                    },
                    legend: { show: false },
                    tooltip: { theme: 'light', y: { formatter: (v) => v + 'h' } },
                    grid: { borderColor: '#dde2ec', strokeDashArray: 3 },
                };
                const el = document.querySelector('#monthly-chart-container');
                if (!el) return;
                const chart = new ApexCharts(el, options);
                chart.render();
            };
        </script>
    @endonce
</div>
```

## 8. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test` reste vert. `DashboardChartTest` peut nécessiter ajustement si :
  - Test cherche un texte précis (titre changé)
  - Test reset les filtres puis vérifie l'absence du chart
  - Test attendait un seul chart visible
- **Smoke Playwright** :
  - `/dashboards` (vue défaut) : 4 KPI cards en haut + bar chart mensuel + widget validation grid.
  - Sélectionner un machine + dates → cliquer "Afficher" → la page bascule sur le chart line pannes+FH.
  - Cliquer "Réinitialiser" → retour à la vue défaut.
  - 0 erreur console.

## 9. Pièges connus

- **`MissingPanne` flight relation** : le modèle `MissingPanne` doit avoir une relation `flight()` ou un champ `flight_id`. Si non, le `whereHas('flight', ...)` du `validationBreakdown` échoue. À vérifier au moment de l'implém ; si le modèle n'a pas la relation, on filtrera sur les manquantes par `machine_id` directement (probable que le modèle ait `flight_id`).
- **ApexCharts `wire:ignore`** : critique pour empêcher Livewire de réinjecter le DOM du graphique entre 2 renders (sinon ApexCharts plante). On garde `wire:ignore.self` sur les wrappers et `wire:ignore` sur les containers.
- **Performance des `getXxxProperty` Livewire** : les 3 properties calculées sont évaluées à chaque render. Pour le moment c'est OK (4-7 queries), mais si la page devient lente, on cachera (5 minutes) plus tard.
- **`isFiltered` flag** : la condition `!empty($this->chartData) && $this->machineId !== null` détermine quelle vue afficher. Le `clearFilter` reset les 3 et vide chartData → vue défaut.
- **Conversion semaine ISO → mois** : on prend le **jeudi** de la semaine ISO comme mois canonique (norme ISO 8601). Évite les ambiguïtés sur les semaines à cheval entre 2 mois.
- **Couleurs cyclées** : si plus de 7 machines actives, ApexCharts cycle. La 8e machine prendra `#f5b731` (amber) à nouveau. Acceptable pour l'instant.

## 10. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 8 | `/profile` |
| 9 | `/admin/users` + `/admin/audit-log` |

KPI trends ("↑ +12% ce mois") : YAGNI. Si demande future, requête Carbon comparant période courante vs précédente.

Filtres avancés (groupBy, sortBy, custom buckets) : pas prévus.
