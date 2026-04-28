<div wire:poll.2s class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    @php
        $filters = ['all' => 'Tous', 'pending' => 'En attente', 'done' => 'Termines', 'errors' => 'Erreurs'];
    @endphp
    <div class="border-b border-slate-200">
        <nav class="flex gap-1 px-4">
            @foreach ($filters as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        @class([
                            'px-4 py-3 text-sm font-medium border-b-2 -mb-px transition',
                            'border-blue-600 text-blue-600' => $filter === $key,
                            'border-transparent text-slate-600 hover:text-slate-900' => $filter !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left">
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Fichier</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Statut</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Machine</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">DSN</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Num</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Details</th>
                    <th class="px-4 py-3 text-[10px] uppercase tracking-wider font-semibold text-slate-500">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($imports as $import)
                    <tr wire:key="import-{{ $import->id }}">
                        <td class="px-4 py-3 truncate max-w-xs text-slate-800">{{ $import->filename }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'px-2 py-0.5 rounded-full text-xs font-medium',
                                'bg-slate-100 text-slate-700' => in_array($import->status, ['pending', 'processing']),
                                'bg-green-100 text-green-800' => $import->status === 'ok',
                                'bg-amber-100 text-amber-800' => in_array($import->status, ['non_vol', 'already_processed']),
                                'bg-red-100 text-red-800' => $import->status === 'error',
                            ])>
                                {{ match($import->status) {
                                    'pending' => 'En attente',
                                    'processing' => 'En cours',
                                    'ok' => 'Traite',
                                    'already_processed' => 'Deja traite',
                                    'non_vol' => 'Non vol',
                                    'error' => 'Erreur',
                                    default => $import->status,
                                } }}
                            </span>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-700">{{ data_get($import->result, 'hc_id', '—') }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ data_get($import->result, 'dsn', '—') }}</td>
                        <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ data_get($import->result, 'num', '—') }}</td>
                        <td class="px-4 py-3 text-xs text-slate-600">
                            @if ($import->status === 'ok')
                                {{ data_get($import->result, 'pannes_conservees_count', 0) }} pannes · {{ data_get($import->result, 'flight_hours', 0) }}h
                                @if ($import->flight_id)
                                    · <a href="{{ route('flights.show', $import->flight_id) }}" class="text-blue-600 hover:underline">Voir</a>
                                @endif
                            @elseif ($import->status === 'non_vol')
                                XML non vol
                                @if ($import->flight_id)
                                    · <a href="{{ route('flights.non-vol', $import->flight_id) }}" class="text-blue-600 hover:underline">Voir</a>
                                @endif
                            @elseif ($import->status === 'already_processed')
                                Deja present
                            @elseif ($import->status === 'error')
                                {{ \Illuminate\Support\Str::limit(data_get($import->result, 'message', ''), 60) }}
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-slate-400 text-sm">
                            Aucun import
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
