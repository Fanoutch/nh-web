<?php

namespace App\Livewire;

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use Carbon\Carbon;
use Livewire\Component;

class DashboardChart extends Component
{
    public ?int $machineId = null;
    public ?string $startDate = null;
    public ?string $endDate = null;
    public bool $showChart = false;

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

        $this->chartTitle = "Pannes & Heures de vol — {$machine->hc_id} — {$this->startDate} → {$this->endDate}";
        $this->chartData = [
            'weeks' => $weeks,
            'pannes' => $pannes,
            'fh' => $fh,
            'noData' => $noData,
            'title' => $this->chartTitle,
        ];
        $this->showChart = true;
        $this->dispatch('chart-data-updated', data: $this->chartData);
    }

    private function generateAllWeeks(string $start, string $end): array
    {
        $weeks = [];
        $startYear = (int) substr($start, 0, 4);
        $startWeek = (int) substr($start, 6, 2);
        $endYear = (int) substr($end, 0, 4);
        $endWeek = (int) substr($end, 6, 2);

        $cursor = Carbon::now()->setISODate($startYear, $startWeek)->startOfWeek();
        $endCursor = Carbon::now()->setISODate($endYear, $endWeek)->startOfWeek();

        while ($cursor->lte($endCursor)) {
            $weeks[] = $cursor->isoFormat('GGGG-[W]WW');
            $cursor->addWeek();
        }
        return $weeks;
    }

    public function render()
    {
        return view('livewire.dashboard-chart', [
            'machines' => Machine::orderBy('hc_id')->get(),
        ]);
    }
}
