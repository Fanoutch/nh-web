<?php

namespace App\Http\Controllers;

use App\Models\ExcelReport;
use App\Models\Secteur;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelReportController extends Controller
{
    /** Accès vérifié sur la route (consulterDispos) ; la dispo doit appartenir au secteur de l'URL. */
    public function download(Secteur $secteur, ExcelReport $report): BinaryFileResponse
    {
        abort_unless($report->secteur_id === $secteur->id, 404, 'Dispo introuvable dans ce secteur.');
        abort_unless($report->isDownloadable(), 404, 'Dispo non disponible.');

        return response()->download(
            $report->output_path,
            $report->output_name ?: basename($report->output_path),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
