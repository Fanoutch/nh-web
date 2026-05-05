<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('flights.show', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Vol {{ $flight->dsn }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-4">
        <x-section-label class="mb-1">{{ $flight->machine->hc_id }} · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            Pannes isolées <span class="font-mono text-ink-secondary">{{ $pannes->count() }}</span>
        </h1>
    </div>

    {{-- Alert amber --}}
    <div class="mb-4 px-4 py-3 bg-accent-soft border border-accent-soft-border rounded-md text-sm text-warn">
        <div class="font-semibold mb-0.5">Pannes hors fenêtre 48h</div>
        Ces pannes ont été détectées mais datent de plus de 48h avant/après la date du vol. Information uniquement (pas de validation requise).
    </div>

    {{-- Card list read-only --}}
    <div class="space-y-2">
        @forelse ($pannes as $p)
            @php
                $teDesc = data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') ?? $p->technical_event_id;
                $sysDesc = data_get($p->details, 'SystemDescription') ?? '—';
                $typeDesc = data_get($p->details, 'TypeDescription') ?? '—';
                $failureCode = data_get($p->details, 'FailureCode') ?? '—';
                $ecart = data_get($p->details, 'ecart') ?? data_get($p->details, 'ecart_vol') ?? '—';
                $raise = $p->raise_datetime?->format('d/m/Y H:i') ?? '—';
            @endphp
            <div class="px-5 py-4 bg-app-card border border-app-border rounded-md border-l-[3px] border-l-app-border">
                <div class="font-mono text-[10px] text-ink-muted mb-1.5">
                    {{ $sysDesc }} · Fault Code {{ $failureCode }} · ×{{ $p->nombre_occurrences }} occurrence{{ $p->nombre_occurrences > 1 ? 's' : '' }}
                </div>
                <div class="text-[13px] font-medium text-ink-primary">{{ $teDesc }}</div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-muted mt-1.5">
                    <span>Système : {{ $sysDesc }} | Type : {{ $typeDesc }}</span>
                    <span class="text-warn font-medium">Hors fenêtre {{ $ecart }}</span>
                    <span class="font-mono">Détectée le {{ $raise }}</span>
                </div>
            </div>
        @empty
            <x-card class="p-12 text-center text-ink-muted text-sm">
                Aucune panne isolée pour ce vol.
            </x-card>
        @endforelse
    </div>
</x-app-layout>
