<?php

use App\Livewire\ExcelReportUploader;
use App\Models\ExcelReport;
use App\Models\User;
use App\Services\ExcelReportPurger;
use Livewire\Livewire;

function okReportWithFile(User $user, string $filename, ?\DateTimeInterface $createdAt = null): ExcelReport
{
    $path = storage_path('app/excel_purge_test_' . uniqid() . '.xlsx');
    file_put_contents($path, 'x');
    $r = ExcelReport::create([
        'user_id' => $user->id, 'filename' => $filename, 'status' => 'ok',
        'output_path' => $path, 'output_name' => $filename . '.xlsx', 'rows_written' => 1,
    ]);
    if ($createdAt) {
        $r->forceFill(['created_at' => $createdAt])->saveQuietly();
    }
    return $r;
}

afterEach(fn () => array_map('unlink', glob(storage_path('app/excel_purge_test_*.xlsx')) ?: []));

it('purges reports older than the retention and deletes their files', function () {
    config(['services.excel_pipeline.retention_days' => 30]);
    $user = User::factory()->create();
    $old = okReportWithFile($user, 'old.csv', now()->subDays(31));
    $recent = okReportWithFile($user, 'recent.csv', now()->subDays(29));
    $oldPath = $old->output_path;

    $n = app(ExcelReportPurger::class)->purge();

    expect($n)->toBe(1)
        ->and(ExcelReport::find($old->id))->toBeNull()
        ->and(is_file($oldPath))->toBeFalse()
        ->and(ExcelReport::find($recent->id))->not->toBeNull()
        ->and(is_file($recent->output_path))->toBeTrue();
});

it('does nothing when retention is disabled', function () {
    config(['services.excel_pipeline.retention_days' => 0]);
    $user = User::factory()->create();
    okReportWithFile($user, 'old.csv', now()->subDays(400));

    expect(app(ExcelReportPurger::class)->purge())->toBe(0)
        ->and(ExcelReport::count())->toBe(1);
});

it('exposes the purge as an artisan command with --days', function () {
    $user = User::factory()->create();
    okReportWithFile($user, 'old.csv', now()->subDays(8));
    okReportWithFile($user, 'recent.csv', now()->subDays(2));

    $this->artisan('bmn:purge', ['--days' => 7])
        ->expectsOutputToContain('1 dispo(s) supprimée(s)')
        ->assertSuccessful();

    expect(ExcelReport::count())->toBe(1);
});

it('lets the author delete their own report and removes the file', function () {
    $user = User::factory()->create();
    $report = okReportWithFile($user, 'mine.csv');
    $path = $report->output_path;

    Livewire::actingAs($user)
        ->test(ExcelReportUploader::class)
        ->call('delete', $report->id)
        ->assertHasNoErrors()
        ->assertSee('supprimé de l');

    expect(ExcelReport::find($report->id))->toBeNull()
        ->and(is_file($path))->toBeFalse();
});

it('forbids deleting another user report unless admin', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $report = okReportWithFile($owner, 'owner.csv');

    Livewire::actingAs($other)
        ->test(ExcelReportUploader::class)
        ->call('delete', $report->id)
        ->assertForbidden();
    expect(ExcelReport::find($report->id))->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ExcelReportUploader::class)
        ->call('delete', $report->id)
        ->assertHasNoErrors();
    expect(ExcelReport::find($report->id))->toBeNull();
});

it('refuses to delete a report still processing', function () {
    $user = User::factory()->create();
    $report = ExcelReport::create(['user_id' => $user->id, 'filename' => 'p.csv', 'status' => 'processing']);

    Livewire::actingAs($user)
        ->test(ExcelReportUploader::class)
        ->call('delete', $report->id)
        ->assertHasErrors(['delete']);
    expect(ExcelReport::find($report->id))->not->toBeNull();
});
