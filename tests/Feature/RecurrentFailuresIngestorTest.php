<?php

use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Services\RecurrentFailuresIngestor;

beforeEach(function () {
    $this->ingestor = new RecurrentFailuresIngestor();
    $this->occurrentesDir = storage_path('app/data/reports/occurrentes');

    // Cleanup any leftover fixtures from previous runs
    if (is_dir($this->occurrentesDir)) {
        foreach (glob($this->occurrentesDir . '/NH9*/occurrentes.json') as $f) {
            @unlink($f);
        }
    }
});

afterEach(function () {
    $base = storage_path('app/data/reports/occurrentes');
    if (!is_dir($base)) return;
    foreach (glob($base . '/NH9*') as $dir) {
        if (is_dir($dir)) {
            $file = $dir . '/occurrentes.json';
            if (file_exists($file)) @unlink($file);
            @rmdir($dir);
        }
    }
});

function writeOccurrentesFixture(string $hcId, array $active, array $volsHistory = []): string
{
    $dir = storage_path("app/data/reports/occurrentes/{$hcId}");
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $path = $dir . '/occurrentes.json';
    file_put_contents($path, json_encode([
        'hc_id' => $hcId,
        'last_updated' => '2026-05-01T12:00:00',
        'vols_history' => $volsHistory,
        'active' => $active,
    ], JSON_PRETTY_PRINT));
    return $path;
}

it('returns zero counts when occurrentes.json does not exist', function () {
    $result = $this->ingestor->ingest('NH_DOES_NOT_EXIST');

    expect($result)->toBe(['synced' => 0, 'removed' => 0]);
});

it('syncs active entries from JSON into the table', function () {
    writeOccurrentesFixture('NH99', [
        [
            'id' => 'TE_A',
            'te_description' => 'TE A long desc',
            'description' => 'Desc A',
            'system_description' => 'Sys A',
            'type_description' => 'Fault Code',
            'failure_code' => '111',
            'active_depuis_vol' => 'NH99_2026-04-29_001',
            'active_depuis_date' => '2026-04-29',
            'first_apparition' => '2026-04-28T08:00:00',
        ],
        [
            'id' => 'TE_B',
            'te_description' => 'TE B long desc',
            'description' => 'Desc B',
            'system_description' => 'Sys B',
            'type_description' => 'Fault Code',
            'failure_code' => '222',
            'active_depuis_vol' => 'NH99_2026-04-30_001',
            'active_depuis_date' => '2026-04-30',
            'first_apparition' => '2026-04-29T08:00:00',
        ],
    ], [
        ['vol' => 'V1', 'end_datetime' => '2026-04-28T10:00:00', 'te_ids' => ['TE_A']],
        ['vol' => 'V2', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A', 'TE_B']],
        ['vol' => 'V3', 'end_datetime' => '2026-04-30T10:00:00', 'te_ids' => ['TE_A', 'TE_B']],
    ]);

    $result = $this->ingestor->ingest('NH99');

    expect($result)->toBe(['synced' => 2, 'removed' => 0]);

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(2);

    $teA = $machine->recurrentFailures()->where('technical_event_id', 'TE_A')->first();
    expect($teA->te_description)->toBe('TE A long desc');
    expect($teA->score)->toBe(3); // present in last 3 vols
    $teB = $machine->recurrentFailures()->where('technical_event_id', 'TE_B')->first();
    expect($teB->score)->toBe(2);
});

it('removes active entries that disappear from JSON between two ingests', function () {
    // Ingest initial : 2 actives
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_A'],
        ['id' => 'TE_B'],
    ]);
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(2);

    // 2e ingest : TE_A retiré, TE_C ajouté
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_B'],
        ['id' => 'TE_C'],
    ]);
    $result = $this->ingestor->ingest('NH99');

    expect($result['removed'])->toBe(1);
    expect($result['synced'])->toBe(2);

    $ids = $machine->recurrentFailures()->where('status', 'active')->pluck('technical_event_id')->sort()->values()->all();
    expect($ids)->toBe(['TE_B', 'TE_C']);
});

it('is idempotent : re-ingesting same JSON does not duplicate rows', function () {
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_A', 'te_description' => 'first'],
    ]);

    $this->ingestor->ingest('NH99');
    $this->ingestor->ingest('NH99');
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(1);
});

it('updates score and description on re-ingest', function () {
    writeOccurrentesFixture('NH99',
        [['id' => 'TE_A', 'te_description' => 'desc v1']],
        [['vol' => 'V1', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A']]],
    );
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    $rf = $machine->recurrentFailures()->where('technical_event_id', 'TE_A')->first();
    expect($rf->score)->toBe(1);
    expect($rf->te_description)->toBe('desc v1');

    // 2e ingest : TE_A présent dans 3 vols + description changée
    writeOccurrentesFixture('NH99',
        [['id' => 'TE_A', 'te_description' => 'desc v2']],
        [
            ['vol' => 'V1', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A']],
            ['vol' => 'V2', 'end_datetime' => '2026-04-30T10:00:00', 'te_ids' => ['TE_A']],
            ['vol' => 'V3', 'end_datetime' => '2026-05-01T10:00:00', 'te_ids' => ['TE_A']],
        ],
    );
    $this->ingestor->ingest('NH99');

    $rf->refresh();
    expect($rf->score)->toBe(3);
    expect($rf->te_description)->toBe('desc v2');
    // toujours 1 seule ligne (pas de duplication)
    expect($machine->recurrentFailures()->where('technical_event_id', 'TE_A')->count())->toBe(1);
});

it('removes all active rows when JSON active array is empty', function () {
    writeOccurrentesFixture('NH99', [['id' => 'TE_A'], ['id' => 'TE_B']]);
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(2);

    writeOccurrentesFixture('NH99', []);
    $result = $this->ingestor->ingest('NH99');

    expect($result)->toBe(['synced' => 0, 'removed' => 2]);
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(0);
});
