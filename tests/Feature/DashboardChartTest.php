<?php

use App\Livewire\DashboardChart;
use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Models\User;
use App\Models\WeeklyAggregate;
use Livewire\Livewire;

it('counts only pannes conservées from flights within the last 30 days', function () {
    $machine = Machine::create(['hc_id' => 'NH10']);

    // Vol récent (il y a 5 jours) → la panne conservée doit être comptée
    $recent = Flight::create([
        'machine_id' => $machine->id, 'dsn' => '1', 'num' => '1',
        'start_datetime' => now()->subDays(5), 'end_datetime' => now()->subDays(5),
        'flight_type' => 'FLIGHT',
    ]);
    TechnicalEvent::create([
        'flight_id' => $recent->id, 'technical_event_id' => 'REC001',
        'raise_datetime' => now()->subDays(5), 'status' => 'conservee', 'iso_week' => '2026-W24',
        'details' => ['FailureCode' => '46830'],
    ]);

    // Vol ancien (il y a 45 jours) → la panne conservée doit être exclue
    $old = Flight::create([
        'machine_id' => $machine->id, 'dsn' => '2', 'num' => '2',
        'start_datetime' => now()->subDays(45), 'end_datetime' => now()->subDays(45),
        'flight_type' => 'FLIGHT',
    ]);
    TechnicalEvent::create([
        'flight_id' => $old->id, 'technical_event_id' => 'OLD001',
        'raise_datetime' => now()->subDays(45), 'status' => 'conservee', 'iso_week' => '2026-W18',
        'details' => ['FailureCode' => '46830'],
    ]);

    $kpis = (new DashboardChart)->getKpisProperty();

    expect($kpis['total_conservees'])->toBe(1);
});

it('validates required fields before displaying chart', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DashboardChart::class)
        ->call('displayChart')
        ->assertHasErrors(['machineId', 'startDate', 'endDate']);
});

it('computes chart data from weekly_aggregates for the selected range', function () {
    $user = User::factory()->create();
    $machine = Machine::create(['hc_id' => 'NH08']);

    WeeklyAggregate::create([
        'machine_id' => $machine->id, 'year' => 2026, 'iso_week' => '2026-W05',
        'total_pannes' => 10, 'total_flight_hours' => 2.5,
    ]);
    WeeklyAggregate::create([
        'machine_id' => $machine->id, 'year' => 2026, 'iso_week' => '2026-W06',
        'total_pannes' => 7, 'total_flight_hours' => 3.2,
    ]);

    $component = Livewire::actingAs($user)
        ->test(DashboardChart::class)
        ->set('machineId', $machine->id)
        ->set('startDate', '2026-01-26')  // semaine W05
        ->set('endDate', '2026-02-08')    // semaine W06
        ->call('displayChart');

    $data = $component->get('chartData');
    expect($data)->not->toBeEmpty();
    expect($data['weeks'])->toContain('2026-W05')->toContain('2026-W06');
    expect($data['pannes'])->toContain(10)->toContain(7);
});

it('marks empty weeks as no_data', function () {
    $user = User::factory()->create();
    $machine = Machine::create(['hc_id' => 'NH09']);

    WeeklyAggregate::create([
        'machine_id' => $machine->id, 'year' => 2026, 'iso_week' => '2026-W05',
        'total_pannes' => 5, 'total_flight_hours' => 1,
    ]);

    $component = Livewire::actingAs($user)
        ->test(DashboardChart::class)
        ->set('machineId', $machine->id)
        ->set('startDate', '2026-01-26')
        ->set('endDate', '2026-02-15')  // couvre W05, W06, W07
        ->call('displayChart');

    $data = $component->get('chartData');
    expect($data['noData'])->toContain('2026-W06');
    expect($data['noData'])->toContain('2026-W07');
    expect($data['noData'])->not->toContain('2026-W05');
});
