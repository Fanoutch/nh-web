<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Models\WeeklyAggregate;
use App\Services\WeeklyAggregatesRefresher;
use Carbon\Carbon;

it('produces a zero row for a machine with no flights', function () {
    $machine = Machine::create(['hc_id' => 'NH99']);

    $refresher = new WeeklyAggregatesRefresher();
    $result = $refresher->refresh($machine, ['2026-W05']);

    expect($result)->toHaveKey('2026-W05');
    $row = WeeklyAggregate::where(['machine_id' => $machine->id, 'iso_week' => '2026-W05'])->first();
    expect($row)->not->toBeNull();
    expect((int) $row->total_pannes)->toBe(0);
    expect((float) $row->total_flight_hours)->toBe(0.0);
    expect((int) $row->year)->toBe(2026);
});

it('counts only conservee technical events for total_pannes', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST1']);
    $flight = Flight::create([
        'machine_id' => $machine->id,
        'dsn' => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 09:00:00',
        'end_datetime'   => '2026-02-02 11:00:00',
        'flight_type' => 'FLIGHT',
        'flight_hours' => 2.0,
    ]);

    foreach (range(1, 3) as $i) {
        TechnicalEvent::create([
            'flight_id' => $flight->id,
            'technical_event_id' => "TE_C{$i}",
            'raise_datetime' => '2026-02-02 10:00:00',
            'status' => 'conservee',
            'iso_week' => '2026-W06',
            'details' => [],
        ]);
    }
    foreach (range(1, 2) as $i) {
        TechnicalEvent::create([
            'flight_id' => $flight->id,
            'technical_event_id' => "TE_I{$i}",
            'raise_datetime' => '2026-02-02 10:00:00',
            'status' => 'isolee',
            'iso_week' => '2026-W06',
            'details' => [],
        ]);
    }

    (new WeeklyAggregatesRefresher())->refresh($machine, ['2026-W06']);

    $row = WeeklyAggregate::where(['machine_id' => $machine->id, 'iso_week' => '2026-W06'])->first();
    expect((int) $row->total_pannes)->toBe(3);
});

it('sums flight_hours per iso_week, ignoring non-vol and flagged flights', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST2']);

    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 09:00:00', 'end_datetime' => '2026-02-02 11:30:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 2.5,
    ]);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D2', 'num' => '1',
        'start_datetime' => '2026-02-05 09:00:00', 'end_datetime' => '2026-02-05 10:15:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 1.25,
    ]);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D3', 'num' => '1',
        'start_datetime' => '2026-02-06 09:00:00', 'end_datetime' => '2026-02-06 09:30:00',
        'flight_type' => 'GROUND', 'flight_hours' => 0.0, 'is_non_vol' => true,
    ]);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D4', 'num' => '1',
        'start_datetime' => '2026-02-07 09:00:00', 'end_datetime' => '2026-02-07 10:00:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 1.0,
        'flagged_as_error' => true,
    ]);

    (new WeeklyAggregatesRefresher())->refresh($machine, ['2026-W06']);

    $row = WeeklyAggregate::where(['machine_id' => $machine->id, 'iso_week' => '2026-W06'])->first();
    expect((float) $row->total_flight_hours)->toBe(3.75);
});

it('is idempotent: re-running on same data does not duplicate rows', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST3']);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 09:00:00', 'end_datetime' => '2026-02-02 11:00:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 2.0,
    ]);

    $refresher = new WeeklyAggregatesRefresher();
    $refresher->refresh($machine, ['2026-W06']);
    $refresher->refresh($machine, ['2026-W06']);
    $refresher->refresh($machine, ['2026-W06']);

    $rows = WeeklyAggregate::where(['machine_id' => $machine->id, 'iso_week' => '2026-W06'])->get();
    expect($rows->count())->toBe(1);
    expect((float) $rows[0]->total_flight_hours)->toBe(2.0);
});

it('refreshes multiple iso_weeks in a single call', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST4']);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 09:00:00', 'end_datetime' => '2026-02-02 11:00:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 2.0,
    ]);
    Flight::create([
        'machine_id' => $machine->id, 'dsn' => 'D2', 'num' => '1',
        'start_datetime' => '2026-02-09 09:00:00', 'end_datetime' => '2026-02-09 11:00:00',
        'flight_type' => 'FLIGHT', 'flight_hours' => 1.5,
    ]);

    (new WeeklyAggregatesRefresher())->refresh($machine, ['2026-W06', '2026-W07']);

    $rows = WeeklyAggregate::where('machine_id', $machine->id)
        ->orderBy('iso_week')
        ->get()
        ->keyBy('iso_week');
    expect($rows)->toHaveCount(2);
    expect((float) $rows['2026-W06']->total_flight_hours)->toBe(2.0);
    expect((float) $rows['2026-W07']->total_flight_hours)->toBe(1.5);
});

it('deduplicates iso_weeks list and skips empty strings', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST5']);

    $result = (new WeeklyAggregatesRefresher())
        ->refresh($machine, ['2026-W06', '2026-W06', '', '2026-W07']);

    expect(array_keys($result))->toBe(['2026-W06', '2026-W07']);
});
