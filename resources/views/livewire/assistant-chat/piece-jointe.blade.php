{{--
    Carte d'une pièce jointe : icône colorée selon le format, nom, état, et ✕ si retirable (pièce
    encore en préparation ou déjà prête, mais pas encore rattachée à un message envoyé).
    $piece est un tableau ['id', 'nom', 'format', 'statut'?, 'message'?] : le statut est absent pour
    une pièce déjà attachée à un message envoyé (toujours « prête » dans ce cas).
--}}
@php
    $retirable = $retirable ?? false;
    // 'supprimerPieceJointe' pour une pièce déjà déposée (fichier + ligne en base), 'retirerPiece'
    // pour un fichier encore refusé (mauvais format ou trop gros) qui n'a jamais été déposé.
    $action = $action ?? 'supprimerPieceJointe';
    $statut = $piece['statut'] ?? 'pret';
    $couleurIcone = match ($piece['format'] ?? '') {
        'pdf' => 'bg-[#c0391a]',
        'docx' => 'bg-[#2b5797]',
        default => 'bg-ink-secondary',
    };
@endphp
<div @class([
        'flex items-center gap-2 max-w-[250px] rounded-lg border px-2 py-1.5',
        'border-danger-border bg-danger-soft' => $statut === 'erreur',
        'border-app-border bg-app-card' => $statut !== 'erreur',
    ])>
    <span class="w-7 h-7 shrink-0 rounded-md grid place-items-center text-[9px] font-medium uppercase text-white {{ $couleurIcone }}">
        {{ $piece['format'] ?? '?' }}
    </span>
    <span class="min-w-0 flex flex-col">
        <span class="text-[12.5px] text-ink-primary truncate" title="{{ $piece['nom'] }}">{{ $piece['nom'] }}</span>
        <span @class([
                'text-[11px]',
                'text-ok' => $statut === 'pret',
                'text-danger' => $statut === 'erreur',
                'text-ink-muted' => ! in_array($statut, ['pret', 'erreur'], true),
            ])>
            @if ($statut === 'erreur')
                {{ $piece['message'] ?? 'Erreur.' }}
            @elseif ($statut === 'pret')
                ✓ prêt
            @else
                préparation…
            @endif
        </span>
    </span>
    @if ($retirable)
        <button type="button" wire:click="{{ $action }}({{ $piece['id'] }})"
                aria-label="Retirer {{ $piece['nom'] }}" title="Retirer"
                class="shrink-0 text-ink-muted hover:text-danger px-1 leading-none">✕</button>
    @endif
</div>
