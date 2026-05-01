<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Facades\DB;

class RecurrentFailuresIngestor
{
    public function ingest(string $hcId): array
    {
        $path = storage_path("app/data/reports/occurrentes/{$hcId}/occurrentes.json");
        if (!file_exists($path)) {
            return ['synced' => 0, 'removed' => 0];
        }

        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            return ['synced' => 0, 'removed' => 0];
        }

        $active = $json['active'] ?? [];
        $volsHistory = $json['vols_history'] ?? [];

        return DB::transaction(function () use ($hcId, $active, $volsHistory) {
            $machine = Machine::firstOrCreate(['hc_id' => $hcId]);
            $activeIds = array_map(fn ($e) => $e['id'], $active);

            $removed = $machine->recurrentFailures()
                ->where('status', 'active')
                ->when(!empty($activeIds), fn ($q) => $q->whereNotIn('technical_event_id', $activeIds))
                ->delete();

            $synced = 0;
            foreach ($active as $entry) {
                $score = $this->computeScore($entry['id'], $volsHistory);
                $machine->recurrentFailures()->updateOrCreate(
                    [
                        'technical_event_id' => $entry['id'],
                        'status'             => 'active',
                    ],
                    [
                        'te_description'      => $entry['te_description'] ?? null,
                        'description'         => $entry['description'] ?? null,
                        'system_description'  => $entry['system_description'] ?? null,
                        'type_description'    => $entry['type_description'] ?? null,
                        'failure_code'        => $entry['failure_code'] ?? null,
                        'active_depuis_vol'   => $entry['active_depuis_vol'] ?? null,
                        'active_depuis_date'  => $entry['active_depuis_date'] ?? null,
                        'first_apparition'    => $entry['first_apparition'] ?? null,
                        'score'               => $score,
                        'details'             => $entry,
                    ],
                );
                $synced++;
            }

            return ['synced' => $synced, 'removed' => $removed];
        });
    }

    private function computeScore(string $teId, array $volsHistory): int
    {
        $last3 = array_slice($volsHistory, -3);
        $count = 0;
        foreach ($last3 as $vol) {
            if (in_array($teId, $vol['te_ids'] ?? [], true)) {
                $count++;
            }
        }
        return max(1, $count);
    }
}
