<?php

namespace App\Console\Commands;

use App\Services\ExcelReportPurger;
use Illuminate\Console\Command;

class PurgeExcelReports extends Command
{
    protected $signature = 'bmn:purge {--days= : Âge en jours au-delà duquel les dispos générées sont supprimées (défaut : config)}';

    protected $description = 'Supprime les dispos générées (onglet BMN) plus anciennes que N jours, fichiers Excel compris';

    public function handle(ExcelReportPurger $purger): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $n = $purger->purge($days);
        $this->info("bmn:purge — {$n} dispo(s) supprimée(s) (rétention : " . ($days ?? config('services.excel_pipeline.retention_days')) . " jours).");
        return self::SUCCESS;
    }
}
