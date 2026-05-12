<x-app-layout>
    {{-- Header --}}
    <div class="flex items-end justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Flotte</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Machines</h1>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs font-mono text-ink-muted">{{ $machines->count() }} hélicoptère(s)</span>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M6 1v7M3 4l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    <path d="M1 9v1.5a.5.5 0 00.5.5h9a.5.5 0 00.5-.5V9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                </svg>
                Uploader XML
            </a>
        </div>
    </div>

    {{-- Empty state --}}
    @if ($machines->isEmpty())
        <x-card class="p-12 text-center">
            <p class="text-ink-muted mb-4">Aucune machine en base.</p>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover transition">
                + Uploader un XML
            </a>
        </x-card>
    @else
        {{-- Cards machines --}}
        <div class="flex flex-col gap-3">
            @foreach ($machines as $m)
                @php
                    $erreursVariant = $m->erreurs_count > 0 ? 'danger' : 'secondary';
                @endphp
                <x-card>
                    {{-- Header --}}
                    <div class="px-5 py-3.5 border-b border-app-border-soft flex items-center gap-5">
                        {{-- HcId block --}}
                        <a href="{{ route('machines.show', $m->hc_id) }}"
                           class="w-[200px] shrink-0 group">
                            <div class="font-mono text-base font-medium text-accent group-hover:text-accent-pressed transition-colors">
                                {{ $m->hc_id }}
                            </div>
                            <div class="text-[11px] text-ink-muted mt-0.5">
                                {{ $m->vols_count + $m->non_vols_count + $m->erreurs_count }} enregistrement(s)
                            </div>
                        </a>

                        {{-- Compteurs avec dividers verticaux --}}
                        <div class="flex items-center gap-4">
                            <x-counter-pill :value="$m->vols_count" label="Vols" />
                            <div class="w-px h-8 bg-app-border-soft"></div>
                            <x-counter-pill :value="$m->non_vols_count" label="Non-Vols" variant="secondary" />
                            @if (auth()->user()?->isSuperAdmin())
                                <div class="w-px h-8 bg-app-border-soft"></div>
                                <x-counter-pill :value="$m->erreurs_count" label="Erreurs" :variant="$erreursVariant" />
                            @endif
                        </div>

                        <div class="flex-1"></div>

                        <a href="{{ route('machines.show', $m->hc_id) }}"
                           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
                            Voir détail →
                        </a>
                    </div>

                    {{-- Widgets row --}}
                    <div class="grid grid-cols-2 divide-x divide-app-border-soft">
                        @include('machines.partials.widget-recurrent', ['machine' => $m])
                        @include('machines.partials.widget-last-flight', ['machine' => $m])
                    </div>
                </x-card>
            @endforeach
        </div>
    @endif
</x-app-layout>
