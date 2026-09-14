<?php

namespace App\Services;

use App\Models\ExcelReport;

/**
 * Rétention des dispos générées : supprime les lignes (et leurs fichiers Excel)
 * plus anciennes que N jours. Appelé après chaque génération réussie et par `bmn:purge`.
 */
class ExcelReportPurger
{
    /** @return int nombre de lignes supprimées */
    public function purge(?int $days = null): int
    {
        $days = $days ?? (int) config('services.excel_pipeline.retention_days', 30);
        if ($days <= 0) {
            return 0;
        }

        $expired = ExcelReport::where('created_at', '<', now()->subDays($days))->get();
        foreach ($expired as $report) {
            $this->deleteReport($report);
        }
        return $expired->count();
    }

    /** Supprime une ligne d'historique et son fichier Excel s'il existe. */
    public function deleteReport(ExcelReport $report): void
    {
        if ($report->output_path && is_file($report->output_path)) {
            @unlink($report->output_path);
        }
        $report->delete();
    }
}
