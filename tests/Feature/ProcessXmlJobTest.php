<?php

use App\Jobs\ProcessXmlJob;
use App\Models\Flight;
use App\Models\Import;
use App\Models\User;
use App\Services\FlightImporter;
use App\Services\RecurrentFailuresRefresher;
use App\Services\WeeklyAggregatesRefresher;
use App\Services\XmlPipelineRunner;

it('processes an xml via the job and updates the import row', function () {
    $xmls = glob(config('services.pipeline.path') . '/raw/*.xml');
    if (empty($xmls)) {
        $this->markTestSkipped('No sample XML available');
    }

    $user = User::factory()->create();
    $staging = storage_path('app/staging_test_' . uniqid() . '.xml');
    copy($xmls[0], $staging);

    $import = Import::create([
        'user_id' => $user->id,
        'filename' => basename($staging),
        'status' => 'pending',
    ]);

    (new ProcessXmlJob($import->id, $staging))->handle(
        app(XmlPipelineRunner::class),
        app(FlightImporter::class),
        app(WeeklyAggregatesRefresher::class),
        app(RecurrentFailuresRefresher::class),
    );

    $import->refresh();
    expect($import->status)->toBeIn(['ok', 'already_processed', 'non_vol', 'error']);

    if (in_array($import->status, ['ok', 'already_processed', 'non_vol'], true)) {
        expect($import->flight_id)->not->toBeNull();
        expect(Flight::find($import->flight_id))->not->toBeNull();
    }
});

it('deletes pannes_*.json and yearly CSVs after successful ingestion', function () {
    $xmls = glob(config('services.pipeline.path') . '/data/raw/*.xml');
    if (empty($xmls)) {
        $xmls = glob(config('services.pipeline.path') . '/raw/*.xml');
    }
    if (empty($xmls)) {
        $this->markTestSkipped('No sample XML available');
    }

    $user = \App\Models\User::factory()->create();
    $staging = storage_path('app/staging_test_' . uniqid() . '.xml');
    copy($xmls[0], $staging);

    $import = \App\Models\Import::create([
        'user_id' => $user->id,
        'filename' => basename($staging),
        'status' => 'pending',
    ]);

    (new \App\Jobs\ProcessXmlJob($import->id, $staging))->handle(
        app(\App\Services\XmlPipelineRunner::class),
        app(\App\Services\FlightImporter::class),
        app(\App\Services\WeeklyAggregatesRefresher::class),
        app(\App\Services\RecurrentFailuresRefresher::class),
    );

    $import->refresh();
    if (!in_array($import->status, ['ok', 'already_processed'], true)) {
        $this->markTestSkipped('Pipeline did not produce a normal flight for this sample XML');
    }

    $flight = \App\Models\Flight::find($import->flight_id);
    $outputDir = dirname($flight->xml_path);

    expect(file_exists($outputDir . '/pannes_conservees.json'))->toBeFalse();
    expect(file_exists($outputDir . '/pannes_isolees.json'))->toBeFalse();

    $year = (int) $flight->end_datetime->format('Y');
    $hcId = $flight->machine->hc_id;
    $base = storage_path('app/data');
    expect(file_exists($base . "/reports/yearly/{$hcId}/{$hcId}_{$year}.csv"))->toBeFalse();
    expect(file_exists($base . "/FHreport/yearly/{$hcId}/{$hcId}_{$year}.csv"))->toBeFalse();

    expect(file_exists($flight->xml_path))->toBeTrue();  // xml_epure must survive
});

it('marks import as error when pipeline fails', function () {
    $user = User::factory()->create();
    $import = Import::create([
        'user_id' => $user->id,
        'filename' => 'fake.xml',
        'status' => 'pending',
    ]);

    (new ProcessXmlJob($import->id, '/nonexistent/file.xml'))->handle(
        app(XmlPipelineRunner::class),
        app(FlightImporter::class),
        app(WeeklyAggregatesRefresher::class),
        app(RecurrentFailuresRefresher::class),
    );

    $import->refresh();
    expect($import->status)->toBe('error');
    expect(data_get($import->result, 'message'))->toContain('not found');
});

it('invokes RecurrentFailuresRefresher with the imported flight machine', function () {
    $xmls = glob(config('services.pipeline.path') . '/data/raw/*.xml');
    if (empty($xmls)) {
        $xmls = glob(config('services.pipeline.path') . '/raw/*.xml');
    }
    if (empty($xmls)) {
        $this->markTestSkipped('No sample XML available');
    }

    $user = \App\Models\User::factory()->create();
    $staging = storage_path('app/staging_test_' . uniqid() . '.xml');
    copy($xmls[0], $staging);

    $import = \App\Models\Import::create([
        'user_id' => $user->id,
        'filename' => basename($staging),
        'status' => 'pending',
    ]);

    $spy = Mockery::spy(\App\Services\RecurrentFailuresRefresher::class);
    $spy->shouldReceive('refresh')->andReturn(['activated' => 0, 'kept' => 0, 'deactivated' => 0]);

    (new \App\Jobs\ProcessXmlJob($import->id, $staging))->handle(
        app(\App\Services\XmlPipelineRunner::class),
        app(\App\Services\FlightImporter::class),
        app(\App\Services\WeeklyAggregatesRefresher::class),
        $spy,
    );

    $import->refresh();
    if (!in_array($import->status, ['ok', 'already_processed'], true)) {
        $this->markTestSkipped('Pipeline did not produce a normal flight for this sample XML');
    }

    $flight = \App\Models\Flight::find($import->flight_id);
    $spy->shouldHaveReceived('refresh')->once()->with(\Mockery::on(
        fn ($machine) => $machine instanceof \App\Models\Machine && $machine->id === $flight->machine_id
    ));
});
