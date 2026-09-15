<?php

use App\Models\Secteur;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('creates the BMN secteur through the migration', function () {
    expect(secteurBmn()->nom)->toBe('BMN')
        ->and(secteurBmn()->getRouteKey())->toBe('bmn');
});

it('returns the role of a member and null otherwise', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $utilisateur = membreSecteur($bmn, 'utilisateur');
    $personne = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);

    expect($chef->roleDans($bmn))->toBe('chef')
        ->and($utilisateur->roleDans($bmn))->toBe('utilisateur')
        ->and($personne->roleDans($bmn))->toBeNull()
        ->and($admin->roleDans($bmn))->toBeNull();
});

it('lets a user belong to several secteurs with different roles', function () {
    $bmn = secteurBmn();
    $test = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $user = membreSecteur($bmn, 'chef');
    $test->users()->attach($user->id, ['role' => 'utilisateur']);

    expect($user->roleDans($bmn))->toBe('chef')
        ->and($user->roleDans($test))->toBe('utilisateur')
        ->and($user->secteursAccessibles()->pluck('slug')->all())->toBe(['bmn', 'test']);
});

it('gives admins every secteur and non members none', function () {
    Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $admin = User::factory()->create(['is_admin' => true]);
    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $personne = User::factory()->create();

    expect($admin->secteursAccessibles()->pluck('slug')->all())->toBe(['bmn', 'test'])
        ->and($superAdmin->secteursAccessibles())->toHaveCount(2)
        ->and($personne->secteursAccessibles())->toBeEmpty();
});

it('refuses the same user twice in a secteur', function () {
    $bmn = secteurBmn();
    $user = membreSecteur($bmn, 'chef');

    $bmn->users()->attach($user->id, ['role' => 'utilisateur']);
})->throws(UniqueConstraintViolationException::class);
