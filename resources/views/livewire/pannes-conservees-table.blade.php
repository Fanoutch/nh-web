<div class="space-y-4">
    {{-- Top bar : search + count badges --}}
    <div class="flex items-center justify-between gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (description, code, système…)"
               class="w-96 bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />
        <div class="flex items-center gap-2">
            @php
                $allPannes = $flight->technicalEvents()->where('status', 'conservee')->get();
                $countPending   = $allPannes->where('validation_status', 'pending')->count();
                $countValidated = $allPannes->where('validation_status', 'validated')->count();
                $countRejected  = $allPannes->where('validation_status', 'rejected')->count();
            @endphp
            <x-badge variant="pending">{{ $countPending }} pending</x-badge>
            <x-badge variant="ok">{{ $countValidated }} validées</x-badge>
            <x-badge variant="error">{{ $countRejected }} rejetées</x-badge>
        </div>
    </div>

    {{-- Card list pannes --}}
    <div class="space-y-2">
        @forelse ($pannes as $p)
            @php
                $status = $p->validation_status;
                $statusBadgeVariant = match ($status) {
                    'validated' => 'ok',
                    'rejected'  => 'error',
                    default     => 'pending',
                };
                $statusLabel = match ($status) {
                    'validated' => 'Validée',
                    'rejected'  => 'Rejetée',
                    default     => 'Pending',
                };
                $teDesc = data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') ?? $p->technical_event_id;
                $sysDesc = data_get($p->details, 'SystemDescription') ?? '—';
                $typeDesc = data_get($p->details, 'TypeDescription') ?? '—';
                $failureCode = data_get($p->details, 'FailureCode') ?? '—';
                $isValid = $status === 'validated';
                $isReject = $status === 'rejected';
                $actionValid  = $isValid  ? 'pending' : 'validated';
                $actionReject = $isReject ? 'pending' : 'rejected';
            @endphp
            <x-panne-row :status="$status" wire:key="te-{{ $p->id }}">
                <div class="flex items-center gap-2 mb-1.5 flex-wrap">
                    <x-badge :variant="$statusBadgeVariant">{{ $statusLabel }}</x-badge>
                    @if ($p->pn_validation_status === 'confirmed')
                        <span class="inline-flex items-center gap-1 bg-ok-soft text-ok border border-ok-border text-[10px] px-2 py-0.5 rounded font-mono">
                            Confirmé en vol par {{ $p->pnValidator?->name }}
                        </span>
                    @elseif ($p->pn_validation_status === 'rejected')
                        <span class="inline-flex items-center gap-1 bg-danger-soft text-danger border border-danger-border text-[10px] px-2 py-0.5 rounded font-mono">
                            Rejeté en vol par {{ $p->pnValidator?->name }}
                        </span>
                    @endif
                    <span class="font-mono text-[10px] text-ink-muted">
                        {{ $sysDesc }} · Fault Code {{ $failureCode }} · ×{{ $p->nombre_occurrences }} occurrence{{ $p->nombre_occurrences > 1 ? 's' : '' }}
                    </span>
                </div>
                <button wire:click="openDetail({{ $p->id }})"
                        class="text-[13px] font-medium text-ink-primary hover:text-accent-pressed text-left transition-colors">
                    {{ $teDesc }}
                </button>
                <div class="text-[11px] text-ink-muted mt-1">
                    Système : {{ $sysDesc }} | Type : {{ $typeDesc }}
                </div>

                <x-slot:actions>
                    <div class="flex gap-1.5">
                        @if ($isValid)
                            <x-ok-button wire:click="setValidation({{ $p->id }}, '{{ $actionValid }}')">✓ Validée</x-ok-button>
                        @else
                            <x-secondary-button wire:click="setValidation({{ $p->id }}, '{{ $actionValid }}')">✓ Valider</x-secondary-button>
                        @endif
                        @if ($isReject)
                            <x-danger-button type="button" wire:click="setValidation({{ $p->id }}, '{{ $actionReject }}')">✕ Rejetée</x-danger-button>
                        @else
                            <x-secondary-button wire:click="setValidation({{ $p->id }}, '{{ $actionReject }}')">✕ Rejeter</x-secondary-button>
                        @endif
                    </div>
                    @if (auth()->user()?->isAdmin() && $p->validated_by)
                        <div class="text-[10px] text-ink-muted text-right leading-snug">
                            par {{ $p->validator?->name ?? 'inconnu' }}<br>
                            {{ $p->validated_at?->format('d/m/Y H:i') }}
                        </div>
                    @endif
                </x-slot:actions>
            </x-panne-row>
        @empty
            <x-card class="p-12 text-center text-ink-muted text-sm">
                Aucune panne conservée pour ce vol.
            </x-card>
        @endforelse
    </div>

    {{-- Form panne manquante (collapsible Alpine) --}}
    <x-card class="p-5" x-data="{ open: false }">
        <div class="flex items-center justify-between">
            <div class="font-semibold text-sm text-ink-primary">Signaler une panne manquante</div>
            <button @click="open = !open" type="button"
                    class="text-xs text-accent hover:text-accent-pressed font-medium transition-colors">
                <span x-show="!open">+ Ouvrir le formulaire</span>
                <span x-show="open" x-cloak>− Fermer</span>
            </button>
        </div>
        <div x-show="open" x-cloak class="mt-4 space-y-3">
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted mb-1.5">Failure Code *</label>
                <input type="text" wire:model="newFailureCode" placeholder="ex: 46-830"
                       class="w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />
                @error('newFailureCode')
                    <p class="text-[11px] text-danger mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted mb-1.5">
                    Description <span class="text-ink-muted lowercase font-normal normal-case">(facultatif)</span>
                </label>
                <textarea wire:model="newDescription" rows="3" placeholder="Description de la panne…"
                          class="w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition resize-y"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <x-secondary-button @click="open = false">Annuler</x-secondary-button>
                <x-primary-button wire:click="submitMissingPanne">Signaler la panne</x-primary-button>
            </div>
        </div>
    </x-card>

    {{-- Side detail panel --}}
    @if ($selected)
        <div wire:click.self="closeDetail"
             class="fixed inset-0 bg-ink-primary/50 backdrop-blur-sm z-40"
             x-data x-on:keydown.escape.window="$wire.closeDetail()"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-[400px] bg-app-card border-l border-app-border shadow-2xl z-50 p-6 overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <x-section-label class="mb-0.5">Détail panne</x-section-label>
                    <div class="text-sm font-medium text-ink-primary">{{ data_get($selected->details, 'TechnicalEventDescription') ?? $selected->technical_event_id }}</div>
                </div>
                <button wire:click="closeDetail" class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
            </div>
            <dl class="space-y-2.5 text-sm">
                @foreach ($selected->details as $k => $v)
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-muted">{{ $k }}</dt>
                        <dd class="text-ink-primary text-[13px] break-words mt-0.5">{{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    @endif

    {{-- Liste pannes manquantes signalées --}}
    @if ($missingPannes->isNotEmpty())
        <x-card class="p-5">
            <x-section-label class="mb-3">Pannes manquantes signalées ({{ $missingPannes->count() }})</x-section-label>
            <ul class="divide-y divide-app-border-soft">
                @foreach ($missingPannes as $m)
                    <li class="py-3 flex items-start justify-between gap-3" wire:key="missing-{{ $m->id }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs bg-accent-soft-strong text-warn px-2 py-0.5 rounded">{{ $m->failure_code }}</span>
                                @if ($m->description)
                                    <span class="text-[13px] text-ink-primary">{{ $m->description }}</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-ink-muted mt-1">
                                Signalée par {{ $m->reporter->email ?? 'inconnu' }}
                                · {{ $m->reported_at->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        @if ($m->reported_by === auth()->id())
                            <button wire:click="deleteMissing({{ $m->id }})"
                                    class="text-[11px] text-danger hover:underline transition-colors self-start">
                                Supprimer
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</div>
