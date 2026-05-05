<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.show', $flight->machine->hc_id) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← {{ $flight->machine->hc_id }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-6">
        <x-section-label class="mb-1">Non-Vol · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            {{ $flight->dsn }} <span class="font-mono text-ink-secondary">#{{ $flight->num }}</span>
        </h1>
    </div>

    {{-- 2 cards --}}
    <div class="grid grid-cols-2 gap-4 mb-6">
        <x-card class="p-5">
            <x-section-label class="mb-3">Motif de classification</x-section-label>
            <div class="space-y-2">
                <div class="flex items-start gap-3 px-3 py-2.5 bg-app-bg rounded">
                    <div class="w-2 h-2 bg-accent rounded-full shrink-0 mt-1.5"></div>
                    <div>
                        <div class="text-sm text-ink-primary font-medium">Type détecté : {{ $flight->flight_type }}</div>
                        <div class="text-[11px] text-ink-muted font-mono mt-1">Pas de phase de vol active identifiée par la pipeline.</div>
                    </div>
                </div>
            </div>
        </x-card>
        <x-card class="p-5">
            <x-section-label class="mb-3">Métadonnées</x-section-label>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-ink-muted">Appareil</dt><dd class="font-mono text-accent">{{ $flight->machine->hc_id }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">DSN</dt><dd class="font-mono text-ink-secondary">{{ $flight->dsn }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">Date</dt><dd class="font-mono text-ink-primary">{{ $flight->start_datetime->format('d/m/Y') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">Statut</dt><dd><x-badge variant="nonvol">Non-Vol</x-badge></dd></div>
            </dl>
        </x-card>
    </div>

    {{-- Action ou alert --}}
    @if ($flight->flagged_as_error)
        <div class="px-4 py-3 bg-danger-soft border border-danger-border rounded-md text-sm text-danger">
            <div class="font-semibold mb-0.5">Signalement enregistré</div>
            Ce vol a été marqué comme mal classifié par <strong>{{ $flight->flaggedBy?->email ?? 'utilisateur' }}</strong> le {{ $flight->flagged_at?->format('d/m/Y H:i') }}. Page en lecture seule.
        </div>
    @else
        <div class="flex justify-end">
            <form method="POST" action="{{ route('flights.flag-as-error', $flight) }}">
                @csrf
                <x-danger-button>
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M6.5 2v5M6.5 9.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    Signaler comme erreur de classification
                </x-danger-button>
            </form>
        </div>
    @endif
</x-app-layout>
