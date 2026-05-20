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

it('stores xml_epure.xml content in flight.xml_blob for vols', function () {
    $outputDir = sys_get_temp_dir() . '/p3_vol_' . uniqid();
    mkdir($outputDir, 0775, true);
    file_put_contents($outputDir . '/xml_epure.xml', '<?xml version="1.0"?><Root><Hello/></Root>');
    file_put_contents($outputDir . '/pannes_conservees.json', json_encode(['pannes_conservees' => []]));
    file_put_contents($outputDir . '/pannes_isolees.json', json_encode(['pannes_hors_date' => []]));

    $flight = (new \App\Services\FlightImporter())->import([
        'hc_id'          => 'NHTEST_BLOB_VOL',
        'dsn'            => 'DSN1',
        'num'            => '1',
        'start_datetime' => '2026-02-02T10:00:00',
        'end_datetime'   => '2026-02-02T12:00:00',
        'flight_type'    => 'FLIGHT',
        'flight_hours'   => 2.0,
        'consumed_fuel'  => 100.0,
        'output_dir'     => $outputDir,
    ]);

    expect($flight->xml_blob)->not->toBeNull();
    expect(stream_get_contents_or_string($flight->xml_blob))
        ->toBe('<?xml version="1.0"?><Root><Hello/></Root>');

    // cleanup
    unlink($outputDir . '/xml_epure.xml');
    unlink($outputDir . '/pannes_conservees.json');
    unlink($outputDir . '/pannes_isolees.json');
    rmdir($outputDir);
});

/**
 * Postgres returns bytea as either a stream resource or a binary string
 * depending on the driver/PDO mode. This helper handles both.
 */
function stream_get_contents_or_string(mixed $value): string
{
    if (is_resource($value)) {
        $content = stream_get_contents($value);
        rewind($value);
        return $content ?: '';
    }
    return (string) $value;
}

it('stores the raw xml content in flight.xml_blob for Bff non-vols', function () {
    $outputDir = sys_get_temp_dir() . '/p3_bff_' . uniqid();
    mkdir($outputDir, 0775, true);
    file_put_contents($outputDir . '/123_POST_DWNLD.HUMS_EXPORT_123_999.xml', '<?xml version="1.0"?><Bff/>');

    $flight = (new \App\Services\FlightImporter())->importNonVol([
        'hc_id'          => 'NHTEST_BLOB_BFF',
        'dsn'            => 'DSN_BFF',
        'num'            => '999',
        'start_datetime' => '2026-02-02T10:00:00',
        'end_datetime'   => '2026-02-02T10:30:00',
        'flight_type'    => 'GROUND',
        'consumed_fuel'  => 0,
        'output_dir'     => $outputDir,
    ]);

    expect($flight->is_non_vol)->toBeTrue();
    expect($flight->xml_blob)->not->toBeNull();
    expect(stream_get_contents_or_string($flight->xml_blob))->toBe('<?xml version="1.0"?><Bff/>');

    // cleanup
    array_map('unlink', glob($outputDir . '/*'));
    rmdir($outputDir);
});
