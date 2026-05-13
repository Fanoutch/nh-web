<?php

use App\Livewire\PannesConserveesTable;
use App\Models\Flight;
use App\Models\Machine;
use App\Models\MissingPanne;
use App\Models\TechnicalEvent;
use App\Models\User;
use Livewire\Livewire;

function createFlightWithPanne(User $user): array {
    $m = Machine::create(['hc_id' => 'NH08']);
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => '1243', 'num' => '612',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);
    $te = TechnicalEvent::create([
        'flight_id' => $flight->id,
        'technical_event_id' => 'FIBIT001',
        'raise_datetime' => now(),
        'status' => 'conservee',
        'iso_week' => '2026-W05',
        'details' => ['FailureCode' => '46830', 'TechnicalEventDescription' => 'Test panne'],
    ]);
    return [$flight, $te];
}

it('changes validation status when clicked', function () {
    $user = User::factory()->create();
    [$flight, $te] = createFlightWithPanne($user);

    Livewire::actingAs($user)
        ->test(PannesConserveesTable::class, ['flight' => $flight])
        ->call('setValidation', $te->id, 'validated');

    expect($te->refresh()->validation_status)->toBe('validated');
    expect($te->validated_by)->toBe($user->id);
});

it('submits a missing panne via modal', function () {
    $user = User::factory()->create();
    [$flight] = createFlightWithPanne($user);

    Livewire::actingAs($user)
        ->test(PannesConserveesTable::class, ['flight' => $flight])
        ->set('newFailureCode', '12-345')
        ->set('newDescription', 'Test desc')
        ->call('submitMissingPanne')
        ->assertSet('showMissingModal', false);

    expect(MissingPanne::count())->toBe(1);
    expect(MissingPanne::first()->failure_code)->toBe('12-345');
});

it('validates required failure code when signalling missing panne', function () {
    $user = User::factory()->create();
    [$flight] = createFlightWithPanne($user);

    Livewire::actingAs($user)
        ->test(PannesConserveesTable::class, ['flight' => $flight])
        ->set('newFailureCode', '')
        ->call('submitMissingPanne')
        ->assertHasErrors(['newFailureCode' => 'required']);

    expect(MissingPanne::count())->toBe(0);
});

it('displays a PN-confirmed badge with validator name when set', function () {
    $user = User::factory()->create();
    $pn   = User::factory()->create(['name' => 'Jean Pilote', 'is_personnel_navigant' => true]);
    [$flight, $te] = createFlightWithPanne($user);

    $te->update([
        'pn_validation_status' => 'confirmed',
        'pn_validated_by' => $pn->id,
        'pn_validated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\PannesConserveesTable::class, ['flight' => $flight])
        ->assertSee('Confirmé en vol')
        ->assertSee('Jean Pilote');
});

it('displays a PN-rejected badge when set', function () {
    $user = User::factory()->create();
    $pn   = User::factory()->create(['name' => 'Marie Pilote', 'is_personnel_navigant' => true]);
    [$flight, $te] = createFlightWithPanne($user);

    $te->update([
        'pn_validation_status' => 'rejected',
        'pn_validated_by' => $pn->id,
        'pn_validated_at' => now(),
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test(\App\Livewire\PannesConserveesTable::class, ['flight' => $flight])
        ->assertSee('Rejeté en vol')
        ->assertSee('Marie Pilote');
});
