<x-modal name="{{ $modalId }}" maxWidth="2xl">
    {{-- Header --}}
    <div class="px-5 py-4 border-b border-app-border flex items-start justify-between">
        <div>
            <x-section-label class="mb-0.5">{{ $machine->hc_id }}</x-section-label>
            <h2 class="text-[15px] font-semibold text-ink-primary">Pannes occurrentes actives</h2>
        </div>
        <button x-on:click="$dispatch('close')"
                class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
    </div>

    {{-- Body --}}
    <div class="p-5 flex flex-col gap-2 max-h-[60vh] overflow-y-auto">
        @foreach ($actives as $rf)
            @php
                $borderColor = $rf->score >= 3 ? 'border-l-danger' : 'border-l-accent';
                $badgeVariant = $rf->score >= 3 ? 'error' : 'amber';
            @endphp
            <div class="px-3.5 py-3 bg-app-bg rounded-md border-l-[3px] {{ $borderColor }}">
                <div class="flex items-center justify-between mb-1.5">
                    <x-badge :variant="$badgeVariant">Score {{ $rf->score }}/3</x-badge>
                    <span class="font-mono text-[10px] text-ink-muted">
                        {{ $rf->system_description ?? '—' }} · {{ $rf->type_description ?? '—' }}
                    </span>
                </div>
                <div class="text-[13px] font-medium text-ink-primary">
                    {{ $rf->te_description ?? $rf->technical_event_id }}
                </div>
                @if ($rf->description)
                    <div class="text-[11px] text-ink-muted mt-1">{{ $rf->description }}</div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Footer --}}
    <div class="px-5 py-3 border-t border-app-border-soft flex justify-end">
        <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
    </div>
</x-modal>
