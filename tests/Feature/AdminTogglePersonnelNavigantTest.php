<?php

use App\Livewire\AdminUsersTable;
use App\Models\User;
use Livewire\Livewire;

it('super admin can toggle is_personnel_navigant on a user', function () {
    $sa = User::factory()->create(['is_super_admin' => true, 'is_admin' => true]);
    $u = User::factory()->create();

    Livewire::actingAs($sa)
        ->test(AdminUsersTable::class)
        ->call('togglePersonnelNavigant', $u->id);

    expect($u->refresh()->is_personnel_navigant)->toBeTrue();

    Livewire::actingAs($sa)
        ->test(AdminUsersTable::class)
        ->call('togglePersonnelNavigant', $u->id);

    expect($u->refresh()->is_personnel_navigant)->toBeFalse();
});

it('non-super-admin cannot toggle PN', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $u = User::factory()->create();

    Livewire::actingAs($admin)
        ->test(AdminUsersTable::class)
        ->call('togglePersonnelNavigant', $u->id);

    expect($u->refresh()->is_personnel_navigant)->toBeFalse();
});

it('filter "pn" returns only Personnel Navigant users', function () {
    $sa = User::factory()->create(['is_super_admin' => true, 'is_admin' => true]);
    $pn = User::factory()->create(['name' => 'Pierre PN', 'is_personnel_navigant' => true]);
    $tech = User::factory()->create(['name' => 'Thierry Tech']);

    Livewire::actingAs($sa)
        ->test(AdminUsersTable::class)
        ->set('roleFilter', 'pn')
        ->assertSee('Pierre PN')
        ->assertDontSee('Thierry Tech');
});

it('filter "technicien" excludes PNs', function () {
    $sa = User::factory()->create(['is_super_admin' => true, 'is_admin' => true]);
    $pn = User::factory()->create(['name' => 'Pierre PN', 'is_personnel_navigant' => true]);
    $tech = User::factory()->create(['name' => 'Thierry Tech']);

    Livewire::actingAs($sa)
        ->test(AdminUsersTable::class)
        ->set('roleFilter', 'technicien')
        ->assertSee('Thierry Tech')
        ->assertDontSee('Pierre PN');
});
