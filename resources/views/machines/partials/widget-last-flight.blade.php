@php
    $lastFlight = $machine->latestFlight;
    $pannes = $lastFlight?->technicalEvents ?? collect();
    $count = $pannes->count();
    $top = $pannes->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-last-flight-{$machine->hc_id}";
    $flightDate = $lastFlight?->start_datetime?->format('d/m/Y');
@endphp

<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 flex flex-col">
    <div class="flex items-center justify-between mb-2">
        <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium">Pannes dernier vol</p>
        <div class="flex items-center gap-2">
            @if ($flightDate)
                <span class="text-[11px] text-slate-500 tabular-nums">{{ $flightDate }}</span>
            @endif
            <span class="text-sm font-semibold text-slate-700 tabular-nums">{{ $count }}</span>
        </div>
    </div>

    @if ($count === 0)
        <p class="text-xs text-slate-400 italic py-2">— Aucune panne sur le dernier vol —</p>
    @else
        <ul class="space-y-2">
            @foreach ($top as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $desc = $d['Description'] ?? '';
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                @endphp
                <li class="text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-slate-900 font-medium truncate flex-1" title="{{ $teDesc }}">
                            {{ $teDesc }}
                        </p>
                        <span class="text-[11px] px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 tabular-nums font-medium">
                            ×{{ $te->nombre_occurrences }}
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 truncate">
                        {{ $desc }} · {{ $sysDesc }} · {{ $typeDesc }}
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

        @include('machines.partials.modal-last-flight', [
            'machine' => $machine,
            'flightDate' => $flightDate,
            'pannes' => $pannes,
            'modalId' => $modalId,
        ])
    @endif
</div>
