<x-app-layout>
    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-[22px] font-semibold text-ink-primary mb-1">Personnel Navigant</h1>
        <p class="text-[13px] text-ink-muted mb-5">
            Sélectionne une machine pour accéder à ses vols et valider les pannes occurrentes.
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
            @foreach ($machines as $m)
                <a href="{{ route('personnel-navigant.show', $m->hc_id) }}"
                   class="block bg-app-elevated border border-app-border rounded-lg px-5 py-6 text-center hover:border-accent hover:bg-app-bg transition group"
                   wire:key="pn-machine-{{ $m->id }}">
                    <div class="text-2xl font-semibold text-ink-primary group-hover:text-accent tracking-wider">
                        {{ $m->hc_id }}
                    </div>
                </a>
            @endforeach
        </div>

        @if ($machines->isEmpty())
            <p class="text-[13px] text-ink-muted italic py-8 text-center">Aucune machine enregistrée.</p>
        @endif
    </div>
</x-app-layout>
