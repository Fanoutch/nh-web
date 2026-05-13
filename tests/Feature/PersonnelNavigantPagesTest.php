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
