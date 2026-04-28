<?php

use App\Models\Flight;
use App\Services\FlightImporter;
use App\Services\XmlPipelineRunner;

it('imports a flight and its technical events from pipeline output', function () {
    $xmlFiles = glob(config('services.pipeline.path') . '/raw/*.xml');
    if (empty($xmlFiles)) {
        $this->markTestSkipped('No sample XML available');
    }

    $runner = new XmlPipelineRunner();

    // Trouver un XML qui donne status=ok (les premiers peuvent etre GROUND)
    $result = null;
    foreach ($xmlFiles as $xml) {
        $outputBase = storage_path('app/test_importer_' . uniqid());
        $r = $runner->run($xml, $outputBase);
        if ($r['status'] === 'ok') {
            $result = $r;
            break;
        }
    }

    if (!$result) {
        $this->markTestSkipped('No XML yielded status=ok in sample set');
    }

    $importer = new FlightImporter();
    $flight = $importer->import($result);

    expect($flight)->toBeInstanceOf(Flight::class);
    expect($flight->machine->hc_id)->toBe($result['hc_id']);
    expect($flight->dsn)->toBe($result['dsn']);
    expect($flight->num)->toBe($result['num']);
    // Au moins une panne conservee devrait etre inseree pour un vol ok
    $conservees = $flight->technicalEvents()->where('status', 'conservee')->count();
    expect($conservees)->toBeGreaterThan(0);
});

it('imports a non-vol flight with is_non_vol=true', function () {
    $xmlFiles = glob(config('services.pipeline.path') . '/raw/*.xml');
    if (empty($xmlFiles)) {
        $this->markTestSkipped('No sample XML available');
    }

    $runner = new XmlPipelineRunner();
    $result = null;
    foreach ($xmlFiles as $xml) {
        $outputBase = storage_path('app/test_nonvol_' . uniqid());
        $r = $runner->run($xml, $outputBase);
        if ($r['status'] === 'no_engine') {
            $result = $r;
            break;
        }
    }
    if (!$result) {
        $this->markTestSkipped('No non-vol XML in sample set');
    }

    $importer = new FlightImporter();
    $flight = $importer->importNonVol($result);

    expect($flight)->toBeInstanceOf(Flight::class);
    expect($flight->is_non_vol)->toBeTrue();
    expect($flight->flagged_as_error)->toBeFalse();
});
