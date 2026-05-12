<?php

use App\Models\User;

it('defaults is_personnel_navigant to false for new users', function () {
    $user = User::factory()->create();
    expect($user->fresh()->is_personnel_navigant)->toBeFalse();
});

it('exposes isPersonnelNavigant() helper', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    expect($user->isPersonnelNavigant())->toBeTrue();
    $user2 = User::factory()->create();
    expect($user2->isPersonnelNavigant())->toBeFalse();
});

it('can flag an admin as personnel navigant independently', function () {
    $u = User::factory()->create(['is_admin' => true, 'is_personnel_navigant' => true]);
    expect($u->isAdmin())->toBeTrue();
    expect($u->isPersonnelNavigant())->toBeTrue();
});
