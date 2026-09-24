{{-- Écran d'accueil affiché quand la conversation est vide --}}
<div wire:loading.remove wire:target="envoyer" class="m-auto text-center py-12">
    <p class="text-[19px] font-medium text-ink-primary mb-1">Bonjour {{ $prenom }}</p>
    <p class="text-sm text-ink-muted">Posez une question sur les documents du secteur {{ $secteur->nom }}.</p>

    @if ($aucunDocumentPret)
        <p class="text-xs text-warn mt-3">Aucun document n'est encore disponible dans ce secteur : un chef doit en déposer dans l'onglet Documents.</p>
    @else
        <div class="flex flex-wrap justify-center gap-2 mt-4 max-w-[520px] mx-auto">
            @foreach ([
                'Quels documents sont disponibles dans ce secteur ?',
                'Quelle est la procédure d’entretien la plus récente ?',
                'Où puis-je trouver les consignes de sécurité ?',
            ] as $suggestion)
                <button type="button" wire:click="$set('question', @js($suggestion))"
                        class="bg-app-card border border-app-border rounded-full px-3 py-1.5 text-[12px] text-ink-secondary hover:border-accent hover:text-ink-primary transition">
                    {{ $suggestion }}
                </button>
            @endforeach
        </div>
    @endif
</div>
