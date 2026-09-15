<?php

use App\Jobs\GenerateExcelReportJob;
use App\Livewire\ExcelReportUploader;
use App\Models\ExcelReport;
use App\Models\Secteur;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

it('redirects guests away from the disponibilites page', function () {
    $this->get(route('secteurs.disponibilites', secteurBmn()))->assertRedirect(route('login'));
});

it('stages a csv and dispatches the generation job when a chef submits', function () {
    Queue::fake();
    Storage::fake('local');

    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $file = UploadedFile::fake()->createWithContent('rapport_2026-09-14.csv', "a;b\n1;2\n");

    Livewire::actingAs($chef)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->set('csvFile', $file)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('csvFile', null)
        ->assertSee('rapport_2026-09-14.csv');

    $report = ExcelReport::first();
    expect($report)->not->toBeNull()
        ->and($report->user_id)->toBe($chef->id)
        ->and($report->secteur_id)->toBe($bmn->id)
        ->and($report->filename)->toBe('rapport_2026-09-14.csv')
        ->and($report->status)->toBe('pending');

    Queue::assertPushed(GenerateExcelReportJob::class, fn ($job) => $job->reportId === $report->id);
});

it('lets an admin who is not a member submit', function () {
    Queue::fake();
    Storage::fake('local');

    $admin = User::factory()->create(['is_admin' => true]);
    $file = UploadedFile::fake()->createWithContent('r.csv', "a;b\n1;2\n");

    Livewire::actingAs($admin)
        ->test(ExcelReportUploader::class, ['secteur' => secteurBmn()])
        ->set('csvFile', $file)
        ->call('submit')
        ->assertHasNoErrors();

    expect(ExcelReport::count())->toBe(1);
});

it('hides the drop zone from a simple utilisateur and forbids submit', function () {
    Queue::fake();
    Storage::fake('local');

    $bmn = secteurBmn();
    $utilisateur = membreSecteur($bmn, 'utilisateur');

    // Le dépôt du fichier lui-même est refusé (voir "forbids a utilisateur from setting csvFile" ci-dessus) ;
    // on vérifie ici que submit() reste protégé indépendamment de l'état de csvFile.
    Livewire::actingAs($utilisateur)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->assertDontSee('Glisser-déposer le CSV du jour')
        ->call('submit')
        ->assertForbidden();

    expect(ExcelReport::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects a file that is not a csv', function () {
    Queue::fake();

    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $file = UploadedFile::fake()->create('rapport.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    Livewire::actingAs($chef)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->set('csvFile', $file)
        ->assertHasErrors(['csvFile']);

    expect(ExcelReport::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('re-checks consulterDispos on every hydrate and forbids a member whose role was removed', function () {
    $bmn = secteurBmn();
    $utilisateur = membreSecteur($bmn, 'utilisateur');

    $component = Livewire::actingAs($utilisateur)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn]);

    secteurBmn()->users()->detach($utilisateur->id);

    $component->call('$refresh')->assertForbidden();
});

it('forbids a utilisateur from setting csvFile even though the drop zone is hidden', function () {
    Queue::fake();
    Storage::fake('local');

    $bmn = secteurBmn();
    $utilisateur = membreSecteur($bmn, 'utilisateur');
    $file = UploadedFile::fake()->createWithContent('r.csv', "a;b\n1;2\n");

    Livewire::actingAs($utilisateur)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->set('csvFile', $file)
        ->assertForbidden();

    expect(ExcelReport::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('checks the gererDispos ability once per render regardless of history size', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    foreach (range(1, 5) as $i) {
        ExcelReport::create(['user_id' => $chef->id, 'secteur_id' => $bmn->id, 'filename' => "r{$i}.csv", 'status' => 'ok']);
    }

    DB::enableQueryLog();
    Livewire::actingAs($chef)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn]);
    $roleQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'secteur_user'));
    DB::flushQueryLog();
    DB::disableQueryLog();

    expect($roleQueries)->toHaveCount(1);
});

it('lists only the dispos of the secteur, from every member', function () {
    $bmn = secteurBmn();
    $test = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $alice = membreSecteur($bmn, 'utilisateur', ['name' => 'Alice Test']);
    $bob = membreSecteur($bmn, 'chef', ['name' => 'Bob Test']);
    ExcelReport::create(['user_id' => $alice->id, 'secteur_id' => $bmn->id, 'filename' => 'a.csv', 'status' => 'ok', 'rows_written' => 12]);
    ExcelReport::create(['user_id' => $bob->id, 'secteur_id' => $bmn->id, 'filename' => 'b.csv', 'status' => 'error', 'message' => 'Colonnes manquantes dans le CSV']);
    ExcelReport::create(['user_id' => $bob->id, 'secteur_id' => $test->id, 'filename' => 'autre-secteur.csv', 'status' => 'ok']);

    Livewire::actingAs($alice)
        ->test(ExcelReportUploader::class, ['secteur' => $bmn])
        ->assertSee('a.csv')->assertSee('b.csv')
        ->assertSee('Bob Test')
        ->assertSee('Colonnes manquantes')
        ->assertDontSee('autre-secteur.csv');
});
