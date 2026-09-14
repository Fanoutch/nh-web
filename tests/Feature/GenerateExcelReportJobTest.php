<?php

use App\Jobs\GenerateExcelReportJob;
use App\Models\ExcelReport;
use App\Models\User;
use App\Services\ExcelPipelineRunner;

/** CSV factice aligné sur les placeholders de BMN/config.py (make_fixtures.py). */
function fakeBmnCsv(): string
{
    $rows = ["COLONNE_A_DEFINIR_1;COLONNE_A_DEFINIR_2;COLONNE_A_DEFINIR_3;COLONNE_A_DEFINIR_4;COLONNE_A_DEFINIR_5;COLONNE_A_DEFINIR_6"];
    for ($i = 1; $i <= 3; $i++) {
        $rows[] = "2026-09-14;SITE-TEST;REF-00{$i};Article {$i};" . (10 * $i) . ";" . (1.5 * $i);
    }
    return "\xEF\xBB\xBF" . implode("\n", $rows) . "\n";
}

function bmnAvailable(): bool
{
    return is_file(rtrim(config('services.excel_pipeline.path'), '/') . '/daily_report.py');
}

it('generates an excel from a csv via the real python script', function () {
    if (!bmnAvailable()) {
        $this->markTestSkipped('Projet BMN introuvable (EXCEL_PIPELINE_PATH)');
    }

    $user = User::factory()->create();
    $staging = storage_path('app/staging_excel_test_' . uniqid() . '.csv');
    file_put_contents($staging, fakeBmnCsv());
    $report = ExcelReport::create(['user_id' => $user->id, 'filename' => 'rapport_2026-09-14.csv', 'status' => 'pending']);

    (new GenerateExcelReportJob($report->id, $staging))->handle(app(ExcelPipelineRunner::class), app(\App\Services\ExcelReportPurger::class));

    $report->refresh();
    expect($report->status)->toBe('ok', 'message: ' . $report->message)
        ->and($report->rows_written)->toBe(3)
        ->and($report->output_path)->toStartWith(GenerateExcelReportJob::outputDir())
        ->and(is_file($report->output_path))->toBeTrue()
        ->and(is_file($staging))->toBeFalse();   // CSV de staging nettoyé

    @unlink($report->output_path);
});

it('records a readable error when the csv does not match the mapping', function () {
    if (!bmnAvailable()) {
        $this->markTestSkipped('Projet BMN introuvable (EXCEL_PIPELINE_PATH)');
    }

    $user = User::factory()->create();
    $staging = storage_path('app/staging_excel_test_' . uniqid() . '.csv');
    file_put_contents($staging, "foo;bar\n1;2\n");
    $report = ExcelReport::create(['user_id' => $user->id, 'filename' => 'mauvais.csv', 'status' => 'pending']);

    (new GenerateExcelReportJob($report->id, $staging))->handle(app(ExcelPipelineRunner::class), app(\App\Services\ExcelReportPurger::class));

    $report->refresh();
    expect($report->status)->toBe('error')
        ->and($report->message)->toContain('Colonnes manquantes')
        ->and(is_file($staging))->toBeFalse();
});

it('marks the report as error when the script path is invalid', function () {
    config(['services.excel_pipeline.path' => sys_get_temp_dir()]);

    $user = User::factory()->create();
    $staging = storage_path('app/staging_excel_test_' . uniqid() . '.csv');
    file_put_contents($staging, "a;b\n1;2\n");
    $report = ExcelReport::create(['user_id' => $user->id, 'filename' => 'x.csv', 'status' => 'pending']);

    (new GenerateExcelReportJob($report->id, $staging))->handle(app(ExcelPipelineRunner::class), app(\App\Services\ExcelReportPurger::class));

    $report->refresh();
    expect($report->status)->toBe('error')
        ->and($report->message)->toContain('Réponse invalide du script Excel');
});
