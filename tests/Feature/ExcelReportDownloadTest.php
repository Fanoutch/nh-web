<?php

use App\Models\ExcelReport;
use App\Models\Secteur;
use App\Models\User;

function dispoTelechargeable(Secteur $secteur, User $user): ExcelReport
{
    $path = storage_path('app/excel_test_' . uniqid() . '.xlsx');
    file_put_contents($path, 'fake xlsx');

    return ExcelReport::create([
        'user_id' => $user->id, 'secteur_id' => $secteur->id, 'filename' => 'r.csv', 'status' => 'ok',
        'output_path' => $path, 'output_name' => 'rapport_du_jour.xlsx',
    ]);
}

afterEach(fn () => array_map('unlink', glob(storage_path('app/excel_test_*.xlsx')) ?: []));

it('lets members and admins download a dispo of the secteur', function () {
    $bmn = secteurBmn();
    $utilisateur = membreSecteur($bmn, 'utilisateur');
    $admin = User::factory()->create(['is_admin' => true]);
    $report = dispoTelechargeable($bmn, $utilisateur);

    $this->actingAs($utilisateur)
        ->get(route('secteurs.disponibilites.download', [$bmn, $report]))
        ->assertOk()
        ->assertDownload('rapport_du_jour.xlsx');

    $this->actingAs($admin)
        ->get(route('secteurs.disponibilites.download', [$bmn, $report]))
        ->assertOk();
});

it('forbids non members to download', function () {
    $bmn = secteurBmn();
    $report = dispoTelechargeable($bmn, membreSecteur($bmn, 'chef'));

    $this->actingAs(User::factory()->create())
        ->get(route('secteurs.disponibilites.download', [$bmn, $report]))
        ->assertForbidden();
});

it('returns 404 for a dispo of another secteur', function () {
    $test = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $admin = User::factory()->create(['is_admin' => true]);
    $report = dispoTelechargeable($test, $admin);

    $this->actingAs($admin)
        ->get(route('secteurs.disponibilites.download', [secteurBmn(), $report]))
        ->assertNotFound();
});

it('returns 404 when the report is not ready or the file is missing', function () {
    $bmn = secteurBmn();
    $user = membreSecteur($bmn, 'utilisateur');
    $pending = ExcelReport::create(['user_id' => $user->id, 'secteur_id' => $bmn->id, 'filename' => 'r.csv', 'status' => 'pending']);
    $gone = ExcelReport::create([
        'user_id' => $user->id, 'secteur_id' => $bmn->id, 'filename' => 'r.csv', 'status' => 'ok',
        'output_path' => storage_path('app/does_not_exist.xlsx'), 'output_name' => 'x.xlsx',
    ]);

    $this->actingAs($user)->get(route('secteurs.disponibilites.download', [$bmn, $pending]))->assertNotFound();
    $this->actingAs($user)->get(route('secteurs.disponibilites.download', [$bmn, $gone]))->assertNotFound();
});

it('requires authentication to download', function () {
    $bmn = secteurBmn();
    $report = dispoTelechargeable($bmn, User::factory()->create());

    $this->get(route('secteurs.disponibilites.download', [$bmn, $report]))->assertRedirect(route('login'));
});
