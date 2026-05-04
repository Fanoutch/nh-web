<?php

use App\Models\Machine;
use App\Models\RecurrentFailure;

it('creates a recurrent failure with cast attributes', function () {
    $machine = Machine::create(['hc_id' => 'NH99']);

    $rf = RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'FFP1000011A',
        'status' => 'active',
        'te_description' => 'SONAR 1 LOSS',
        'description' => 'ROTORS FLIGHT CONTROL',
        'system_description' => 'Flight Control System',
        'type_description' => 'Fault Code',
        'failure_code' => '1000011',
        'active_depuis_vol' => 'NH99_2026-05-01_001',
        'active_depuis_date' => '2026-05-01',
        'first_apparition' => '2026-04-28T08:00:00',
        'score' => 2,
        'details' => ['raw' => 'value'],
    ]);

    expect($rf->machine->hc_id)->toBe('NH99');
    expect($rf->active_depuis_date->format('Y-m-d'))->toBe('2026-05-01');
    expect($rf->first_apparition->format('Y-m-d H:i:s'))->toBe('2026-04-28 08:00:00');
    expect($rf->details)->toBe(['raw' => 'value']);
});

it('exposes recurrentFailures relation on Machine', function () {
    $machine = Machine::create(['hc_id' => 'NH98']);
    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE1',
        'status' => 'active',
    ]);
    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE2',
        'status' => 'active',
    ]);

    expect($machine->recurrentFailures)->toHaveCount(2);
});
