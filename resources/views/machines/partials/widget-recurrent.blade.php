@php
    $actives = $machine->recurrentFailures;
    $count = $actives->count();
    $top = $actives->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-recurrent-{$machine->hc_id}";
@endphp

<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 flex flex-col">
    <div class="flex items-center justify-between mb-2">
        <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium">Pannes occurrentes actives</p>
        <p class="text-sm font-semibold text-slate-700 tabular-nums">{{ $count }}</p>
    </div>

    @if ($count === 0)
        <p class="text-xs text-slate-400 italic py-2">— Aucune panne occurrente active —</p>
    @else
        <ul class="space-y-2">
            @foreach ($top as $rf)
                <li class="text-xs">
                    <p class="text-slate-900 font-medium truncate" title="{{ $rf->te_description }}">
                        {{ $rf->te_description ?? $rf->technical_event_id }}
                    </p>
                    <p class="text-[11px] text-slate-500 truncate">
                        {{ $rf->description }} · {{ $rf->system_description }} · {{ $rf->type_description }}
                    </p>
                </li>
            @endforeach
        </ul>

        @if ($rest > 0)
            <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                    class="text-xs text-blue-600 hover:text-blue-700 font-medium mt-2 self-start">
                voir plus ({{ $rest }})
            </button>
        @endif

        @include('machines.partials.modal-recurrent', ['machine' => $machine, 'actives' => $actives, 'modalId' => $modalId])
    @endif
</div>
