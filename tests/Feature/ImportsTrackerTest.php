<?php

use App\Livewire\ImportsTracker;
use App\Models\Import;
use App\Models\User;
use Livewire\Livewire;

it('lists imports of the authenticated user only', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Import::create(['user_id' => $user->id, 'filename' => 'mine.xml', 'status' => 'ok']);
    Import::create(['user_id' => $other->id, 'filename' => 'theirs.xml', 'status' => 'ok']);

    Livewire::actingAs($user)
        ->test(ImportsTracker::class)
        ->assertSee('mine.xml')
        ->assertDontSee('theirs.xml');
});

it('filters by status', function () {
    $user = User::factory()->create();
    Import::create(['user_id' => $user->id, 'filename' => 'ok.xml', 'status' => 'ok']);
    Import::create(['user_id' => $user->id, 'filename' => 'pend.xml', 'status' => 'pending']);
    Import::create(['user_id' => $user->id, 'filename' => 'err.xml', 'status' => 'error']);

    Livewire::actingAs($user)
        ->test(ImportsTracker::class)
        ->call('setFilter', 'errors')
        ->assertSee('err.xml')
        ->assertDontSee('ok.xml')
        ->assertDontSee('pend.xml');
});
