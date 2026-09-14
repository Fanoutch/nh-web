<?php

use App\Jobs\GenerateExcelReportJob;
use App\Livewire\ExcelReportUploader;
use App\Models\ExcelReport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('redirects guests away from the excel report page', function () {
    $this->get(route('excel-reports.index'))->assertRedirect(route('login'));
});

it('renders the excel report page for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('excel-reports.index'))
        ->assertOk()
        ->assertSee('Rapport Excel')
        ->assertSeeLivewire(ExcelReportUploader::class);
});

it('stages a csv and dispatches the generation job on submit', function () {
    Queue::fake();
    Storage::fake('local');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->createWithContent('rapport_2026-09-14.csv', "a;b\n1;2\n");

    Livewire::actingAs($user)
        ->test(ExcelReportUploader::class)
        ->set('csvFile', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('csvFile', null)
        ->assertSee('rapport_2026-09-14.csv');

    $report = ExcelReport::first();
    expect($report)->not->toBeNull()
        ->and($report->user_id)->toBe($user->id)
        ->and($report->filename)->toBe('rapport_2026-09-14.csv')
        ->and($report->status)->toBe('pending');

    Queue::assertPushed(GenerateExcelReportJob::class, fn ($job) => $job->reportId === $report->id);
});

it('rejects a file that is not a csv', function () {
    Queue::fake();
    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('rapport.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    Livewire::actingAs($user)
        ->test(ExcelReportUploader::class)
        ->set('csvFile', $file)
        ->assertHasErrors(['csvFile']);

    expect(ExcelReport::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('lists reports from every user in the history', function () {
    $alice = User::factory()->create(['name' => 'Alice Test']);
    $bob = User::factory()->create(['name' => 'Bob Test']);
    ExcelReport::create(['user_id' => $alice->id, 'filename' => 'a.csv', 'status' => 'ok', 'rows_written' => 12]);
    ExcelReport::create(['user_id' => $bob->id, 'filename' => 'b.csv', 'status' => 'error', 'message' => 'Colonnes manquantes dans le CSV']);

    Livewire::actingAs($alice)
        ->test(ExcelReportUploader::class)
        ->assertSee('a.csv')->assertSee('b.csv')
        ->assertSee('Bob Test')
        ->assertSee('Colonnes manquantes');
});
