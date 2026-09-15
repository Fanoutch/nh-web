<?php

use App\Livewire\AdminUsersTable;
use App\Models\User;
use Livewire\Livewire;

function superAdmin(): User
{
    return User::factory()->create(['is_super_admin' => true, 'is_admin' => true]);
}

it('lets a super admin make a user chef, then utilisateur, then remove access', function () {
    $sa = superAdmin();
    $u = User::factory()->create(['name' => 'Jean Test']);
    $bmn = secteurBmn();

    $step1 = Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $u->id, $bmn->id, 'chef');
    expect($u->roleDans($bmn))->toBe('chef');
    $step1->assertSee('Jean Test est maintenant chef BMN.');

    $step2 = Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $u->id, $bmn->id, 'utilisateur');
    expect($u->roleDans($bmn))->toBe('utilisateur')
        ->and($u->secteurs()->count())->toBe(1);
    $step2->assertSee('Jean Test est maintenant utilisateur BMN.');

    $step3 = Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $u->id, $bmn->id, 'aucun');
    expect($u->roleDans($bmn))->toBeNull();
    $step3->assertSee("Jean Test n'a plus accès à BMN.");
});

it('refuses a simple admin', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $u = User::factory()->create();

    $result = Livewire::actingAs($admin)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $u->id, secteurBmn()->id, 'chef');

    expect($u->roleDans(secteurBmn()))->toBeNull();
    $result->assertSee('Seul un super admin peut modifier les rôles de secteur.');
});

it('refuses to change your own secteur roles', function () {
    $sa = superAdmin();

    Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $sa->id, secteurBmn()->id, 'chef');

    expect($sa->roleDans(secteurBmn()))->toBeNull();
});

it('refuses an unknown role', function () {
    $sa = superAdmin();
    $u = User::factory()->create();

    Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->call('setRoleSecteur', $u->id, secteurBmn()->id, 'patron');

    expect($u->roleDans(secteurBmn()))->toBeNull();
});

it('filters users by secteur', function () {
    $sa = superAdmin();
    $bmn = secteurBmn();
    membreSecteur($bmn, 'utilisateur', ['name' => 'Marie Membre']);
    User::factory()->create(['name' => 'Paul Personne']);

    Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->set('roleFilter', 'secteur-' . $bmn->id)
        ->assertSee('Marie Membre')
        ->assertDontSee('Paul Personne');
});

it('shows the current secteur role in the table', function () {
    $sa = superAdmin();
    membreSecteur(secteurBmn(), 'chef', ['name' => 'Claire Chef']);

    Livewire::actingAs($sa)->test(AdminUsersTable::class)
        ->assertSee('Secteurs')
        ->assertSeeHtml('<option value="chef" selected>Chef</option>');
});
