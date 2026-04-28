<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class XmlPipelineRunner
{
    /**
     * Execute la pipeline Python sur un XML et retourne le resume JSON.
     *
     * @return array{status:string,hc_id:?string,dsn:?string,num:?string,folder_name:?string,annee:?string,semaine:?string,output_dir:?string,message:?string}
     */
    public function run(string $xmlPath, string $outputBase, ?string $filtreMode = '48h'): array
    {
        $pythonRoot = config('services.pipeline.path');

        $cmd = [
            'python3', 'main.py',
            $xmlPath,
            '--output-base', $outputBase,
            '--json-output',
        ];
        if ($filtreMode !== null) {
            $cmd[] = '--filtre-mode';
            $cmd[] = $filtreMode;
        }

        $process = new Process($cmd, $pythonRoot);
        $process->setTimeout(600);
        $process->run();

        $stdout = trim($process->getOutput());
        $lines = array_values(array_filter(
            explode("\n", $stdout),
            fn ($l) => trim($l) !== '',
        ));
        $lastLine = end($lines) ?: '';

        $parsed = json_decode($lastLine, true);
        if (!is_array($parsed)) {
            return [
                'status' => 'error',
                'hc_id' => null, 'dsn' => null, 'num' => null,
                'folder_name' => null, 'annee' => null, 'semaine' => null,
                'output_dir' => null,
                'message' => 'Invalid JSON from pipeline: ' . substr($process->getErrorOutput() ?: $lastLine, 0, 500),
            ];
        }
        return $parsed;
    }
}
