<?php

use App\Models\Secteur;
use App\Models\User;

function profilAssistant(string $profil): User
{
    return match ($profil) {
        'non-membre' => User::factory()->create(),
        'utilisateur' => membreSecteur(secteurBmn(), 'utilisateur'),
        'chef' => membreSecteur(secteurBmn(), 'chef'),
        'admin' => User::factory()->create(['is_admin' => true]),
        'super admin' => User::factory()->create(['is_super_admin' => true]),
    };
}

it('applies the assistant rules', function (string $profil, bool $documents, bool $interroger, bool $consulter) {
    $user = profilAssistant($profil);
    $bmn = secteurBmn();

    expect($user->can('gererDocuments', $bmn))->toBe($documents)
        ->and($user->can('interrogerAssistant', $bmn))->toBe($interroger)
        ->and($user->can('consulterDocument', $bmn))->toBe($consulter);
})->with([
    'non-membre' => ['non-membre', false, false, false],
    'utilisateur' => ['utilisateur', false, true, true],
    'chef' => ['chef', true, true, true],
    'admin' => ['admin', true, true, true],
    'super admin' => ['super admin', true, true, true],
]);

it('gives a chef of another secteur no assistant right on BMN', function () {
    $chefAilleurs = membreSecteur(Secteur::create(['slug' => 'test', 'nom' => 'Test']), 'chef');

    expect($chefAilleurs->can('gererDocuments', secteurBmn()))->toBeFalse()
        ->and($chefAilleurs->can('interrogerAssistant', secteurBmn()))->toBeFalse();
});
