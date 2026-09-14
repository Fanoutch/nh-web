<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Appelle le script Python BMN (daily_report.py) sur un CSV et retourne son résumé JSON.
 * Même pattern que XmlPipelineRunner : la dernière ligne de stdout est un JSON.
 */
class ExcelPipelineRunner
{
    /**
     * @return array{status:string,output_path:?string,output_name:?string,csv_name:?string,rows_written:int,cells_written:int,archived_to:?string,message:?string}
     */
    public function run(string $csvPath, string $outputDir): array
    {
        $pythonRoot = config('services.excel_pipeline.path');
        $python = config('services.excel_pipeline.python', 'python3');

        $cmd = [
            $python, 'daily_report.py',
            '--csv', $csvPath,
            '--output-dir', $outputDir,
            '--no-archive',
            '--json-output',
        ];

        $process = new Process($cmd, $pythonRoot);
        $process->setTimeout(300);
        $process->run();

        $lines = array_values(array_filter(
            explode("\n", trim($process->getOutput())),
            fn ($l) => trim($l) !== '',
        ));
        $lastLine = end($lines) ?: '';

        $parsed = json_decode($lastLine, true);
        if (!is_array($parsed) || !isset($parsed['status'])) {
            return $this->errorResult(
                'Réponse invalide du script Excel : '
                . substr($process->getErrorOutput() ?: $lastLine ?: 'aucune sortie', 0, 500)
            );
        }

        return array_merge($this->errorResult(null), $parsed);
    }

    private function errorResult(?string $message): array
    {
        return [
            'status' => 'error',
            'output_path' => null, 'output_name' => null, 'csv_name' => null,
            'rows_written' => 0, 'cells_written' => 0, 'archived_to' => null,
            'message' => $message,
        ];
    }
}
