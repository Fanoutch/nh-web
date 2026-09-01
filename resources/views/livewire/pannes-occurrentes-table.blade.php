<div class="space-y-3">
    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Code</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Description</th>
                        <th class="text-center px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-32">Occurrences</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-44">Statut PN</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-64">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pannes as $p)
                        <tr class="border-b border-app-border-soft" wire:key="pn-te-{{ $p->id }}">
                            <td class="px-4 py-2.5 font-mono text-ink-primary">{{ $p->technical_event_id }}</td>
                            <td class="px-4 py-2.5 text-ink-secondary">
                                {{ data_get($p->details, 'TechnicalEventDescription', '—') }}
                            </td>
                            <td class="px-4 py-2.5 text-center font-mono">
                                <span class="inline-flex items-center justify-center min-w-[2rem] px-2 py-0.5 rounded bg-accent-soft-strong text-warn font-semibold">
                                    {{ $p->nombre_occurrences }}
                                </span>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($p->pn_validation_status === 'confirmed')
                                    <span class="inline-flex items-center gap-1 bg-ok-soft text-ok border border-ok-border text-[11px] px-2 py-0.5 rounded font-mono">
                                        Confirmé · {{ $p->pnValidator?->name }}
                                    </span>
                                @elseif ($p->pn_validation_status === 'rejected')
                                    <span class="inline-flex items-center gap-1 bg-danger-soft text-danger border border-danger-border text-[11px] px-2 py-0.5 rounded font-mono">
                                        Rejeté · {{ $p->pnValidator?->name }}
                                    </span>
                                @else
                                    <span class="text-ink-muted text-xs italic">En attente</span>
                                @endif
                                @if ($p->pn_comment)
                                    <div class="mt-1 text-[11px] text-ink-secondary italic">« {{ $p->pn_comment }} »</div>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex flex-col gap-1.5">
                                    <input type="text"
                                           wire:model="comments.{{ $p->id }}"
                                           placeholder="Commentaire (optionnel)"
                                           class="w-full px-2 py-1 rounded border border-app-border bg-transparent text-[11px] text-ink-primary placeholder:text-ink-muted placeholder:italic focus:outline-none focus:border-accent" />
                                    <div class="flex gap-1.5">
                                        <button wire:click="setPnValidation({{ $p->id }}, 'confirmed')"
                                                class="px-3 py-1 rounded bg-ok-soft border border-ok-border text-ok text-[11px] font-medium hover:bg-ok-border transition">
                                            Confirmer
                                        </button>
                                        <button wire:click="setPnValidation({{ $p->id }}, 'rejected')"
                                                class="px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                            Rejeter
                                        </button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-ink-muted italic">
                                — Aucune panne occurrente sur ce vol —
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
