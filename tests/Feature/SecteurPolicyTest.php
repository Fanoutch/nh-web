<?php

use App\Models\Secteur;
use App\Models\User;

function utilisateurAvecProfil(string $profil): User
{
    return match ($profil) {
        'non-membre' => User::factory()->create(),
        'utilisateur' => membreSecteur(secteurBmn(), 'utilisateur'),
        'chef' => membreSecteur(secteurBmn(), 'chef'),
        'admin' => User::factory()->create(['is_admin' => true]),
        'super admin' => User::factory()->create(['is_super_admin' => true]),
    };
}

it('applies the secteur rules', function (string $profil, bool $view, bool $consulter, bool $gerer) {
    $user = utilisateurAvecProfil($profil);
    $bmn = secteurBmn();

    expect($user->can('view', $bmn))->toBe($view)
        ->and($user->can('consulterDispos', $bmn))->toBe($consulter)
        ->and($user->can('gererDispos', $bmn))->toBe($gerer);
})->with([
    'non-membre' => ['non-membre', false, false, false],
    'utilisateur' => ['utilisateur', true, true, false],
    'chef' => ['chef', true, true, true],
    'admin' => ['admin', true, true, true],
    'super admin' => ['super admin', true, true, true],
]);

it('gives a chef of another secteur no right on BMN', function () {
    $test = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $chefAilleurs = membreSecteur($test, 'chef');

    expect($chefAilleurs->can('view', secteurBmn()))->toBeFalse()
        ->and($chefAilleurs->can('gererDispos', secteurBmn()))->toBeFalse()
        ->and($chefAilleurs->can('gererDispos', $test))->toBeTrue();
});
