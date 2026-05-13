<x-app-layout>
    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3 mb-1">
            <a href="{{ route('personnel-navigant.index') }}" class="text-ink-muted hover:text-ink-primary text-sm">← Retour</a>
            <h1 class="text-[22px] font-semibold text-ink-primary leading-tight">{{ $machine->hc_id }}</h1>
        </div>
        <p class="text-[13px] text-ink-muted mb-4">
            Sélectionne un vol pour visualiser ses pannes occurrentes.
        </p>

        <x-card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[13px]">
                    <thead>
                        <tr class="border-b border-app-border">
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">DSN</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Numéro</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Début</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Fin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($flights as $flight)
                            <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors cursor-pointer"
                                onclick="window.location='{{ route('personnel-navigant.pannes', $flight) }}'"
                                wire:key="pn-flight-{{ $flight->id }}">
                                <td class="px-4 py-2.5 font-mono text-ink-primary">
                                    <a href="{{ route('personnel-navigant.pannes', $flight) }}" class="hover:text-accent">{{ $flight->dsn }}</a>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-ink-secondary">{{ $flight->num }}</td>
                                <td class="px-4 py-2.5 text-ink-secondary">{{ $flight->start_datetime?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2.5 text-ink-secondary">{{ $flight->end_datetime?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-6 text-center text-ink-muted italic">Aucun vol enregistré.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="mt-4">{{ $flights->links() }}</div>
    </div>
</x-app-layout>
