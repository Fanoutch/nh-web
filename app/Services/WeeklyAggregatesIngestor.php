<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use Illuminate\Support\Facades\DB;

class WeeklyAggregatesIngestor
{
    /**
     * Ingere les CSV yearly (pannes + FH) dans weekly_aggregates via UPSERT.
     * Les CSV passes a null sont ignores. Les UPSERT preservent les colonnes
     * non concernees (ingest FH ne touche pas total_pannes et vice versa).
     */
    public function ingest(Machine $machine, int $year, ?string $pannesCsvPath, ?string $fhCsvPath): void
    {
        DB::transaction(function () use ($machine, $year, $pannesCsvPath, $fhCsvPath) {
            if ($pannesCsvPath && file_exists($pannesCsvPath)) {
                $this->upsertFromCsv($machine, $year, $pannesCsvPath, 'total_pannes', skipTotalRow: false);
            }
            if ($fhCsvPath && file_exists($fhCsvPath)) {
                $this->upsertFromCsv($machine, $year, $fhCsvPath, 'total_flight_hours', skipTotalRow: true);
            }
        });
    }

    private function upsertFromCsv(Machine $machine, int $year, string $path, string $column, bool $skipTotalRow): void
    {
        $fh = fopen($path, 'r');
        if ($fh === false) return;

        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return; }

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) continue;
            $semaine = trim((string) $row[0]);
            $value = $row[1];

            if ($skipTotalRow && str_starts_with($semaine, 'TOTAL')) continue;
            if (!preg_match('/^\d{4}-W\d{2}$/', $semaine)) continue;

            WeeklyAggregate::updateOrCreate(
                ['machine_id' => $machine->id, 'iso_week' => $semaine],
                ['year' => $year, $column => $value],
            );
        }
        fclose($fh);
    }
}
