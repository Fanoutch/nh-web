<?php

namespace App\Jobs;

use App\Models\Flight;
use App\Models\Import;
use App\Services\FlightImporter;
use App\Services\RecurrentFailuresRefresher;
use App\Services\WeeklyAggregatesRefresher;
use App\Services\XmlPipelineRunner;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $importId, public string $xmlPath) {}

    public function handle(
        XmlPipelineRunner $runner,
        FlightImporter $importer,
        WeeklyAggregatesRefresher $aggRefresher,
        RecurrentFailuresRefresher $recurrentRefresher,
    ): void {
        $import = Import::findOrFail($this->importId);
        $import->update(['status' => 'processing']);

        $outputBase = storage_path('app/data');
        $result = $runner->run($this->xmlPath, $outputBase);

        match ($result['status']) {
            'ok'        => $this->handleOk($import, $result, $importer, $aggRefresher, $recurrentRefresher, $outputBase),
            'no_engine' => $this->handleNonVol($import, $result, $importer),
            default     => $this->handleError($import, $result),
        };
    }

    private function handleOk(Import $import, array $result, FlightImporter $importer, WeeklyAggregatesRefresher $aggRefresher, RecurrentFailuresRefresher $recurrentRefresher, string $outputBase): void
    {
        try {
            $existed = Flight::whereHas('machine', fn ($q) => $q->where('hc_id', $result['hc_id']))
                ->where('dsn', $result['dsn'] ?? '')
                ->where('num', $result['num'] ?? '')
                ->exists();

            $flight = $importer->import($result);

            // Refresh weekly_aggregates from DB for every iso_week touched
            // by this flight: the flight's own iso_week (FH side) + every
            // distinct iso_week of its conservee pannes.
            $flightIsoWeek = Carbon::parse($flight->end_datetime)->isoFormat('GGGG-[W]WW');
            $panneIsoWeeks = $flight->technicalEvents()
                ->where('status', 'conservee')
                ->pluck('iso_week')
                ->all();
            $aggRefresher->refresh($flight->machine, array_merge([$flightIsoWeek], $panneIsoWeeks));

            $recurrentRefresher->refresh($flight->machine);

            $import->update([
                'status' => $existed ? 'already_processed' : 'ok',
                'flight_id' => $flight->id,
                'result' => [
                    'hc_id' => $result['hc_id'],
                    'dsn' => $result['dsn'],
                    'num' => $result['num'],
                    'pannes_conservees_count' => $flight->technicalEvents()->where('status', 'conservee')->count(),
                    'flight_hours' => (float) $flight->flight_hours,
                ],
            ]);
            $this->deleteTransitFiles($result, $outputBase);
        } catch (\Throwable $e) {
            $this->logPipelineError($import, $e->getMessage());
        }
    }

    private function handleNonVol(Import $import, array $result, FlightImporter $importer): void
    {
        try {
            $flight = $importer->importNonVol($result);
            $import->update([
                'status' => 'non_vol',
                'flight_id' => $flight->id,
                'result' => [
                    'hc_id' => $result['hc_id'],
                    'dsn' => $result['dsn'],
                    'num' => $result['num'],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logPipelineError($import, $e->getMessage());
        }
    }

    private function handleError(Import $import, array $result): void
    {
        $this->logPipelineError($import, $result['message'] ?? 'Unknown pipeline error');
    }

    private function logPipelineError(Import $import, string $message): void
    {
        $import->update([
            'status' => 'error',
            'result' => ['message' => $message],
        ]);

        activity('pipeline')
            ->event('error')
            ->performedOn($import)
            ->withProperties([
                'attributes' => [
                    'filename' => $import->filename,
                    'message' => $message,
                ],
            ])
            ->log("Pipeline error: {$message}");
    }

    /**
     * Supprime les fichiers JSON/CSV de transit pipeline qui ne servent plus
     * une fois la donnee ingere en DB. Garde xml_epure.xml (download) et
     * occurrentes.json (etat persistant Phase Recurrent, requis par la
     * prochaine ingestion tant que Phase 2 n'a pas migre l'etat en DB).
     */
    private function deleteTransitFiles(array $result, string $outputBase): void
    {
        $outputDir = $result['output_dir'] ?? null;
        $hcId = $result['hc_id'] ?? null;
        $year = (int) ($result['annee'] ?? date('Y'));

        $toDelete = [];
        if ($outputDir) {
            $toDelete[] = $outputDir . '/pannes_conservees.json';
            $toDelete[] = $outputDir . '/pannes_isolees.json';
        }
        if ($hcId) {
            $toDelete[] = $outputBase . "/reports/yearly/{$hcId}/{$hcId}_{$year}.csv";
            $toDelete[] = $outputBase . "/FHreport/yearly/{$hcId}/{$hcId}_{$year}.csv";
        }

        foreach ($toDelete as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
