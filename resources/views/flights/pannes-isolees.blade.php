<x-app-layout>
    <x-slot name="header">
        Pannes isolees — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}
    </x-slot>
    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="p-3">ID panne</th>
                    <th class="p-3">RaiseDateTime</th>
                    <th class="p-3">Ecart</th>
                    <th class="p-3">Raison</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pannes as $p)
                    <tr class="border-t">
                        <td class="p-3 font-mono text-xs">{{ $p->technical_event_id }}</td>
                        <td class="p-3">{{ $p->raise_datetime->format('d/m/Y H:i:s') }}</td>
                        <td class="p-3">{{ data_get($p->details, 'ecart') ?? data_get($p->details, 'ecart_vol') ?? '—' }}</td>
                        <td class="p-3">{{ data_get($p->details, 'raison') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-6 text-center text-gray-500">Aucune panne isolee.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
