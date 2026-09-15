<x-app-layout>
    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-[22px] font-semibold text-ink-primary mb-1">Secteurs</h1>
        <p class="text-[13px] text-ink-muted mb-5">
            Sélectionnez un secteur pour accéder à ses outils.
        </p>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
            @foreach ($cartes as $carte)
                <a href="{{ route('secteurs.show', $carte['secteur']) }}"
                   class="block bg-app-elevated border border-app-border rounded-lg px-5 py-6 text-center hover:border-accent hover:bg-app-bg transition group"
                   wire:key="secteur-{{ $carte['secteur']->id }}">
                    <div class="text-2xl font-semibold text-ink-primary group-hover:text-accent tracking-wider">
                        {{ $carte['secteur']->nom }}
                    </div>
                    <div class="mt-1 text-[11px] text-ink-muted">{{ $carte['role'] }}</div>
                </a>
            @endforeach
        </div>

        @if ($cartes->isEmpty())
            <p class="text-[13px] text-ink-muted italic py-8 text-center">Aucun secteur accessible.</p>
        @endif
    </div>
</x-app-layout>
