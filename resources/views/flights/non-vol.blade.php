<x-app-layout>
    <x-slot name="header">
        Non vol — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}
    </x-slot>

    <div class="bg-white shadow rounded p-6 grid md:grid-cols-2 gap-4 mb-6">
        <div><p class="text-sm text-gray-500">Type</p><p class="font-medium">{{ $flight->flight_type }}</p></div>
        <div><p class="text-sm text-gray-500">DSN</p><p class="font-medium">{{ $flight->dsn }}</p></div>
        <div><p class="text-sm text-gray-500">Debut</p><p class="font-medium">{{ $flight->start_datetime->format('d/m/Y H:i') }}</p></div>
        <div><p class="text-sm text-gray-500">Fin</p><p class="font-medium">{{ $flight->end_datetime->format('d/m/Y H:i') }}</p></div>
        <div>
            <a href="{{ route('flights.xml', $flight) }}"
               class="inline-block bg-gray-200 px-3 py-1 rounded text-sm hover:bg-gray-300">
                Telecharger XML
            </a>
        </div>
    </div>

    @if ($flight->flagged_as_error)
        <div class="bg-yellow-50 border border-yellow-200 p-4 rounded text-sm text-yellow-800">
            Signale comme erreur par
            <span class="font-medium">{{ $flight->flaggedBy->email ?? 'utilisateur' }}</span>
            le {{ $flight->flagged_at->format('d/m/Y H:i') }}.
        </div>
    @else
        <form method="POST" action="{{ route('flights.flag-as-error', $flight) }}">
            @csrf
            <button type="submit"
                    class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                C'est un vol
            </button>
        </form>
    @endif
</x-app-layout>
