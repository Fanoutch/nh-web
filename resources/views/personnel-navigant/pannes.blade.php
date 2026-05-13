<x-app-layout>
    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3 mb-1">
            <a href="{{ route('personnel-navigant.show', $flight->machine->hc_id) }}" class="text-ink-muted hover:text-ink-primary text-sm">← Retour</a>
            <h1 class="text-[22px] font-semibold text-ink-primary leading-tight">
                {{ $flight->machine->hc_id }} · Vol DSN {{ $flight->dsn }}
            </h1>
        </div>
        <p class="text-[13px] text-ink-muted mb-4">
            Confirme ou rejette les occurrences de pannes que tu as constatées sur ce vol.
        </p>

        @livewire('pannes-occurrentes-table', ['flight' => $flight])
    </div>
</x-app-layout>
