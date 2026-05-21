<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('flights.show', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Vol {{ $flight->dsn }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-5">
        <x-section-label class="mb-1">{{ $flight->machine->hc_id }} · {{ $flight->end_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            Pannes conservées <span class="font-mono text-accent">{{ $flight->technicalEvents()->where('status', 'conservee')->count() }}</span>
        </h1>
    </div>

    <livewire:pannes-conservees-table :flight="$flight" />
</x-app-layout>
