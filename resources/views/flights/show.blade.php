<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.show', $flight->machine->hc_id) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← {{ $flight->machine->hc_id }}
        </a>
    </div>

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Vol · {{ $flight->end_datetime->format('d/m/Y') }}</x-section-label>
            <h1 class="text-[20px] font-semibold text-ink-primary">
                {{ $flight->dsn }} <span class="font-mono text-accent">#{{ $flight->num }}</span>
            </h1>
        </div>
        <a href="{{ route('flights.xml', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M6.5 1v8M3.5 6.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                <path d="M1 10v1.5a.5.5 0 00.5.5h10a.5.5 0 00.5-.5V10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
            Télécharger XML épuré
        </a>
    </div>

    {{-- Meta strip --}}
    <x-card class="px-5 py-4 mb-5">
        <div class="flex gap-8 flex-wrap">
            <div>
                <x-section-label class="mb-1">Appareil</x-section-label>
                <div class="font-mono text-sm text-accent">{{ $flight->machine->hc_id }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Date</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ $flight->end_datetime->format('d/m/Y') }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Durée</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ number_format($flight->flight_hours, 1) }}h</div>
            </div>
            <div>
                <x-section-label class="mb-1">Carburant</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ $flight->consumed_fuel ?? '—' }}{{ $flight->consumed_fuel ? ' kg' : '' }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Type</x-section-label>
                <div class="text-sm text-ink-primary">Normal</div>
            </div>
        </div>
    </x-card>

    {{-- 2 cards Pannes --}}
    <div class="grid grid-cols-2 gap-4">
        <a href="{{ route('flights.pannes-conservees', $flight) }}"
           class="block bg-app-card border border-app-border rounded-lg p-5 hover:border-accent transition group">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm text-ink-primary">Pannes conservées</div>
                <span class="font-mono text-[22px] font-medium text-accent">{{ $counts['conservees'] }}</span>
            </div>
            <div class="text-xs text-ink-muted leading-relaxed">
                Pannes retenues par la pipeline dans la fenêtre de 48h. Validation technicien requise.
            </div>
            <div class="mt-3 text-xs text-accent group-hover:text-accent-pressed">Ouvrir le tableau →</div>
        </a>
        <a href="{{ route('flights.pannes-isolees', $flight) }}"
           class="block bg-app-card border border-app-border rounded-lg p-5 hover:border-ink-secondary transition group">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm text-ink-primary">Pannes isolées</div>
                <span class="font-mono text-[22px] font-medium text-ink-secondary">{{ $counts['isolees'] }}</span>
            </div>
            <div class="text-xs text-ink-muted leading-relaxed">
                Pannes filtrées (date hors fenêtre 48h). Information uniquement.
            </div>
            <div class="mt-3 text-xs text-ink-secondary">Ouvrir le tableau →</div>
        </a>
    </div>
</x-app-layout>
