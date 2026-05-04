<x-modal name="{{ $modalId }}" maxWidth="2xl">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">
            Pannes du dernier vol — {{ $machine->hc_id }} — {{ $flightDate }}
        </h2>
        <div class="space-y-3 max-h-[60vh] overflow-y-auto">
            @foreach ($pannes as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $desc = $d['Description'] ?? '';
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                @endphp
                <div class="border border-slate-200 rounded-lg p-3">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm text-slate-900 font-medium">{{ $teDesc }}</p>
                        <span class="text-[11px] px-2 py-0.5 rounded bg-slate-200 text-slate-700 font-medium tabular-nums">
                            ×{{ $te->nombre_occurrences }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $desc }} · {{ $sysDesc }} · {{ $typeDesc }}
                    </p>
                </div>
            @endforeach
        </div>
        <div class="mt-5 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
        </div>
    </div>
</x-modal>
