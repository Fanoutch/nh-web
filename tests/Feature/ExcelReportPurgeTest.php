<?php

use App\Livewire\ExcelReportUploader;
use App\Models\ExcelReport;
use App\Models\Secteur;
use App\Models\User;
use App\Services\ExcelReportPurger;
use Livewire\Livewire;

function okReportWithFile(User $user, string $filename, ?\DateTimeInterface $createdAt = null): ExcelReport
{
    $path = storage_path('app/excel_purge_test_' . uniqid() . '.xlsx');
    file_put_contents($path, 'x');
    $r = ExcelReport::create([
        'user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'filename' => $filename, 'status' => 'ok',
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

it('lets a chef delete a report and removes the file', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $auteur = membreSecteur($bmn, 'utilisateur');
    $report = okReportWithFile($auteur, 'mine.csv');
    $path = $report->output_path;

    Livewire::actingAs($chef)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->call('delete', $report->id)
        ->assertHasNoErrors()
        ->assertSee('supprimé de l');

    expect(ExcelReport::find($report->id))->toBeNull()
        ->and(is_file($path))->toBeFalse();
});

it('forbids a simple utilisateur to delete, even their own report, but lets an admin', function () {
    $bmn = secteurBmn();
    $utilisateur = membreSecteur($bmn, 'utilisateur');
    $admin = User::factory()->create(['is_admin' => true]);
    $report = okReportWithFile($utilisateur, 'owner.csv');

    Livewire::actingAs($utilisateur)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->assertDontSee('title="Supprimer"', false)
        ->call('delete', $report->id)
        ->assertForbidden();
    expect(ExcelReport::find($report->id))->not->toBeNull();

    Livewire::actingAs($admin)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->call('delete', $report->id)
        ->assertHasNoErrors();
    expect(ExcelReport::find($report->id))->toBeNull();
});

it('does not delete a report from another secteur', function () {
    $test = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $admin = User::factory()->create(['is_admin' => true]);
    $report = okReportWithFile($admin, 'autre.csv');
    $report->update(['secteur_id' => $test->id]);

    Livewire::actingAs($admin)
        ->test(ExcelReportUploader::class, ['secteur' => secteurBmn()])
        ->call('delete', $report->id);

    expect(ExcelReport::find($report->id))->not->toBeNull();
});

it('refuses to delete a report still processing', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $report = ExcelReport::create(['user_id' => $chef->id, 'secteur_id' => $bmn->id, 'filename' => 'p.csv', 'status' => 'processing']);

    Livewire::actingAs($chef)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->call('delete', $report->id)
        ->assertHasErrors(['delete']);
    expect(ExcelReport::find($report->id))->not->toBeNull();
});
