<x-app-layout>
    <x-slot name="header">
        Pannes conservees — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}
    </x-slot>
    <p class="text-sm text-gray-600 mb-4">
        Heure du vol :
        {{ $flight->start_datetime->format('d/m/Y H:i') }}
        →
        {{ $flight->end_datetime->format('d/m/Y H:i') }}
    </p>
    <livewire:pannes-conservees-table :flight="$flight" />
</x-app-layout>
