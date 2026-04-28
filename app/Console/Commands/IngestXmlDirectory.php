<?php

namespace App\Console\Commands;

use App\Jobs\ProcessXmlJob;
use App\Models\Import;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class IngestXmlDirectory extends Command
{
    protected $signature = 'nh:ingest-xml
        {path : Dossier contenant les .xml (chemin absolu ou relatif au projet)}
        {--user=1 : ID utilisateur a associer aux imports (defaut: 1)}
        {--sync : Executer les jobs immediatement (sync) au lieu de les dispatcher sur la queue}';

    protected $description = 'Ingere tous les XML d\'un dossier de la meme maniere que l\'UI /upload (copie dans staging + dispatch ProcessXmlJob).';

    public function handle(): int
    {
        $dir = $this->argument('path');
        if (!is_dir($dir)) {
            $this->error("Dossier introuvable : {$dir}");
            return self::FAILURE;
        }

        $userId = (int) $this->option('user');
        if (!User::find($userId)) {
            $this->error("Utilisateur id={$userId} introuvable.");
            return self::FAILURE;
        }

        $files = glob(rtrim($dir, '/') . '/*.xml') ?: [];
        if (empty($files)) {
            $this->warn("Aucun .xml dans {$dir}");
            return self::SUCCESS;
        }

        $sync = (bool) $this->option('sync');
        $this->info(count($files) . ' fichier(s) a ingerer. Mode : ' . ($sync ? 'SYNC' : 'QUEUE'));

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach ($files as $src) {
            $originalName = basename($src);
            $stagedName = uniqid('', true) . '_' . $originalName;
            $stagedRelative = 'staging/' . $stagedName;

            Storage::disk('local')->put($stagedRelative, file_get_contents($src));
            $absolute = Storage::disk('local')->path($stagedRelative);

            $import = Import::create([
                'user_id' => $userId,
                'filename' => $originalName,
                'status' => 'pending',
            ]);

            if ($sync) {
                ProcessXmlJob::dispatchSync($import->id, $absolute);
            } else {
                ProcessXmlJob::dispatch($import->id, $absolute);
            }

            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);
        $this->info('Termine. Suivi dans /imports sur le site.');

        return self::SUCCESS;
    }
}
