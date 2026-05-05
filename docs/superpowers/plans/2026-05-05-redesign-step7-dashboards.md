# Redesign Étape 7 — `/dashboards` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre `/dashboards` en tableau de bord double-vue : (1) overview flotte par défaut avec 4 KPIs + chart bar mensuel multi-machines actives + widget validation par appareil ; (2) drill-down quand machine + dates sont sélectionnés (chart line pannes+FH par semaine, restylé). Ajouter scope `Machine::active()` réutilisable.

**Architecture:** Refonte du composant Livewire `DashboardChart` (PHP + vue Blade) pour gérer les 2 vues conditionnelles. Ajout d'un scope sur `Machine` (≥3 vols réels sur 30 jours glissants). Charts ApexCharts restylés avec palette redesign + DM Mono. Pas de migration.

**Tech Stack:** Laravel 12, Livewire, Alpine.js, Tailwind CSS, ApexCharts (CDN), Pest 3, Playwright MCP.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step7-dashboards-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step7-dashboards-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step7-dashboards.md`

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??`.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step7-dashboards-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step7-dashboards.md
git commit -m "docs: add spec and plan for redesign step 7 (/dashboards)"
```

---

## Task 2: Ajouter `Machine::scopeActive`

**Files:**
- Modify: `app/Models/Machine.php`

- [ ] **Step 1: Lire le fichier actuel pour identifier où insérer**

```bash
cat app/Models/Machine.php
```

Repérer la fin de la classe (avant la dernière `}`) ou un endroit logique après les relations existantes (après `recurrentFailures()` et `latestFlight()`).

- [ ] **Step 2: Ajouter la méthode `scopeActive` à la classe**

Ajouter cette méthode dans la classe `Machine` (juste avant la `}` finale) :

```php
    public function scopeActive($query, int $threshold = 3, int $days = 30)
    {
        return $query->whereHas('flights', function ($q) use ($days) {
            $q->where('is_non_vol', false)
              ->where('flagged_as_error', false)
              ->where('start_datetime', '>=', now()->subDays($days));
        }, '>=', $threshold);
    }
```

- [ ] **Step 3: Test rapide via tinker**

```bash
php artisan tinker --execute="echo \App\Models\Machine::active()->pluck('hc_id')->implode(', ');"
```

Expected: liste des HcId qui ont ≥3 vols dans les 30 derniers jours. Si aucune machine n'est active selon le critère, output vide. Pas d'erreur SQL.

- [ ] **Step 4: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 verts. Le scope ajouté ne casse rien.

- [ ] **Step 5: Commit**

```bash
git add app/Models/Machine.php
git commit -m "feat(model): add Machine::scopeActive (>=3 real flights / 30 days)"
```

---

## Task 3: Refondre `app/Livewire/DashboardChart.php`

**Files:**
- Modify: `app/Livewire/DashboardChart.php` (replace entirely)

- [ ] **Step 1: Replace the entire content**

```php
<?php

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

    public function getKpisProperty(): array
    {
        $totalConservees = TechnicalEvent::where('status', 'conservee')->count();
        $validated = TechnicalEvent::where('status', 'conservee')
            ->where('validation_status', 'validated')
            ->count();

        return [
            'total_vols'        => Flight::where('is_non_vol', false)->where('flagged_as_error', false)->count(),
            'total_conservees'  => $totalConservees,
            'taux_validation'   => $totalConservees > 0 ? (int) round($validated / $totalConservees * 100) : 0,
            'erreurs_pipeline'  => Import::where('status', 'error')->count(),
        ];
    }

    public function getMonthlyDataProperty(): array
    {
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

        $buckets = [];
        foreach ($aggs as $a) {
            $year = (int) substr($a->iso_week, 0, 4);
            $week = (int) substr($a->iso_week, 6, 2);
            $monthKey = Carbon::now()->setISODate($year, $week)->startOfWeek()->addDays(3)->format('Y-m');
            $buckets[$a->machine_id][$monthKey] = ($buckets[$a->machine_id][$monthKey] ?? 0)
                + (float) $a->total_flight_hours;
        }

        $months = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

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

- [ ] **Step 2: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 verts. Le composant PHP a maintenant des `getXxxProperty` mais l'ancien comportement (`displayChart`) reste compatible.

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/DashboardChart.php
git commit -m "feat(livewire): refactor DashboardChart for dual-view dashboard with KPIs and validation breakdown"
```

---

## Task 4: Refondre `dashboards.blade.php` (header)

**Files:**
- Modify: `resources/views/dashboards.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/dashboards.blade.php
git commit -m "feat(dashboards): redesign /dashboards header with section-label"
```

---

## Task 5: Refondre `livewire/dashboard-chart.blade.php`

**Files:**
- Modify: `resources/views/livewire/dashboard-chart.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/dashboard-chart.blade.php
git commit -m "feat(dashboards): redesign dashboard view with KPIs, monthly chart and validation breakdown"
```

---

## Task 6: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests finaux**

```bash
npm run build
php artisan test
```

Expected: tout vert.

- [ ] **Step 2: Screenshot Playwright `/dashboards` (vue défaut)**

Naviguer `http://127.0.0.1:8000/dashboards`. Capture viewport 1440×900.

Vérifier :
- Header section-label "Analytique" + h1 "Dashboards"
- Filtres en haut : select "Tous les hélicos" + 2 inputs date avec séparateur "→"
- 4 KPI cards : Total vols (ink-primary mono), Pannes conservées (accent mono), Taux validation (ok green mono), Erreurs pipeline (danger mono)
- Card "Heures de vol par mois" avec légende couleurs hélicos + bar chart ApexCharts (ou empty state si aucune machine active)
- Card "Statuts de validation des pannes" avec grid de mini-cards par hélico (barre tricolore + counts mono)

- [ ] **Step 3: Test bascule en vue filtrée**

Sélectionner une machine + dates dans les filtres → cliquer "Afficher". Vérifier :
- La vue change : chart line ApexCharts apparaît avec 2 séries (Pannes danger + Heures accent)
- Le widget validation et le chart mensuel disparaissent
- Bouton "✕ Réinitialiser" visible

Cliquer "✕ Réinitialiser" → retour à la vue défaut.

- [ ] **Step 4: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error. ApexCharts peut warner mais 0 erreur attendue.

---

## Notes / pièges connus

- **`Machine::active()` performance** : `whereHas` produit un sous-query `EXISTS`. Sur petit effectif (<50 machines, <10000 vols récents), c'est instantané. Si la flotte grossit, indexer `flights(machine_id, is_non_vol, flagged_as_error, start_datetime)` (composite index).
- **Buckétisation iso_week → mois** : on prend le **jeudi de la semaine ISO** comme mois canonique (norme ISO 8601). Cela évite l'ambiguïté pour les semaines à cheval entre 2 mois (W01 par exemple peut commencer en décembre).
- **Carbon `addDays(3)`** : du lundi (start of ISO week) + 3 jours = jeudi. Le mois du jeudi détermine la classification du bucket.
- **`getXxxProperty` Livewire** : ces 3 propriétés calculées (kpis, monthlyData, validationBreakdown) sont évaluées à **chaque render**. Pour `/dashboards` peu fréquentée, OK. Si la page devient lente plus tard, ajouter un `Cache::remember()` 5 min sur les 3 calculs.
- **`MissingPanne::flight()`** : la relation est définie côté modèle (vérifié dans `app/Models/MissingPanne.php`). Le `whereHas('flight', ...)` fonctionne.
- **ApexCharts `wire:ignore`** : critique pour empêcher Livewire de réinjecter le DOM du graphique entre 2 renders. On garde `wire:ignore.self` sur les wrappers (vue filtrée) et `wire:ignore` sur les containers internes.
- **Reset filter UX** : `clearFilter` ne reset pas seulement les inputs côté Livewire, il dispatch un event `chart-data-updated` avec data vide pour que l'Alpine côté chart filtré se "ferme" proprement.
- **Format mois** : `Carbon::isoFormat('MMM YYYY')` → "mai 2026". Locale FR active par défaut sur Laravel ou non ? Si l'output est en anglais, ajouter `\Carbon\Carbon::setLocale('fr')` dans le `boot()` d'`AppServiceProvider`. Vérifier au moment du test.
- **Empty state vue défaut** : si `Machine::active()` retourne 0 → message "Aucune machine active sur les 30 derniers jours." dans la card Heures de vol. Le widget validation est juste skipé (`@if (!empty($validationBreakdown))`).
- **DashboardChartTest** : test Pest existant. Vérifier qu'il appelle bien `displayChart` (toujours fonctionnel) et n'asserte pas sur des éléments visuels disparus (graphique unique, etc.). Adapter si besoin.
