<?php

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use App\Services\WeeklyAggregatesIngestor;

it('ingests yearly panne and fh csvs into weekly_aggregates', function () {
    $machine = Machine::create(['hc_id' => 'NH08']);

    $pannesCsv = base_path('../data/reports/yearly/NH08/NH08_2026.csv');
    $fhCsv = base_path('../data/FHreport/yearly/NH08/NH08_2026.csv');

    if (!file_exists($pannesCsv) || !file_exists($fhCsv)) {
        $this->markTestSkipped('Yearly CSVs missing for NH08');
    }

    $ingestor = new WeeklyAggregatesIngestor();
    $ingestor->ingest($machine, 2026, $pannesCsv, $fhCsv);

    $aggregates = WeeklyAggregate::where('machine_id', $machine->id)->get();
    expect($aggregates->count())->toBeGreaterThan(0);

    $w05 = $aggregates->firstWhere('iso_week', '2026-W05');
    expect($w05)->not->toBeNull();
    expect((int) $w05->total_pannes)->toBeGreaterThan(0);
    expect((float) $w05->total_flight_hours)->toBeGreaterThan(0);
});

it('upserts on re-ingest without duplicating rows', function () {
    $machine = Machine::create(['hc_id' => 'NH99']);

    $tmp = tempnam(sys_get_temp_dir(), 'panne_');
    file_put_contents($tmp, "semaine,total_pannes\n2026-W05,5\n2026-W06,7\n");

    $ingestor = new WeeklyAggregatesIngestor();
    $ingestor->ingest($machine, 2026, $tmp, null);
    expect(WeeklyAggregate::where('machine_id', $machine->id)->count())->toBe(2);

    // Second ingest met a jour sans creer de nouvelles lignes
    file_put_contents($tmp, "semaine,total_pannes\n2026-W05,10\n2026-W06,7\n");
    $ingestor->ingest($machine, 2026, $tmp, null);
    expect(WeeklyAggregate::where('machine_id', $machine->id)->count())->toBe(2);

    $w05 = WeeklyAggregate::where(['machine_id' => $machine->id, 'iso_week' => '2026-W05'])->first();
    expect((int) $w05->total_pannes)->toBe(10);

    unlink($tmp);
});

it('skips TOTAL row when ingesting fh csv', function () {
    $machine = Machine::create(['hc_id' => 'NH77']);
    $tmp = tempnam(sys_get_temp_dir(), 'fh_');
    file_put_contents($tmp, "semaine,total_flight_hours\n2026-W05,2.71\n2026-W06,5.15\nTOTAL 2026,7.86\n");

    $ingestor = new WeeklyAggregatesIngestor();
    $ingestor->ingest($machine, 2026, null, $tmp);

    $count = WeeklyAggregate::where('machine_id', $machine->id)->count();
    expect($count)->toBe(2);  // pas de ligne pour "TOTAL 2026"

    unlink($tmp);
});
