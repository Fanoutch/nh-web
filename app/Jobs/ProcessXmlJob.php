<?php

namespace App\Jobs;

use App\Models\Flight;
use App\Models\Import;
use App\Services\FlightImporter;
use App\Services\RecurrentFailuresIngestor;
use App\Services\WeeklyAggregatesIngestor;
use App\Services\XmlPipelineRunner;
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
        WeeklyAggregatesIngestor $aggIngestor,
        RecurrentFailuresIngestor $recurrentIngestor,
    ): void {
        $import = Import::findOrFail($this->importId);
        $import->update(['status' => 'processing']);

        $outputBase = storage_path('app/data');
        $result = $runner->run($this->xmlPath, $outputBase);

        match ($result['status']) {
            'ok'        => $this->handleOk($import, $result, $importer, $aggIngestor, $recurrentIngestor),
            'no_engine' => $this->handleNonVol($import, $result, $importer),
            default     => $this->handleError($import, $result),
        };
    }

    private function handleOk(Import $import, array $result, FlightImporter $importer, WeeklyAggregatesIngestor $aggIngestor, RecurrentFailuresIngestor $recurrentIngestor): void
    {
        try {
            $existed = Flight::whereHas('machine', fn ($q) => $q->where('hc_id', $result['hc_id']))
                ->where('dsn', $result['dsn'] ?? '')
                ->where('num', $result['num'] ?? '')
                ->exists();

            $flight = $importer->import($result);

            $year = (int) ($result['annee'] ?? date('Y'));
            $hcId = $result['hc_id'];
            $pipelinePath = config('services.pipeline.path');
            $pannesCsv = $pipelinePath . '/data/reports/yearly/' . $hcId . '/' . $hcId . '_' . $year . '.csv';
            $fhCsv = $pipelinePath . '/data/FHreport/yearly/' . $hcId . '/' . $hcId . '_' . $year . '.csv';
            $aggIngestor->ingest($flight->machine, $year, $pannesCsv, $fhCsv);
            $recurrentIngestor->ingest($result['hc_id']);

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
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'error',
                'result' => ['message' => $e->getMessage()],
            ]);
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
            $import->update([
                'status' => 'error',
                'result' => ['message' => $e->getMessage()],
            ]);
        }
    }

    private function handleError(Import $import, array $result): void
    {
        $import->update([
            'status' => 'error',
            'result' => ['message' => $result['message'] ?? 'Unknown pipeline error'],
        ]);
    }
}
