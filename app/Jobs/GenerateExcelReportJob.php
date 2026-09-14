<?php

namespace App\Jobs;

use App\Models\ExcelReport;
use App\Services\ExcelPipelineRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateExcelReportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(public int $reportId, public string $csvPath) {}

    public static function outputDir(): string
    {
        return storage_path('app/excel-reports');
    }

    public function handle(ExcelPipelineRunner $runner): void
    {
        $report = ExcelReport::findOrFail($this->reportId);
        $report->update(['status' => 'processing']);

        try {
            $outputDir = self::outputDir();
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0775, true);
            }

            $result = $runner->run($this->csvPath, $outputDir);

            if ($result['status'] === 'ok') {
                $report->update([
                    'status' => 'ok',
                    'output_path' => $result['output_path'],
                    'output_name' => $result['output_name'],
                    'rows_written' => (int) $result['rows_written'],
                    'message' => null,
                ]);
            } else {
                $report->update([
                    'status' => 'error',
                    'message' => $result['message'] ?? 'Erreur inconnue du script Excel',
                ]);
            }
        } catch (\Throwable $e) {
            $report->update(['status' => 'error', 'message' => substr($e->getMessage(), 0, 1000)]);
            throw $e;
        } finally {
            if (is_file($this->csvPath)) {
                @unlink($this->csvPath);
            }
        }
    }
}
