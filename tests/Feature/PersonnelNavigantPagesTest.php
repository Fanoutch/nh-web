<?php

use App\Models\Machine;
use App\Models\User;

it('index route exists and is accessible to a PN', function () {
    Machine::create(['hc_id' => 'NH08']);
    $u = User::factory()->create(['is_personnel_navigant' => true]);
    $this->actingAs($u)->get(route('personnel-navigant.index'))->assertOk();
});

it('show route exists and is accessible to a PN', function () {
    $m = Machine::create(['hc_id' => 'NH08']);
    $u = User::factory()->create(['is_personnel_navigant' => true]);
    $this->actingAs($u)->get(route('personnel-navigant.show', $m->hc_id))->assertOk();
});

it('index displays all machine hc_ids sorted ascending', function () {
    Machine::create(['hc_id' => 'NH09']);
    Machine::create(['hc_id' => 'NH08']);
    Machine::create(['hc_id' => 'NH03']);
    $u = User::factory()->create(['is_personnel_navigant' => true]);

    $response = $this->actingAs($u)->get(route('personnel-navigant.index'));

    $response->assertOk();
    $response->assertSeeInOrder(['NH03', 'NH08', 'NH09']);
});

it('show page lists flights of the machine and links to pannes', function () {
    $m = \App\Models\Machine::create(['hc_id' => 'NH08']);
    $f1 = \App\Models\Flight::create([
        'machine_id' => $m->id, 'dsn' => '9001', 'num' => '101',
        'start_datetime' => now()->subDay(), 'end_datetime' => now()->subDay()->addHour(),
        'flight_type' => 'FLIGHT', 'is_non_vol' => false,
    ]);
    $u = User::factory()->create(['is_personnel_navigant' => true]);

    $response = $this->actingAs($u)->get(route('personnel-navigant.show', 'NH08'));

    $response->assertOk();
    $response->assertSee('9001'); // DSN
    $response->assertSee(route('personnel-navigant.pannes', $f1));
});
