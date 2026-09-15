@props(['secteur', 'actif'])

@php
    // Un onglet par outil du secteur. Ajouter ici les futurs onglets.
    $onglets = [
        'disponibilites' => ['label' => 'Disponibilités', 'route' => 'secteurs.disponibilites'],
        'assistant' => ['label' => 'Assistant IA', 'route' => 'secteurs.assistant'],
    ];
@endphp

<div class="mb-6">
    <x-section-label class="mb-1">Secteurs</x-section-label>
    <h1 class="text-[22px] font-semibold text-ink-primary mb-4">{{ $secteur->nom }}</h1>

    <nav class="flex gap-1 border-b border-app-border">
        @foreach ($onglets as $cle => $onglet)
            <a href="{{ route($onglet['route'], $secteur) }}"
               @class([
                   'px-4 py-2 -mb-px text-[13px] font-medium border-b-2 transition',
                   'border-accent text-accent' => $actif === $cle,
                   'border-transparent text-ink-muted hover:text-ink-primary' => $actif !== $cle,
               ])>
                {{ $onglet['label'] }}
            </a>
        @endforeach
    </nav>
</div>
