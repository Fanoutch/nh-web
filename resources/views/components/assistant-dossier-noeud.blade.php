{{-- Un nœud de l'arborescence de l'onglet Documents (et ses enfants, par récursion). --}}
@props(['dossier', 'enfants', 'compteurs', 'dossierId', 'idsOuverts', 'profondeur' => 1])

@php
    $sousDossiers = $enfants->get($dossier->id, collect());
    $compte = $compteurs[$dossier->id] ?? ['dossiers' => 0, 'documents' => 0];
    $actif = $dossierId === $dossier->id;
    $ouvertParDefaut = in_array($dossier->id, $idsOuverts, true);
@endphp

<div x-data="{ ouvert: {{ $ouvertParDefaut ? 'true' : 'false' }} }">
    <button type="button" wire:click="ouvrirDossier({{ $dossier->id }})"
            style="padding-left: {{ 8 + $profondeur * 12 }}px"
            x-on:dragover.prevent="if (glisseType) $el.classList.add('bg-accent-soft')"
            x-on:dragleave="$el.classList.remove('bg-accent-soft')"
            x-on:drop.prevent="$el.classList.remove('bg-accent-soft'); if (glisseType) { $wire.deplacer(glisseType, glisseId, {{ $dossier->id }}); glisseType = null; glisseId = null; }"
            @class([
                'w-full flex items-center gap-1.5 rounded-md py-1.5 pr-2 text-[13px] text-left truncate',
                'bg-accent-soft-strong font-medium text-ink-primary' => $actif,
                'text-ink-secondary hover:bg-app-bg' => ! $actif,
            ])>
        @if ($sousDossiers->isNotEmpty())
            <span x-on:click.stop="ouvert = ! ouvert" class="w-3 shrink-0 text-[10px] text-ink-muted" x-text="ouvert ? '▾' : '▸'"></span>
        @else
            <span class="w-3 shrink-0"></span>
        @endif
        <span class="truncate">📁 {{ $dossier->nom }}</span>
        <span class="ml-auto shrink-0 font-mono text-[10px] text-ink-muted">{{ $compte['documents'] }}</span>
    </button>

    @if ($sousDossiers->isNotEmpty())
        <div x-show="ouvert" x-cloak>
            @foreach ($sousDossiers as $enfant)
                <x-assistant-dossier-noeud :dossier="$enfant" :enfants="$enfants" :compteurs="$compteurs"
                    :dossier-id="$dossierId" :ids-ouverts="$idsOuverts" :profondeur="$profondeur + 1" />
            @endforeach
        </div>
    @endif
</div>
