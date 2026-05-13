<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'personnel-navigant'])->get('/__test/pn', fn () => 'ok')
        ->name('test.pn');
});

it('returns 403 for a Technicien (no flags)', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/__test/pn')->assertForbidden();
});

it('returns 200 for a Personnel Navigant', function () {
    $u = User::factory()->create(['is_personnel_navigant' => true]);
    $this->actingAs($u)->get('/__test/pn')->assertOk();
});

it('returns 200 for an admin (bypass)', function () {
    $u = User::factory()->create(['is_admin' => true]);
    $this->actingAs($u)->get('/__test/pn')->assertOk();
});

it('returns 200 for a super admin (bypass)', function () {
    $u = User::factory()->create(['is_super_admin' => true, 'is_admin' => true]);
    $this->actingAs($u)->get('/__test/pn')->assertOk();
});

it('redirects guests to login', function () {
    $this->get('/__test/pn')->assertRedirect(route('login'));
});
