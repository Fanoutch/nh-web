<?php

use App\Models\Flight;
use App\Models\Import;
use App\Models\Machine;
use App\Models\MissingPanne;
use App\Models\TechnicalEvent;
use App\Models\User;
use App\Models\WeeklyAggregate;

it('creates a machine and enforces unique hc_id', function () {
    $m = Machine::create(['hc_id' => 'NH08']);
    expect($m->hc_id)->toBe('NH08');

    $this->expectException(\Illuminate\Database\QueryException::class);
    Machine::create(['hc_id' => 'NH08']);
});

it('creates a flight linked to a machine with unique key', function () {
    $m = Machine::create(['hc_id' => 'NH09']);
    $flight = Flight::create([
        'machine_id' => $m->id,
        'dsn' => '1243', 'num' => '612',
        'start_datetime' => now()->subHour(),
        'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
        'flight_hours' => 1.5,
    ]);
    expect($flight->machine->hc_id)->toBe('NH09');

    $this->expectException(\Illuminate\Database\QueryException::class);
    Flight::create([
        'machine_id' => $m->id, 'dsn' => '1243', 'num' => '612',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);
});

it('creates a technical event with jsonb details', function () {
    $m = Machine::create(['hc_id' => 'NH10']);
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => '1244', 'num' => '100',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);
    $te = TechnicalEvent::create([
        'flight_id' => $flight->id,
        'technical_event_id' => 'FIBIT46830A',
        'raise_datetime' => now(),
        'status' => 'conservee',
        'iso_week' => '2026-W05',
        'details' => ['FailureCode' => '46830', 'SystemType' => 'MISSION'],
    ]);
    expect($te->details['FailureCode'])->toBe('46830');
    expect($flight->technicalEvents()->count())->toBe(1);
});

it('creates missing panne and weekly aggregate and import', function () {
    $user = User::factory()->create();
    $m = Machine::create(['hc_id' => 'NH11']);
    $flight = Flight::create([
        'machine_id' => $m->id, 'dsn' => '1245', 'num' => '200',
        'start_datetime' => now(), 'end_datetime' => now(),
        'flight_type' => 'FLIGHT',
    ]);

    $mp = MissingPanne::create([
        'flight_id' => $flight->id, 'failure_code' => 'XYZ',
        'reported_by' => $user->id, 'reported_at' => now(),
    ]);
    expect($mp->reporter->id)->toBe($user->id);

    $agg = WeeklyAggregate::create([
        'machine_id' => $m->id, 'year' => 2026,
        'iso_week' => '2026-W05', 'total_pannes' => 22, 'total_flight_hours' => 2.71,
    ]);
    expect($agg->machine->hc_id)->toBe('NH11');

    $import = Import::create([
        'user_id' => $user->id, 'filename' => 'foo.xml', 'status' => 'pending',
    ]);
    expect($import->user->id)->toBe($user->id);
    expect($import->status)->toBe('pending');
});
