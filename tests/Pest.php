<?php

use App\Models\Secteur;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

function something()
{
    //
}

/** Le secteur BMN, créé par la migration create_secteurs_table. */
function secteurBmn(): Secteur
{
    return Secteur::where('slug', 'bmn')->firstOrFail();
}

/** Crée un utilisateur membre du secteur avec le rôle donné ('chef' | 'utilisateur'). */
function membreSecteur(Secteur $secteur, string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $secteur->users()->attach($user->id, ['role' => $role]);

    return $user;
}
