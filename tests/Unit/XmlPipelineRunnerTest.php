<?php

use App\Services\XmlPipelineRunner;

it('runs pipeline on a valid xml and returns parsed json', function () {
    $xmlFiles = glob(base_path('../raw/*.xml'));
    if (empty($xmlFiles)) {
        $this->markTestSkipped('No sample XML available in raw/');
    }

    $outputBase = storage_path('app/test_pipeline_' . uniqid());

    $runner = new XmlPipelineRunner();
    $result = $runner->run($xmlFiles[0], $outputBase);

    expect($result)->toBeArray()
        ->and($result['status'])->toBeIn(['ok', 'no_engine', 'error'])
        ->and($result['hc_id'])->not->toBeNull();
});

it('returns error status when xml file is missing', function () {
    $runner = new XmlPipelineRunner();
    $result = $runner->run('/nonexistent/path.xml', storage_path('app/tmp'));
    expect($result['status'])->toBe('error');
    expect($result['message'])->toContain('not found');
});
