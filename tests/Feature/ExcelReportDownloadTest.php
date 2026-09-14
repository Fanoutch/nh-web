<?php

use App\Models\ExcelReport;
use App\Models\User;

it('downloads the generated excel when the report is ok', function () {
    $user = User::factory()->create();
    $path = storage_path('app/excel_test_' . uniqid() . '.xlsx');
    file_put_contents($path, 'fake xlsx');

    $report = ExcelReport::create([
        'user_id' => $user->id, 'filename' => 'r.csv', 'status' => 'ok',
        'output_path' => $path, 'output_name' => 'rapport_du_jour.xlsx',
    ]);

    try {
        $this->actingAs($user)
            ->get(route('excel-reports.download', $report))
            ->assertOk()
            ->assertDownload('rapport_du_jour.xlsx');
    } finally {
        @unlink($path);
    }
});

it('returns 404 when the report is not ready or the file is missing', function () {
    $user = User::factory()->create();
    $pending = ExcelReport::create(['user_id' => $user->id, 'filename' => 'r.csv', 'status' => 'pending']);
    $gone = ExcelReport::create([
        'user_id' => $user->id, 'filename' => 'r.csv', 'status' => 'ok',
        'output_path' => storage_path('app/does_not_exist.xlsx'), 'output_name' => 'x.xlsx',
    ]);

    $this->actingAs($user)->get(route('excel-reports.download', $pending))->assertNotFound();
    $this->actingAs($user)->get(route('excel-reports.download', $gone))->assertNotFound();
});

it('requires authentication to download', function () {
    $user = User::factory()->create();
    $report = ExcelReport::create(['user_id' => $user->id, 'filename' => 'r.csv', 'status' => 'ok']);

    $this->get(route('excel-reports.download', $report))->assertRedirect(route('login'));
});
