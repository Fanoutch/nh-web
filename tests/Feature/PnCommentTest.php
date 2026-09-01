<?php

use App\Livewire\PannesConserveesTable;
use App\Livewire\PannesOccurrentesTable;
use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Models\User;
use Livewire\Livewire;

function pnCommentFixtures(): array {
    $m = Machine::create(['hc_id' => 'NH08']);
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => '9001', 'num' => '101',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT', 'is_non_vol' => false,
    ]);
    $te = TechnicalEvent::create([
        'flight_id' => $flight->id, 'technical_event_id' => 'OCC1',
        'raise_datetime' => now(), 'status' => 'conservee',
        'iso_week' => '2026-W30', 'details' => [],
        'nombre_occurrences' => 3,
    ]);
    return [$flight, $te];
}

it('persists pn_comment on a technical event', function () {
    [, $te] = pnCommentFixtures();

    $te->update(['pn_comment' => 'Vibration ressentie en vol']);

    expect($te->refresh()->pn_comment)->toBe('Vibration ressentie en vol');
});

it('saves pn_comment together with the PN validation', function () {
    [$flight, $te] = pnCommentFixtures();
    $pn = User::factory()->create(['is_personnel_navigant' => true]);

    Livewire::actingAs($pn)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->set("comments.{$te->id}", 'Vibration ressentie en vol')
        ->call('setPnValidation', $te->id, 'confirmed');

    $te->refresh();
    expect($te->pn_validation_status)->toBe('confirmed')
        ->and($te->pn_comment)->toBe('Vibration ressentie en vol');
});

it('stores null when the PN validates without a comment', function () {
    [$flight, $te] = pnCommentFixtures();
    $pn = User::factory()->create(['is_personnel_navigant' => true]);

    Livewire::actingAs($pn)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->set("comments.{$te->id}", '   ')
        ->call('setPnValidation', $te->id, 'rejected');

    expect($te->refresh()->pn_comment)->toBeNull();
});

it('shows the pn_comment in the PN occurrentes table', function () {
    [$flight, $te] = pnCommentFixtures();
    $te->update(['pn_validation_status' => 'confirmed', 'pn_comment' => 'Vibration ressentie en vol']);
    $pn = User::factory()->create(['is_personnel_navigant' => true]);

    Livewire::actingAs($pn)
        ->test(PannesOccurrentesTable::class, ['flight' => $flight])
        ->assertSee('Vibration ressentie en vol');
});

it('shows the pn_comment in the admin pannes conservees table', function () {
    [$flight, $te] = pnCommentFixtures();
    $te->update(['pn_validation_status' => 'confirmed', 'pn_comment' => 'Vibration ressentie en vol']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(PannesConserveesTable::class, ['flight' => $flight])
        ->assertSee('Vibration ressentie en vol');
});
