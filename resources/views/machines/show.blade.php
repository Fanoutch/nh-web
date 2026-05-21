<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.index') }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Machines
        </a>
    </div>

    {{-- Header --}}
    <div class="flex items-baseline gap-4 mb-6">
        <h1 class="font-mono text-[28px] font-medium text-accent">{{ $machine->hc_id }}</h1>
        <div class="text-[13px] text-ink-muted">{{ $totalCount }} enregistrement(s)</div>
        <div class="flex-1"></div>
        <div class="flex items-center gap-2.5">
            <x-counter-pill :value="$counts['vols']" label="Vols" boxed />
            <x-counter-pill :value="$counts['non-vols']" label="Non-Vols" variant="secondary" boxed />
            @if (auth()->user()?->isSuperAdmin())
                <x-counter-pill :value="$counts['erreurs']" label="Erreurs" :variant="$counts['erreurs'] > 0 ? 'danger' : 'secondary'" boxed />
            @endif
        </div>
    </div>

    @php
        $labels = ['vols' => 'Vols', 'non-vols' => 'Non-Vols'];
        if (auth()->user()?->isSuperAdmin()) {
            $labels['erreurs'] = 'Erreurs';
        }
    @endphp

    {{-- Tabs --}}
    <div class="border-b border-app-border mb-0">
        <nav class="flex gap-1">
            @foreach ($labels as $key => $label)
                @php $isActive = $tab === $key; @endphp
                <a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => $key]) }}"
                   @class([
                       'px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors',
                       'text-accent-pressed border-accent' => $isActive,
                       'text-ink-secondary border-transparent hover:text-ink-primary' => !$isActive,
                   ])>
                    {{ $label }} ({{ $counts[$key] }})
                </a>
            @endforeach
        </nav>
    </div>

    {{-- Tableau --}}
    <x-card class="rounded-t-none border-t-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Date</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">DSN</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Num</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Type</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Heures vol</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Pannes</th>
                        @if ($tab === 'erreurs')
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Signalé par</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Signalé le</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($flights as $flight)
                        @php
                            $route = $tab === 'non-vols'
                                ? route('flights.non-vol', $flight)
                                : route('flights.show', $flight);
                            $pannesCount = $flight->conservees_count ?? 0;
                            $pannesBadgeVariant = $pannesCount === 0 ? 'pending'
                                : ($pannesCount > 5 ? 'error' : 'amber');
                        @endphp
                        <tr class="border-b border-app-border-soft hover:bg-app-bg cursor-pointer transition-colors"
                            onclick="window.location='{{ $route }}'">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary">
                                {{ $flight->end_datetime->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $flight->dsn }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary">{{ $flight->num }}</td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($tab === 'erreurs')
                                    <x-badge variant="error">Erreur</x-badge>
                                @elseif ($tab === 'non-vols')
                                    <x-badge variant="nonvol">Non-Vol</x-badge>
                                @else
                                    <span class="text-ink-secondary">Normal</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($tab === 'vols')
                                    {{ number_format($flight->flight_hours, 1) }}h
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($tab === 'vols')
                                    <x-badge :variant="$pannesBadgeVariant">{{ $pannesCount }}</x-badge>
                                @else
                                    <span class="font-mono text-xs text-ink-muted">—</span>
                                @endif
                            </td>
                            @if ($tab === 'erreurs')
                                <td class="px-4 py-2.5 text-xs text-ink-secondary">{{ $flight->flaggedBy?->email ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-ink-secondary font-mono">{{ $flight->flagged_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $tab === 'erreurs' ? 8 : 6 }}" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun vol dans cet onglet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        @if ($flights->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $flights->firstItem() }}–{{ $flights->lastItem() }} de {{ $flights->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($flights->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <a href="{{ $flights->previousPageUrl() }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</a>
                    @endif
                    @if ($flights->hasMorePages())
                        <a href="{{ $flights->nextPageUrl() }}"
                           class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</a>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</x-app-layout>
