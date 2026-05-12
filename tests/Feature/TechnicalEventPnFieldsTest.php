<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Models\User;

it('defaults pn_validation_status to pending', function () {
    $m = Machine::create(['hc_id' => 'NH08']);
    $f = Flight::create([
        'machine_id' => $m->id, 'dsn' => '1', 'num' => '1',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);
    $te = TechnicalEvent::create([
        'flight_id' => $f->id, 'technical_event_id' => 'CODE1',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W19', 'details' => [],
    ]);

    expect($te->fresh()->pn_validation_status)->toBe('pending');
    expect($te->fresh()->pn_validated_by)->toBeNull();
    expect($te->fresh()->pn_validated_at)->toBeNull();
});

it('pnValidator relationship resolves to User', function () {
    $user = User::factory()->create();
    $m = Machine::create(['hc_id' => 'NH09']);
    $f = Flight::create([
        'machine_id' => $m->id, 'dsn' => '2', 'num' => '2',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);
    $te = TechnicalEvent::create([
        'flight_id' => $f->id, 'technical_event_id' => 'CODE2',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W19', 'details' => [],
        'pn_validation_status' => 'confirmed',
        'pn_validated_by' => $user->id,
        'pn_validated_at' => now(),
    ]);

    expect($te->fresh()->pnValidator->id)->toBe($user->id);
});
