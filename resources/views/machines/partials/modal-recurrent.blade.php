<x-modal name="{{ $modalId }}" maxWidth="2xl">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">
            Pannes occurrentes actives — {{ $machine->hc_id }}
        </h2>
        <div class="space-y-3 max-h-[60vh] overflow-y-auto">
            @foreach ($actives as $rf)
                <div class="border border-slate-200 rounded-lg p-3">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm text-slate-900 font-medium">{{ $rf->te_description ?? $rf->technical_event_id }}</p>
                        <span class="text-[11px] px-2 py-0.5 rounded bg-blue-100 text-blue-700 font-medium tabular-nums">
                            {{ $rf->score }}/3
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $rf->description }} · {{ $rf->system_description }} · {{ $rf->type_description }}
                    </p>
                </div>
            @endforeach
        </div>
        <div class="mt-5 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
        </div>
    </div>
</x-modal>
