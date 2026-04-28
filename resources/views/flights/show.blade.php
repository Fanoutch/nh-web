<x-app-layout>
    <nav class="flex items-center gap-2 text-sm text-slate-500 mb-4">
        <a href="{{ route('machines.index') }}" class="hover:text-slate-700">Machines</a>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-3 h-3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
        <a href="{{ route('machines.show', $flight->machine->hc_id) }}" class="hover:text-slate-700">{{ $flight->machine->hc_id }}</a>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-3 h-3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
        <span class="text-slate-700">Vol n°{{ $flight->num }}</span>
    </nav>

    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Vol n°{{ $flight->num }}</h1>
            <p class="text-sm text-slate-500 mt-1">{{ $flight->machine->hc_id }} · DSN {{ $flight->dsn }}</p>
        </div>
        <a href="{{ route('flights.xml', $flight) }}"
           class="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm hover:bg-slate-50 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5m-13.5-9L12 3m0 0 4.5 4.5M12 3v13.5" />
            </svg>
            Telecharger XML
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-xl p-6 mb-6 grid md:grid-cols-4 gap-6">
        <div>
            <p class="text-[10px] uppercase tracking-wider font-semibold text-slate-500 mb-1">Type</p>
            <span class="inline-block text-[11px] px-2 py-0.5 rounded uppercase font-medium bg-blue-100 text-blue-700">{{ $flight->flight_type }}</span>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-wider font-semibold text-slate-500 mb-1">Duree (FH)</p>
            <p class="text-lg font-bold text-slate-900 tabular-nums">{{ number_format($flight->flight_hours, 2) }} h</p>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-wider font-semibold text-slate-500 mb-1">Debut</p>
            <p class="text-sm text-slate-800 tabular-nums">{{ $flight->start_datetime->format('d/m/Y H:i:s') }}</p>
        </div>
        <div>
            <p class="text-[10px] uppercase tracking-wider font-semibold text-slate-500 mb-1">Fin</p>
            <p class="text-sm text-slate-800 tabular-nums">{{ $flight->end_datetime->format('d/m/Y H:i:s') }}</p>
        </div>
        <div class="md:col-span-4">
            <p class="text-[10px] uppercase tracking-wider font-semibold text-slate-500 mb-1">Carburant consomme</p>
            <p class="text-sm text-slate-800">{{ $flight->consumed_fuel ?? '—' }}</p>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-slate-900 mb-3">Pannes du vol</h2>
    <div class="grid md:grid-cols-2 gap-4">
        <a href="{{ route('flights.pannes-conservees', $flight) }}"
           class="group block bg-white rounded-xl border border-slate-200 p-6 hover:border-blue-300 hover:shadow-lg transition">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-wider font-semibold text-slate-500">Pannes conservees</p>
                    <p class="text-4xl font-bold text-blue-600 mt-1 tabular-nums">{{ $counts['conservees'] }}</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6 text-slate-300 group-hover:text-blue-500 transition">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </div>
        </a>
        <a href="{{ route('flights.pannes-isolees', $flight) }}"
           class="group block bg-white rounded-xl border border-slate-200 p-6 hover:border-amber-300 hover:shadow-lg transition">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-[11px] uppercase tracking-wider font-semibold text-slate-500">Pannes isolees</p>
                    <p class="text-4xl font-bold text-amber-600 mt-1 tabular-nums">{{ $counts['isolees'] }}</p>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6 text-slate-300 group-hover:text-amber-500 transition">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                </svg>
            </div>
        </a>
    </div>
</x-app-layout>
