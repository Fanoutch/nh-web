@php
    $lastFlight = $machine->latestFlight;
    $pannes = $lastFlight?->technicalEvents ?? collect();
    $count = $pannes->count();
    $top = $pannes->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-last-flight-{$machine->hc_id}";
    $flightDate = $lastFlight?->start_datetime?->format('d/m/Y');
@endphp

<div class="px-5 py-4 flex flex-col">
    <div class="flex items-start justify-between mb-3">
        <div>
            <x-section-label>Pannes du dernier vol</x-section-label>
            @if ($flightDate)
                <div class="text-[10px] text-ink-muted font-mono mt-0.5">vol du {{ $flightDate }}</div>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <span class="text-sm font-mono font-semibold text-ink-secondary tabular-nums">{{ $count }}</span>
            @if ($rest > 0)
                <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                        class="text-[11px] text-accent hover:text-accent-pressed font-medium transition-colors">
                    voir plus
                </button>
            @endif
        </div>
    </div>

    @if ($count === 0)
        <p class="text-[11px] text-ink-muted italic py-2">— Aucune panne sur le dernier vol —</p>
    @else
        <div class="flex flex-col gap-1.5">
            @foreach ($top as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                    $occ = $te->nombre_occurrences;
                    $badgeVariant = $occ >= 3 ? 'error' : ($occ === 2 ? 'amber' : 'pending');
                @endphp
                <div class="flex items-center gap-2 px-2 py-1.5 bg-app-bg rounded">
                    <x-badge :variant="$badgeVariant" class="shrink-0">×{{ $occ }}</x-badge>
                    <div class="min-w-0">
                        <div class="text-xs text-ink-primary truncate" title="{{ $teDesc }}">{{ $teDesc }}</div>
                        <div class="text-[10px] text-ink-muted font-mono truncate">
                            {{ $sysDesc }} · {{ $typeDesc }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($rest > 0)
            @include('machines.partials.modal-last-flight', [
                'machine' => $machine,
                'flightDate' => $flightDate,
                'pannes' => $pannes,
                'modalId' => $modalId,
            ])
        @endif
    @endif
</div>
