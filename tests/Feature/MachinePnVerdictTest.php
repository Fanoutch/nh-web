<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Models\TechnicalEvent;
use App\Models\User;

function pnVerdictFixtures(): Machine {
    $m = Machine::create(['hc_id' => 'NH11']);
    RecurrentFailure::create([
        'machine_id' => $m->id, 'technical_event_id' => 'OCC1',
        'status' => 'active', 'score' => 2,
        'te_description' => 'Vibration transmission principale',
    ]);
    return $m;
}

function pnVerdictEvent(Machine $m, array $attrs = []): TechnicalEvent {
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => $attrs['dsn'] ?? '9100', 'num' => '101',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT', 'is_non_vol' => false,
    ]);
    return TechnicalEvent::create(array_merge([
        'flight_id' => $flight->id, 'technical_event_id' => 'OCC1',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W36', 'details' => [], 'nombre_occurrences' => 2,
    ], $attrs['event'] ?? []));
}

it('shows the latest PN verdict and comment for a panne occurrente on the machines page', function () {
    $m = pnVerdictFixtures();
    $pn = User::factory()->create(['name' => 'Dupont']);
    pnVerdictEvent($m, ['event' => [
        'pn_validation_status' => 'confirmed', 'pn_validated_by' => $pn->id,
        'pn_validated_at' => now(), 'pn_comment' => 'Vibration ressentie en montée',
    ]]);

    $this->actingAs(User::factory()->create())
        ->get(route('machines.index'))
        ->assertSee('Confirmé en vol')
        ->assertSee('Vibration ressentie en montée');
});

it('shows no PN verdict when no PN has validated the panne occurrente', function () {
    pnVerdictFixtures();

    $this->actingAs(User::factory()->create())
        ->get(route('machines.index'))
        ->assertDontSee('Confirmé en vol')
        ->assertDontSee('Rejeté en vol');
});

it('uses the most recent PN verdict when several exist for the same panne', function () {
    $m = pnVerdictFixtures();
    $pn = User::factory()->create();
    pnVerdictEvent($m, ['dsn' => '9101', 'event' => [
        'pn_validation_status' => 'rejected', 'pn_validated_by' => $pn->id,
        'pn_validated_at' => now()->subDays(3), 'pn_comment' => 'Ancien commentaire obsolète',
    ]]);
    pnVerdictEvent($m, ['dsn' => '9102', 'event' => [
        'pn_validation_status' => 'confirmed', 'pn_validated_by' => $pn->id,
        'pn_validated_at' => now(), 'pn_comment' => 'Commentaire le plus récent',
    ]]);

    $this->actingAs(User::factory()->create())
        ->get(route('machines.index'))
        ->assertSee('Commentaire le plus récent')
        ->assertDontSee('Ancien commentaire obsolète');
});

it('shows the détails button even with 3 or fewer pannes occurrentes', function () {
    pnVerdictFixtures();

    $this->actingAs(User::factory()->create())
        ->get(route('machines.index'))
        ->assertSee('détails');
});
