<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Models\TechnicalEvent;
use App\Services\RecurrentFailuresRefresher;

function makeFlight(Machine $machine, string $num, string $start, string $end, array $overrides = []): Flight
{
    return Flight::create(array_merge([
        'machine_id'       => $machine->id,
        'dsn'              => 'DSN' . $num,
        'num'              => $num,
        'start_datetime'   => $start,
        'end_datetime'     => $end,
        'flight_type'      => 'FLIGHT',
        'flight_hours'     => 2.0,
        'is_non_vol'       => false,
        'flagged_as_error' => false,
    ], $overrides));
}

function addConservee(Flight $flight, string $teId, string $raise, array $extraDetails = []): TechnicalEvent
{
    return TechnicalEvent::create([
        'flight_id'          => $flight->id,
        'technical_event_id' => $teId,
        'raise_datetime'     => $raise,
        'status'             => 'conservee',
        'iso_week'           => \Carbon\Carbon::parse($raise)->isoFormat('GGGG-[W]WW'),
        'details'            => array_merge([
            'TechnicalEventId'    => $teId,
            'TEDescription'       => "Desc {$teId}",
            'Description'         => "Long {$teId}",
            'SystemDescription'   => 'MGB',
            'TypeDescription'     => 'FAULT',
            'FailureCode'         => '0001',
        ], $extraDetails),
    ]);
}

it('produces no recurrent failures for a machine with no flights', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST0']);

    $result = (new RecurrentFailuresRefresher())->refresh($machine);

    expect($result)->toBe(['activated' => 0, 'kept' => 0, 'deactivated' => 0]);
    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(0);
});

it('activates a TE that appears in 2 of the last 3 flights', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST1']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');

    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');
    addConservee($f2, 'TE_A', '2026-02-04 11:00:00');
    // f3 does not have TE_A

    $result = (new RecurrentFailuresRefresher())->refresh($machine);

    expect($result['activated'])->toBe(1);
    expect($result['kept'])->toBe(0);
    expect($result['deactivated'])->toBe(0);

    $row = RecurrentFailure::where('machine_id', $machine->id)
        ->where('technical_event_id', 'TE_A')->first();
    expect($row)->not->toBeNull();
    expect($row->status)->toBe('active');
    expect((int) $row->score)->toBe(2);
    expect($row->active_depuis_vol)->toBe('NHTEST1_2026-02-06_3');
});

it('does not activate a TE that appears in only 1 of last 3 flights', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST2']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');

    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');

    $result = (new RecurrentFailuresRefresher())->refresh($machine);

    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(0);
    expect($result['activated'])->toBe(0);
});

it('deactivates a previously active TE that drops to 0 of last 3 flights', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST3']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');
    addConservee($f2, 'TE_A', '2026-02-04 11:00:00');
    (new RecurrentFailuresRefresher())->refresh($machine);

    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(1);

    makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');
    makeFlight($machine, '4', '2026-02-08 10:00:00', '2026-02-08 12:00:00');
    makeFlight($machine, '5', '2026-02-10 10:00:00', '2026-02-10 12:00:00');

    $result = (new RecurrentFailuresRefresher())->refresh($machine);

    expect($result['deactivated'])->toBe(1);
    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(0);
});

it('keeps an active TE in the buffer zone (score 1 or 2 stays active)', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST4']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');
    addConservee($f2, 'TE_A', '2026-02-04 11:00:00');
    (new RecurrentFailuresRefresher())->refresh($machine);

    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');
    (new RecurrentFailuresRefresher())->refresh($machine);
    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(1);

    $f4 = makeFlight($machine, '4', '2026-02-08 10:00:00', '2026-02-08 12:00:00');
    $result = (new RecurrentFailuresRefresher())->refresh($machine);
    expect($result['deactivated'])->toBe(0);
    expect($result['kept'])->toBeGreaterThanOrEqual(1);

    $row = RecurrentFailure::where('machine_id', $machine->id)->where('technical_event_id', 'TE_A')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->score)->toBe(1);
});

it('ignores non_vol and flagged_as_error flights when building the window', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST5']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');
    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');
    addConservee($f2, 'TE_A', '2026-02-04 11:00:00');

    makeFlight($machine, '4', '2026-02-08 09:00:00', '2026-02-08 09:30:00',
        ['is_non_vol' => true, 'flight_hours' => 0]);
    makeFlight($machine, '5', '2026-02-10 09:00:00', '2026-02-10 09:30:00',
        ['flagged_as_error' => true]);

    $result = (new RecurrentFailuresRefresher())->refresh($machine);

    expect($result['activated'])->toBe(1);
    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(1);
});

it('counts only conservee technical_events, not isolee', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST6']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');

    foreach ([$f1, $f2, $f3] as $f) {
        TechnicalEvent::create([
            'flight_id' => $f->id,
            'technical_event_id' => 'TE_A',
            'raise_datetime' => $f->end_datetime,
            'status' => 'isolee',
            'iso_week' => '2026-W06',
            'details' => [],
        ]);
    }

    (new RecurrentFailuresRefresher())->refresh($machine);

    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(0);
});

it('populates description fields from technical_events.details JSONB', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST7']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');

    addConservee($f1, 'TE_X', '2026-02-02 11:00:00', [
        'TEDescription'     => 'X short',
        'Description'       => 'X long description',
        'SystemDescription' => 'MGB',
        'TypeDescription'   => 'FAULT_CODE',
        'FailureCode'       => '46-830',
    ]);
    addConservee($f2, 'TE_X', '2026-02-04 11:00:00', [
        'TEDescription'     => 'X short v2',
        'Description'       => 'X long v2',
    ]);

    (new RecurrentFailuresRefresher())->refresh($machine);

    $row = RecurrentFailure::where('machine_id', $machine->id)->where('technical_event_id', 'TE_X')->first();
    expect($row->te_description)->toBe('X short v2');
    expect($row->description)->toBe('X long v2');
    expect($row->first_apparition->toDateTimeString())->toBe('2026-02-02 11:00:00');
});

it('is idempotent: re-running on same data does not duplicate rows', function () {
    $machine = Machine::create(['hc_id' => 'NHTEST8']);

    $f1 = makeFlight($machine, '1', '2026-02-02 10:00:00', '2026-02-02 12:00:00');
    $f2 = makeFlight($machine, '2', '2026-02-04 10:00:00', '2026-02-04 12:00:00');
    $f3 = makeFlight($machine, '3', '2026-02-06 10:00:00', '2026-02-06 12:00:00');
    addConservee($f1, 'TE_A', '2026-02-02 11:00:00');
    addConservee($f2, 'TE_A', '2026-02-04 11:00:00');

    $svc = new RecurrentFailuresRefresher();
    $svc->refresh($machine);
    $svc->refresh($machine);
    $svc->refresh($machine);

    expect(RecurrentFailure::where('machine_id', $machine->id)->count())->toBe(1);
});
