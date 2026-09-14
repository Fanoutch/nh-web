<?php

namespace App\Http\Controllers;

use App\Models\ExcelReport;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelReportController extends Controller
{
    public function download(ExcelReport $report): BinaryFileResponse
    {
        abort_unless($report->isDownloadable(), 404, 'Dispo non disponible.');

        return response()->download(
            $report->output_path,
            $report->output_name ?: basename($report->output_path),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
