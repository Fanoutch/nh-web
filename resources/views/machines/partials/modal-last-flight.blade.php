<x-modal name="{{ $modalId }}" maxWidth="2xl">
    {{-- Header --}}
    <div class="px-5 py-4 border-b border-app-border flex items-start justify-between">
        <div>
            <x-section-label class="mb-0.5">{{ $machine->hc_id }} · {{ $flightDate }}</x-section-label>
            <h2 class="text-[15px] font-semibold text-ink-primary">Pannes du dernier vol</h2>
        </div>
        <button x-on:click="$dispatch('close')"
                class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
    </div>

    {{-- Body --}}
    <div class="p-5 flex flex-col gap-2 max-h-[60vh] overflow-y-auto">
        @foreach ($pannes as $te)
            @php
                $d = is_array($te->details) ? $te->details : [];
                $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                $desc = $d['Description'] ?? '';
                $sysDesc = $d['SystemDescription'] ?? '';
                $typeDesc = $d['TypeDescription'] ?? '';
                $occ = $te->nombre_occurrences;
                $borderColor = $occ >= 3 ? 'border-l-danger' : ($occ === 2 ? 'border-l-accent' : 'border-l-app-border');
                $badgeVariant = $occ >= 3 ? 'error' : ($occ === 2 ? 'amber' : 'pending');
            @endphp
            <div class="px-3.5 py-3 bg-app-bg rounded-md border-l-[3px] {{ $borderColor }}">
                <div class="flex items-center justify-between mb-1.5">
                    <x-badge :variant="$badgeVariant">×{{ $occ }} occurrence{{ $occ > 1 ? 's' : '' }}</x-badge>
                    <span class="font-mono text-[10px] text-ink-muted">{{ $sysDesc }} · {{ $typeDesc }}</span>
                </div>
                <div class="text-[13px] font-medium text-ink-primary">{{ $teDesc }}</div>
                @if ($desc)
                    <div class="text-[11px] text-ink-muted mt-1">{{ $desc }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="px-5 py-3 border-t border-app-border-soft flex justify-end">
        <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
    </div>
</x-modal>
