<div class="space-y-4">
    {{-- Filtres --}}
    <div class="flex items-center gap-3">
        <select wire:model.live="logName"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Tous les modules</option>
            @foreach ($logNames as $ln)
                <option value="{{ $ln }}">{{ ucfirst($ln) }}</option>
            @endforeach
        </select>

        <select wire:model.live="event"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Toutes les actions</option>
            @foreach ($events as $ev)
                <option value="{{ $ev }}">{{ ucfirst($ev) }}</option>
            @endforeach
        </select>

        <span class="text-xs text-ink-muted ml-auto">
            {{ $activities->total() }} entrée{{ $activities->total() > 1 ? 's' : '' }}
        </span>
    </div>

    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-44">Date</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-48">Utilisateur</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-32">Module</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-32">Action</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Cible</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Changements</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($activities as $a)
                        @php
                            $eventVariant = match ($a->event) {
                                'created' => 'ok',
                                'updated' => 'amber',
                                'deleted' => 'error',
                                default => 'pending',
                            };
                        @endphp
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors align-top" wire:key="act-{{ $a->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary whitespace-nowrap">
                                {{ $a->created_at->format('d/m/Y H:i:s') }}
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($a->causer)
                                    <div class="font-medium text-ink-primary">{{ $a->causer->name }}</div>
                                    <div class="text-[11px] text-ink-muted">{{ $a->causer->email }}</div>
                                @else
                                    <span class="text-ink-muted italic">système</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge variant="amber">{{ $a->log_name ?? '—' }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                <x-badge :variant="$eventVariant">{{ $a->event ?? '—' }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($a->subject_type)
                                    <span class="font-mono text-xs text-ink-secondary">
                                        {{ class_basename($a->subject_type) }}#{{ $a->subject_id }}
                                    </span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @php
                                    $old = data_get($a->properties, 'old', []);
                                    $new = data_get($a->properties, 'attributes', []);
                                    $keys = array_unique(array_merge(array_keys((array) $old), array_keys((array) $new)));
                                @endphp
                                @if (!empty($keys))
                                    <ul class="space-y-0.5 text-[11px] font-mono">
                                        @foreach ($keys as $k)
                                            <li>
                                                <span class="text-ink-muted">{{ $k }}:</span>
                                                <span class="text-danger line-through">{{ data_get($old, $k) ?? '∅' }}</span>
                                                <span class="text-ink-muted">→</span>
                                                <span class="text-ok font-medium">{{ data_get($new, $k) ?? '∅' }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucune activité enregistrée.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($activities->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $activities->firstItem() }}–{{ $activities->lastItem() }} de {{ $activities->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($activities->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</button>
                    @endif
                    @if ($activities->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</button>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</div>
