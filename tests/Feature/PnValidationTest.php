<?php

use App\Livewire\PannesOccurrentesTable;
use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Models\User;
use Livewire\Livewire;

function pnFixtures(): array {
    $m = Machine::create(['hc_id' => 'NH08']);
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => '9001', 'num' => '101',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT', 'is_non_vol' => false,
    ]);
    $occurrent = TechnicalEvent::create([
        'flight_id' => $flight->id, 'technical_event_id' => 'OCC1',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W19', 'details' => [],
        'nombre_occurrences' => 3,
    ]);
    $oneShot = TechnicalEvent::create([
        'flight_id' => $flight->id, 'technical_event_id' => 'SINGLE',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W19', 'details' => [],
        'nombre_occurrences' => 1,
    ]);
    return [$flight, $occurrent, $oneShot];
}

it('lists only TechnicalEvents with nombre_occurrences > 1', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight, $occurrent, $oneShot] = pnFixtures();

    Livewire::actingAs($user)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->assertSee('OCC1')
        ->assertDontSee('SINGLE');
});

it('PN can confirm an occurrence', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight, $te] = pnFixtures();

    Livewire::actingAs($user)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->call('setPnValidation', $te->id, 'confirmed');

    expect($te->refresh()->pn_validation_status)->toBe('confirmed');
    expect($te->pn_validated_by)->toBe($user->id);
    expect($te->pn_validated_at)->not->toBeNull();
});

it('PN can reject an occurrence', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight, $te] = pnFixtures();

    Livewire::actingAs($user)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->call('setPnValidation', $te->id, 'rejected');

    expect($te->refresh()->pn_validation_status)->toBe('rejected');
});

it('rejects unknown validation status with 422', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight, $te] = pnFixtures();

    Livewire::actingAs($user)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->call('setPnValidation', $te->id, 'bogus')
        ->assertStatus(422);
});

it('refuses to validate a TechnicalEvent from another flight', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight] = pnFixtures();

    $otherMachine = Machine::create(['hc_id' => 'NH09']);
    $otherFlight = Flight::create([
        'machine_id' => $otherMachine->id, 'dsn' => '9999', 'num' => '999',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT', 'is_non_vol' => false,
    ]);
    $otherTe = TechnicalEvent::create([
        'flight_id' => $otherFlight->id, 'technical_event_id' => 'OTHER',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W19', 'details' => [], 'nombre_occurrences' => 2,
    ]);

    Livewire::actingAs($user)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->call('setPnValidation', $otherTe->id, 'confirmed')
        ->assertStatus(404);

    expect($otherTe->refresh()->pn_validation_status)->toBe('pending');
});

it('renders the pannes page through the route', function () {
    $user = User::factory()->create(['is_personnel_navigant' => true]);
    [$flight] = pnFixtures();

    $this->actingAs($user)
        ->get(route('personnel-navigant.pannes', $flight))
        ->assertOk()
        ->assertSee('OCC1');
});
