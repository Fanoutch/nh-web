<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\User;

it('downloads xml content from xml_blob when present', function () {
    $user = User::factory()->create();
    $machine = Machine::create(['hc_id' => 'NH_DOWNLOAD']);
    $flight = Flight::create([
        'machine_id'     => $machine->id,
        'dsn'            => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 10:00:00',
        'end_datetime'   => '2026-02-02 12:00:00',
        'flight_type'    => 'FLIGHT',
        'flight_hours'   => 2.0,
        'xml_blob'       => '<?xml version="1.0"?><Hello/>',
    ]);

    $response = $this->actingAs($user)->get(route('flights.xml', $flight));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/xml');
    expect($response->getContent())
        ->toBe('<?xml version="1.0"?><Hello/>');
});

it('falls back to xml_path file when xml_blob is null', function () {
    $user = User::factory()->create();
    $machine = Machine::create(['hc_id' => 'NH_DOWNLOAD_FALLBACK']);
    $tmp = tempnam(sys_get_temp_dir(), 'p3_fallback_') . '.xml';
    file_put_contents($tmp, '<?xml version="1.0"?><FromFile/>');
    $flight = Flight::create([
        'machine_id'     => $machine->id,
        'dsn'            => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 10:00:00',
        'end_datetime'   => '2026-02-02 12:00:00',
        'flight_type'    => 'FLIGHT',
        'flight_hours'   => 2.0,
        'xml_path'       => $tmp,
        // xml_blob NOT set
    ]);

    $response = $this->actingAs($user)->get(route('flights.xml', $flight));

    $response->assertOk();

    unlink($tmp);
});

it('returns 404 when neither xml_blob nor xml_path exists', function () {
    $user = User::factory()->create();
    $machine = Machine::create(['hc_id' => 'NH_404']);
    $flight = Flight::create([
        'machine_id'     => $machine->id,
        'dsn'            => 'D1', 'num' => '1',
        'start_datetime' => '2026-02-02 10:00:00',
        'end_datetime'   => '2026-02-02 12:00:00',
        'flight_type'    => 'FLIGHT',
        'flight_hours'   => 2.0,
        // no blob, no path
    ]);

    $this->actingAs($user)
        ->get(route('flights.xml', $flight))
        ->assertNotFound();
});
