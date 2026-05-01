<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Models\TechnicalEvent;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('renders /machines with active recurrent failures eager-loaded', function () {
    $machine = Machine::create(['hc_id' => 'NH50']);

    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE_RF1',
        'status' => 'active',
        'te_description' => 'RECURRENT EVENT 1',
        'description' => 'ROTORS FLIGHT CONTROL',
        'system_description' => 'Flight Control System',
        'type_description' => 'Fault Code',
        'score' => 3,
    ]);

    $response = $this->actingAs($this->user)->get('/machines');

    $response->assertOk();
    $response->assertSee('NH50');
    $response->assertSee('RECURRENT EVENT 1');
});

it('renders pannes from the last flight in the row', function () {
    foreach (['NH51' => 'LAST FLIGHT EVENT 1', 'NH52' => 'LAST FLIGHT EVENT 2'] as $hcId => $eventLabel) {
        $machine = Machine::create(['hc_id' => $hcId]);
        $flight = Flight::create([
            'machine_id' => $machine->id,
            'dsn' => 'D-' . $hcId, 'num' => 'N-' . $hcId,
            'start_datetime' => '2026-04-30 08:00:00',
            'end_datetime' => '2026-04-30 10:00:00',
            'flight_type' => 'FLIGHT',
            'flight_hours' => 2.0,
            'is_non_vol' => false,
        ]);
        TechnicalEvent::create([
            'flight_id' => $flight->id,
            'technical_event_id' => 'TE_' . $hcId,
            'raise_datetime' => '2026-04-30 09:30:00',
            'status' => 'conservee',
            'iso_week' => '2026-W18',
            'nombre_occurrences' => 5,
            'details' => [
                'TechnicalEventDescription' => $eventLabel,
                'Description' => 'ENGINE',
                'SystemDescription' => 'Engine System',
                'TypeDescription' => 'Fault Code',
            ],
        ]);
    }

    $response = $this->actingAs($this->user)->get('/machines');

    $response->assertOk();
    $response->assertSee('NH51');
    $response->assertSee('LAST FLIGHT EVENT 1');
    $response->assertSee('NH52');
    $response->assertSee('LAST FLIGHT EVENT 2');
});
