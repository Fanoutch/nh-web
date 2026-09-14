<div>
    {{-- Drop zone --}}
    <label for="csvFileInput"
           class="block bg-app-card border-2 border-dashed border-app-border rounded-xl p-12 text-center cursor-pointer hover:border-accent hover:bg-accent-soft transition-colors">
        <div class="w-12 h-12 mx-auto bg-app-bg rounded-lg flex items-center justify-center mb-4">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M12 3v14M6 9l6-6 6 6" stroke="#f5b731" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M3 19v1.5A1.5 1.5 0 004.5 22h15a1.5 1.5 0 001.5-1.5V19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="text-ink-muted"/>
            </svg>
        </div>
        <p class="text-[15px] font-medium text-ink-primary mb-1">Glisser-déposer le CSV du jour pour générer la dispo</p>
        <p class="text-sm text-ink-muted mb-4">ou cliquer pour sélectionner — un seul fichier, jusqu'à 20 Mo</p>
        <span class="inline-block bg-accent-soft border border-accent-soft-border text-warn px-3.5 py-1 rounded text-xs font-medium">
            Parcourir…
        </span>
        <input type="file" id="csvFileInput" accept=".csv,text/csv"
               wire:model="csvFile" class="hidden" />
    </label>

    {{-- Upload progress --}}
    <div wire:loading wire:target="csvFile" class="mt-4 text-sm text-ink-secondary">
        Upload en cours…
        <div class="h-2 mt-2 bg-app-border-soft rounded overflow-hidden">
            <div class="h-full bg-accent animate-pulse" style="width:100%"></div>
        </div>
    </div>

    @error('csvFile')
        <p class="mt-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    @if ($notice)
        <p class="mt-3 text-sm text-ok">{{ $notice }}</p>
    @endif

    {{-- Fichier en attente --}}
    @if ($csvFile)
        <div class="mt-6">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm font-semibold text-ink-primary">CSV prêt : générer la dispo</div>
                <x-primary-button wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M6.5 1L12 6.5 6.5 12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                        <path d="M1 6.5h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    </svg>
                    Générer la dispo
                </x-primary-button>
            </div>
            <x-card class="overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-2.5">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none" class="text-ink-muted shrink-0">
                        <rect x="1" y="1" width="9" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
                        <path d="M4 4h5M4 7h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    <span class="font-mono text-xs text-ink-primary flex-1 truncate">{{ $csvFile->getClientOriginalName() }}</span>
                    <span class="text-[11px] text-ink-muted">{{ number_format($csvFile->getSize() / 1024, 0) }} KB</span>
                    <button type="button" wire:click="removeFile"
                            class="text-ink-muted hover:text-danger transition-colors text-sm leading-none">×</button>
                </div>
            </x-card>
        </div>
    @endif

    {{-- Historique --}}
    <div class="mt-10" wire:poll.3s>
        <x-section-label class="mb-3">Dispos générées</x-section-label>
        <x-card class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-[12px]">
                    <thead>
                        <tr class="border-b border-app-border">
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">CSV déposé</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Date</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Par</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Statut</th>
                            <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Lignes</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Dispo (Excel)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($reports as $report)
                            @php
                                $statusLabel = match ($report->status) {
                                    'pending' => 'En attente',
                                    'processing' => 'Génération…',
                                    'ok' => 'OK',
                                    'error' => 'Erreur',
                                    default => $report->status,
                                };
                            @endphp
                            <tr wire:key="report-{{ $report->id }}"
                                @class([
                                    'border-b border-app-border-soft',
                                    'bg-accent-soft' => $report->status === 'processing',
                                ])>
                                <td class="px-4 py-2.5 font-mono text-xs text-ink-primary truncate max-w-xs">{{ $report->filename }}</td>
                                <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $report->created_at->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2.5 text-xs text-ink-secondary">{{ $report->user?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    <x-badge :variant="$report->status">{{ $statusLabel }}</x-badge>
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-xs">
                                    @if ($report->status === 'ok')
                                        <span class="text-ink-primary">{{ $report->rows_written }}</span>
                                    @else
                                        <span class="text-ink-muted">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-xs">
                                    @if ($report->isDownloadable())
                                        <a href="{{ route('bmn.download', $report) }}"
                                           class="font-mono text-[11px] text-accent hover:text-accent-pressed transition-colors">Télécharger ↓</a>
                                    @elseif ($report->status === 'error')
                                        <span class="font-mono text-[11px] text-danger" title="{{ $report->message }}">{{ \Illuminate\Support\Str::limit($report->message ?? 'Erreur', 60) }}</span>
                                    @else
                                        <span class="text-ink-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-12 text-center text-ink-muted text-sm">
                                    Aucune dispo générée pour le moment.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>
</div>
