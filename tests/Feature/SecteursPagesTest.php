<?php

use App\Livewire\ExcelReportUploader;
use App\Models\Secteur;
use App\Models\User;

it('redirects guests to login', function () {
    $this->get(route('secteurs.index'))->assertRedirect(route('login'));
});

it('lists only the secteurs the user can access, with the role', function () {
    Secteur::create(['slug' => 'test', 'nom' => 'Autre Secteur']);
    $chef = membreSecteur(secteurBmn(), 'chef');

    $this->actingAs($chef)->get(route('secteurs.index'))
        ->assertOk()
        ->assertSee('BMN')
        ->assertSee('Chef')
        ->assertDontSee('Autre Secteur');
});

it('shows every secteur to an admin, labelled Admin', function () {
    Secteur::create(['slug' => 'test', 'nom' => 'Autre Secteur']);
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get(route('secteurs.index'))
        ->assertOk()
        ->assertSee('BMN')
        ->assertSee('Autre Secteur')
        ->assertViewHas('cartes', fn ($cartes) => $cartes->pluck('role')->unique()->values()->all() === ['Admin']);
});

it('shows an empty message when the user has no secteur', function () {
    $this->actingAs(User::factory()->create())->get(route('secteurs.index'))
        ->assertOk()
        ->assertSee('Aucun secteur accessible.');
});

it('forbids the secteur pages to non members', function () {
    $user = User::factory()->create();
    $bmn = secteurBmn();

    $this->actingAs($user)->get(route('secteurs.show', $bmn))->assertForbidden();
    $this->actingAs($user)->get(route('secteurs.disponibilites', $bmn))->assertForbidden();
    $this->actingAs($user)->get(route('secteurs.assistant', $bmn))->assertForbidden();
});

it('returns 404 for an unknown secteur', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)->get('/secteurs/inconnu')->assertNotFound();
});

it('redirects the secteur page to the Disponibilités tab', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    $this->actingAs($user)->get('/secteurs/bmn')->assertRedirect('/secteurs/bmn/disponibilites');
});

it('renders the Disponibilités tab with both tabs', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    $this->actingAs($user)->get('/secteurs/bmn/disponibilites')
        ->assertOk()
        ->assertSee('BMN')
        ->assertSee('Disponibilités')
        ->assertSee('Assistant IA')
        ->assertSeeLivewire(ExcelReportUploader::class);
});

it('shows the Assistant IA tab as coming soon', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    $this->actingAs($user)->get('/secteurs/bmn/assistant')
        ->assertOk()
        ->assertSee('Assistant IA')
        ->assertSee('Bientôt disponible');
});

it('shows the Secteurs menu entry only to users with an accessible secteur', function () {
    $membre = membreSecteur(secteurBmn(), 'utilisateur');
    $personne = User::factory()->create();

    $this->actingAs($membre)->get(route('machines.index'))->assertSee(route('secteurs.index'));
    $this->actingAs($personne)->get(route('machines.index'))->assertDontSee(route('secteurs.index'));
});
