<div wire:poll.2s>
    @php
        $filters = ['all' => 'Tous', 'pending' => 'En attente'];
    @endphp

    {{-- Tabs filter (simplifiés : Tous + En attente uniquement) --}}
    <div class="border-b border-app-border mb-0">
        <nav class="flex gap-1">
            @foreach ($filters as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        @class([
                            'px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors',
                            'text-accent-pressed border-accent' => $filter === $key,
                            'text-ink-secondary border-transparent hover:text-ink-primary' => $filter !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tableau --}}
    <x-card class="rounded-t-none border-t-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Fichier</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Date upload</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Statut</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">HcId</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">DSN</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Pannes</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">H. vol</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Lien</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($imports as $import)
                        @php
                            $statusBadgeVariant = match ($import->status) {
                                'ok' => 'ok',
                                'processing' => 'processing',
                                'pending' => 'pending',
                                'non_vol' => 'nonvol',
                                'already_processed' => 'already',
                                'error' => 'error',
                                default => 'pending',
                            };
                            $statusLabel = match ($import->status) {
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'ok' => 'OK',
                                'already_processed' => 'Déjà traité',
                                'non_vol' => 'Non-Vol',
                                'error' => 'Erreur',
                                default => $import->status,
                            };
                            $isProcessing = $import->status === 'processing';
                        @endphp
                        <tr wire:key="import-{{ $import->id }}"
                            @class([
                                'border-b border-app-border-soft',
                                'bg-accent-soft' => $isProcessing,
                            ])>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary truncate max-w-xs">{{ $import->filename }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :variant="$statusBadgeVariant">{{ $statusLabel }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-accent">{{ data_get($import->result, 'hc_id', '—') }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ data_get($import->result, 'dsn', '—') }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($import->status === 'ok')
                                    <span class="text-ink-primary">{{ data_get($import->result, 'pannes_conservees_count', 0) }}</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($import->status === 'ok')
                                    <span class="text-ink-primary">{{ number_format(data_get($import->result, 'flight_hours', 0), 1) }}h</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($import->flight_id && $import->status === 'ok')
                                    <a href="{{ route('flights.show', $import->flight_id) }}"
                                       class="font-mono text-[11px] text-accent hover:text-accent-pressed transition-colors">Voir vol →</a>
                                @elseif ($import->flight_id && $import->status === 'non_vol')
                                    <a href="{{ route('flights.non-vol', $import->flight_id) }}"
                                       class="font-mono text-[11px] text-accent hover:text-accent-pressed transition-colors">Voir →</a>
                                @elseif ($import->status === 'error')
                                    <span class="font-mono text-[11px] text-danger">{{ \Illuminate\Support\Str::limit(data_get($import->result, 'message', 'Parse error'), 30) }}</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun import{{ $filter === 'pending' ? ' en attente' : '' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
