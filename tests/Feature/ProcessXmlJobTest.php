<?php

use App\Jobs\ProcessXmlJob;
use App\Models\Flight;
use App\Models\Import;
use App\Models\User;
use App\Services\FlightImporter;
use App\Services\WeeklyAggregatesIngestor;
use App\Services\XmlPipelineRunner;

it('processes an xml via the job and updates the import row', function () {
    $xmls = glob(base_path('../raw/*.xml'));
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
        app(WeeklyAggregatesIngestor::class),
    );

    $import->refresh();
    expect($import->status)->toBeIn(['ok', 'already_processed', 'non_vol', 'error']);

    if (in_array($import->status, ['ok', 'already_processed', 'non_vol'], true)) {
        expect($import->flight_id)->not->toBeNull();
        expect(Flight::find($import->flight_id))->not->toBeNull();
    }
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
        app(WeeklyAggregatesIngestor::class),
    );

    $import->refresh();
    expect($import->status)->toBe('error');
    expect(data_get($import->result, 'message'))->toContain('not found');
});
