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
