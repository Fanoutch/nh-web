<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use Illuminate\Support\Facades\DB;

class WeeklyAggregatesRefresher
{
    /**
     * Recalcule les lignes weekly_aggregates pour la machine sur les semaines
     * fournies, en SQL direct sur flights + technical_events. Upsert par
     * (machine_id, iso_week). Aucun fichier disque n'est lu.
     *
     * @param  array<string>  $isoWeeks  format "YYYY-Www" (ex "2026-W05")
     * @return array<string,WeeklyAggregate>  index par iso_week
     */
    public function refresh(Machine $machine, array $isoWeeks): array
    {
        $unique = array_values(array_unique(array_filter($isoWeeks)));
        $out = [];
        foreach ($unique as $isoWeek) {
            $out[$isoWeek] = $this->refreshOne($machine, $isoWeek);
        }
        return $out;
    }

    private function refreshOne(Machine $machine, string $isoWeek): WeeklyAggregate
    {
        $totalPannes = DB::table('technical_events')
            ->join('flights', 'flights.id', '=', 'technical_events.flight_id')
            ->where('flights.machine_id', $machine->id)
            ->where('technical_events.iso_week', $isoWeek)
            ->where('technical_events.status', 'conservee')
            ->count();

        $totalFh = (float) DB::table('flights')
            ->where('machine_id', $machine->id)
            ->where('is_non_vol', false)
            ->where('flagged_as_error', false)
            ->whereRaw("to_char(end_datetime, 'IYYY-\"W\"IW') = ?", [$isoWeek])
            ->sum('flight_hours');

        return WeeklyAggregate::updateOrCreate(
            ['machine_id' => $machine->id, 'iso_week' => $isoWeek],
            [
                'year' => (int) substr($isoWeek, 0, 4),
                'total_pannes' => $totalPannes,
                'total_flight_hours' => $totalFh,
            ],
        );
    }
}
