<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightImporter
{
    /**
     * Importe un vol normal + pannes depuis le resultat pipeline (status=ok).
     */
    public function import(array $pipelineResult): Flight
    {
        return DB::transaction(function () use ($pipelineResult) {
            $machine = Machine::firstOrCreate(['hc_id' => $pipelineResult['hc_id']]);

            $flight = Flight::updateOrCreate(
                [
                    'machine_id' => $machine->id,
                    'dsn' => $pipelineResult['dsn'] ?? '',
                    'num' => $pipelineResult['num'] ?? '',
                ],
                [
                    'start_datetime' => $this->parseDate($pipelineResult['start_datetime']),
                    'end_datetime'   => $this->parseDate($pipelineResult['end_datetime']),
                    'flight_type'    => $pipelineResult['flight_type'] ?? 'FLIGHT',
                    'flight_hours'   => $pipelineResult['flight_hours'] ?? 0,
                    'consumed_fuel'  => $pipelineResult['consumed_fuel'] ?? null,
                    'is_non_vol'     => strtoupper($pipelineResult['flight_type'] ?? 'FLIGHT') !== 'FLIGHT',
                    'xml_path'       => ($pipelineResult['output_dir'] ?? '') . '/xml_epure.xml',
                    'processed_at'   => now(),
                ],
            );

            $outputDir = $pipelineResult['output_dir'] ?? '';
            $conservees = $this->readJson($outputDir . '/pannes_conservees.json');
            if (is_array($conservees['pannes_conservees'] ?? null)) {
                $this->syncPannes($flight, $conservees['pannes_conservees'], 'conservee');
            }
            $isolees = $this->readJson($outputDir . '/pannes_isolees.json');
            if (is_array($isolees['pannes_hors_date'] ?? null)) {
                $this->syncPannes($flight, $isolees['pannes_hors_date'], 'isolee');
            }

            return $flight;
        });
    }

    /**
     * Importe un non-vol (status=no_engine), flight_type != FLIGHT.
     */
    public function importNonVol(array $pipelineResult): Flight
    {
        return DB::transaction(function () use ($pipelineResult) {
            $machine = Machine::firstOrCreate(['hc_id' => $pipelineResult['hc_id']]);

            $now = now();
            return Flight::updateOrCreate(
                [
                    'machine_id' => $machine->id,
                    'dsn' => $pipelineResult['dsn'] ?? '',
                    'num' => $pipelineResult['num'] ?? '',
                ],
                [
                    'start_datetime' => $this->parseDate($pipelineResult['start_datetime']) ?? $now,
                    'end_datetime'   => $this->parseDate($pipelineResult['end_datetime']) ?? $now,
                    'flight_type'    => $pipelineResult['flight_type'] ?? 'GROUND',
                    'flight_hours'   => 0,
                    'consumed_fuel'  => $pipelineResult['consumed_fuel'] ?? null,
                    'is_non_vol'     => true,
                    'flagged_as_error' => false,
                    'xml_path'       => $pipelineResult['output_dir'] ?? null,
                    'processed_at'   => now(),
                ],
            );
        });
    }

    private function syncPannes(Flight $flight, array $pannes, string $status): void
    {
        // Import idempotent : on purge les pannes precedentes de ce status
        $flight->technicalEvents()->where('status', $status)->delete();

        foreach ($pannes as $p) {
            $raise = $this->parseDate($p['RaiseDateTime'] ?? null);
            $isoWeek = $raise ? $raise->isoFormat('GGGG-[W]WW') : '';

            TechnicalEvent::create([
                'flight_id' => $flight->id,
                'technical_event_id' => $p['TechnicalEventId'] ?? '',
                'raise_datetime' => $raise ?? now(),
                'status' => $status,
                'iso_week' => $isoWeek,
                'nombre_occurrences' => $p['nombre_occurrences'] ?? 1,
                'details' => $p,
            ]);
        }
    }

    private function readJson(string $path): array
    {
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        return json_decode($raw, true) ?: [];
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (!$raw) return null;
        // Try French format DD/MM/YYYY first (pipeline's pannes_*.json native format).
        // Otherwise Carbon::parse mis-interprets ambiguous dates like "08/01/2026"
        // (8 Jan) as MM/DD/YYYY -> Aug 1, scrambling iso_week for every conservee
        // panne with day <= 12.
        try {
            $dt = Carbon::createFromFormat('d/m/Y H:i:s', $raw);
            if ($dt !== false) return $dt;
        } catch (\Throwable) {}
        try { return Carbon::parse($raw); } catch (\Throwable) {}
        return null;
    }
}
