<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\RecurrentFailure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecurrentFailuresRefresher
{
    private const WINDOW_SIZE = 3;
    private const ACTIVATION_THRESHOLD = 2;

    /**
     * Recompute the active recurrent failures for a machine from DB.
     * Sliding window: the 3 most recent chronological flights (by end_datetime,
     * is_non_vol=false, flagged_as_error=false). A technical_event_id appearing
     * (status=conservee) in >= 2 of those 3 flights becomes active. Active
     * rows that drop to 0/3 are deleted; 1/3 or 2/3 stay (buffer zone).
     *
     * @return array{activated:int,kept:int,deactivated:int}
     */
    public function refresh(Machine $machine): array
    {
        return DB::transaction(function () use ($machine) {
            $window = $this->loadWindow($machine);
            if (empty($window)) {
                return ['activated' => 0, 'kept' => 0, 'deactivated' => 0];
            }

            $scores = $this->scoreByTeId($window);
            $existing = RecurrentFailure::where('machine_id', $machine->id)
                ->where('status', 'active')
                ->get()
                ->keyBy('technical_event_id');

            $activated = $kept = $deactivated = 0;

            foreach ($scores as $teId => $score) {
                if ($score >= self::ACTIVATION_THRESHOLD) {
                    if ($existing->has($teId)) {
                        $row = $existing[$teId];
                        $row->score = $score;
                        $row->save();
                        $kept++;
                    } else {
                        $this->createActiveRow($machine, $teId, $score, $window);
                        $activated++;
                    }
                    $existing->forget($teId);
                }
            }

            // Existing rows whose TE is in window with score 1 or 2 stay (buffer).
            // Existing rows whose TE has score 0 (= not in window at all) get deleted.
            foreach ($existing as $teId => $row) {
                $score = $scores[$teId] ?? 0;
                if ($score === 0) {
                    $row->delete();
                    $deactivated++;
                } else {
                    $row->score = $score;
                    $row->save();
                    $kept++;
                }
            }

            return compact('activated', 'kept', 'deactivated');
        });
    }

    /**
     * @return array<int,\App\Models\Flight>  flights in window, latest-first
     */
    private function loadWindow(Machine $machine): array
    {
        return \App\Models\Flight::where('machine_id', $machine->id)
            ->where('is_non_vol', false)
            ->where('flagged_as_error', false)
            ->orderByDesc('end_datetime')
            ->limit(self::WINDOW_SIZE)
            ->get()
            ->all();
    }

    /**
     * @param  array<\App\Models\Flight>  $window
     * @return array<string,int>  technical_event_id => count of flights containing it
     */
    private function scoreByTeId(array $window): array
    {
        $scores = [];
        foreach ($window as $flight) {
            $teIds = DB::table('technical_events')
                ->where('flight_id', $flight->id)
                ->where('status', 'conservee')
                ->distinct()
                ->pluck('technical_event_id')
                ->all();
            foreach ($teIds as $teId) {
                $scores[$teId] = ($scores[$teId] ?? 0) + 1;
            }
        }
        return $scores;
    }

    /**
     * @param  array<\App\Models\Flight>  $window  latest-first
     */
    private function createActiveRow(Machine $machine, string $teId, int $score, array $window): void
    {
        $latest = $window[0];
        $details = DB::table('technical_events')
            ->join('flights', 'flights.id', '=', 'technical_events.flight_id')
            ->where('flights.machine_id', $machine->id)
            ->where('technical_events.technical_event_id', $teId)
            ->where('technical_events.status', 'conservee')
            ->orderByDesc('technical_events.raise_datetime')
            ->value('technical_events.details');
        $details = is_array($details) ? $details : (json_decode($details ?? 'null', true) ?: []);

        $firstApparition = DB::table('technical_events')
            ->join('flights', 'flights.id', '=', 'technical_events.flight_id')
            ->where('flights.machine_id', $machine->id)
            ->where('technical_events.technical_event_id', $teId)
            ->where('technical_events.status', 'conservee')
            ->min('technical_events.raise_datetime');

        $activeDepuisDate = Carbon::parse($latest->end_datetime)->toDateString();
        $activeDepuisVol = sprintf(
            '%s_%s_%s',
            $machine->hc_id,
            Carbon::parse($latest->end_datetime)->format('Y-m-d'),
            $latest->num,
        );

        RecurrentFailure::create([
            'machine_id'          => $machine->id,
            'technical_event_id'  => $teId,
            'status'              => 'active',
            'te_description'      => $details['TEDescription'] ?? null,
            'description'         => $details['Description'] ?? null,
            'system_description'  => $details['SystemDescription'] ?? null,
            'type_description'    => $details['TypeDescription'] ?? null,
            'failure_code'        => $details['FailureCode'] ?? null,
            'active_depuis_vol'   => $activeDepuisVol,
            'active_depuis_date'  => $activeDepuisDate,
            'first_apparition'    => $firstApparition,
            'score'               => $score,
            'details'             => $details,
        ]);
    }
}
