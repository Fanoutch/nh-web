@php
    $actives = $machine->recurrentFailures;
    $count = $actives->count();
    $top = $actives->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-recurrent-{$machine->hc_id}";
@endphp

<div class="px-5 py-4 flex flex-col">
    <div class="flex items-center justify-between mb-3">
        <x-section-label>Pannes occurrentes actives</x-section-label>
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
        <p class="text-[11px] text-ink-muted italic py-2">— Aucune panne occurrente active —</p>
    @else
        <div class="flex flex-col gap-1.5">
            @foreach ($top as $rf)
                @php $badgeVariant = $rf->score >= 3 ? 'error' : 'amber'; @endphp
                <div class="flex items-center gap-2 px-2 py-1.5 bg-app-bg rounded">
                    <x-badge :variant="$badgeVariant" class="shrink-0">×{{ $rf->score }}</x-badge>
                    <div class="min-w-0">
                        <div class="text-xs text-ink-primary truncate" title="{{ $rf->te_description ?? $rf->technical_event_id }}">
                            {{ $rf->te_description ?? $rf->technical_event_id }}
                        </div>
                        <div class="text-[10px] text-ink-muted font-mono truncate">
                            {{ $rf->system_description ?? '—' }} · {{ $rf->type_description ?? '—' }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($rest > 0)
            @include('machines.partials.modal-recurrent', ['machine' => $machine, 'actives' => $actives, 'modalId' => $modalId])
        @endif
    @endif
</div>
