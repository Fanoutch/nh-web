<x-app-layout>
    {{-- Breadcrumb --}}
    <nav class="flex items-center gap-2 text-sm text-slate-500 mb-4">
        <a href="{{ route('machines.index') }}" class="hover:text-slate-700">Machines</a>
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-3 h-3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
        </svg>
        <span class="text-slate-700">{{ $machine->hc_id }}</span>
    </nav>

    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-900">{{ $machine->hc_id }}</h1>
        <p class="text-sm text-slate-500 mt-1">Detail de la machine</p>
    </div>

    @php
        $counts = [
            'vols' => $machine->flights()->where('is_non_vol', false)->count(),
            'non-vols' => $machine->flights()->where('is_non_vol', true)->where('flagged_as_error', false)->count(),
            'erreurs' => $machine->flights()->where('flagged_as_error', true)->count(),
        ];
        $labels = ['vols' => 'Vols', 'non-vols' => 'Non-vols', 'erreurs' => 'Erreurs'];
    @endphp

    {{-- Onglets --}}
    <div class="border-b border-slate-200 mb-0">
        <nav class="flex gap-1">
            @foreach ($labels as $key => $label)
                @php $isActive = $tab === $key; @endphp
                <a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => $key]) }}"
                   @class([
                       'flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 -mb-px transition',
                       'border-blue-600 text-blue-600' => $isActive,
                       'border-transparent text-slate-600 hover:text-slate-900' => !$isActive,
                   ])>
                    {{ $label }}
                    <span @class([
                        'text-xs px-2 py-0.5 rounded-full min-w-[1.5rem] text-center',
                        'bg-blue-100 text-blue-700' => $isActive,
                        'bg-slate-100 text-slate-600' => !$isActive,
                    ])>{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Tableau --}}
    <div class="bg-white border border-slate-200 border-t-0 rounded-b-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left">
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Date debut</th>
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">DSN</th>
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Num</th>
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Type</th>
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500 text-right">Heures vol</th>
                        <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500 text-right">Pannes</th>
                        @if ($tab === 'erreurs')
                            <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Signale par</th>
                            <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Signale le</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($flights as $flight)
                        @php
                            $route = $tab === 'vols'
                                ? route('flights.show', $flight)
                                : route('flights.non-vol', $flight);
                            $isFlight = strtoupper($flight->flight_type) === 'FLIGHT';
                        @endphp
                        <tr class="hover:bg-slate-50 cursor-pointer" onclick="window.location='{{ $route }}'">
                            <td class="px-4 py-3">
                                <span class="text-blue-600 font-medium">{{ $flight->start_datetime->format('d/m/Y H:i') }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $flight->dsn }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-700">{{ $flight->num }}</td>
                            <td class="px-4 py-3">
                                <span @class([
                                    'text-[11px] px-2 py-0.5 rounded uppercase font-medium',
                                    'bg-blue-100 text-blue-700' => $isFlight,
                                    'bg-slate-200 text-slate-600' => !$isFlight,
                                ])>{{ $flight->flight_type }}</span>
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ number_format($flight->flight_hours, 1) }}</td>
                            <td @class([
                                'px-4 py-3 text-right tabular-nums',
                                'text-slate-400' => ($flight->conservees_count ?? 0) === 0,
                                'text-slate-900 font-medium' => ($flight->conservees_count ?? 0) > 0,
                            ])>{{ $flight->conservees_count ?? 0 }}</td>
                            @if ($tab === 'erreurs')
                                <td class="px-4 py-3 text-xs text-slate-600">{{ $flight->flaggedBy?->email ?? '—' }}</td>
                                <td class="px-4 py-3 text-xs text-slate-600">{{ $flight->flagged_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-slate-400 text-sm">
                                Aucun vol dans cet onglet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($flights->hasPages())
            <div class="px-4 py-3 border-t border-slate-100">
                {{ $flights->withQueryString()->links() }}
            </div>
        @endif
    </div>
</x-app-layout>
