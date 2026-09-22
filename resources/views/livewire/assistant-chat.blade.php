<div class="grid grid-cols-1 md:grid-cols-[240px_1fr] gap-4 min-h-[520px]">
    {{-- Historique personnel --}}
    <x-card class="p-3 flex flex-col">
        <div class="flex items-center justify-between mb-2">
            <x-section-label>Historique</x-section-label>
            <button type="button" wire:click="nouvelle" title="Nouvelle conversation"
                    class="w-6 h-6 rounded border border-app-border text-ink-secondary hover:border-accent hover:text-accent leading-none">＋</button>
        </div>
        <div class="flex flex-col gap-0.5 overflow-y-auto">
            @forelse ($conversations as $c)
                <div wire:key="conv-{{ $c->id }}"
                     @class(['group flex items-center gap-1 rounded px-2 py-1.5 text-[12px]',
                             'bg-accent-soft text-accent' => $c->id === $conversationId,
                             'text-ink-secondary hover:bg-app-bg' => $c->id !== $conversationId])>
                    <button type="button" wire:click="ouvrir({{ $c->id }})" class="flex-1 text-left truncate">{{ $c->titre }}</button>
                    <button type="button" wire:click="supprimerConversation({{ $c->id }})"
                            wire:confirm="Supprimer la conversation « {{ $c->titre }} » ?"
                            class="text-ink-muted hover:text-danger leading-none" title="Supprimer">✕</button>
                </div>
            @empty
                <p class="text-[12px] text-ink-muted italic px-2 py-1">Aucune conversation.</p>
            @endforelse
        </div>
    </x-card>

    {{-- Conversation --}}
    <x-card class="p-4 flex flex-col">
        <div class="flex-1 flex flex-col gap-3 overflow-y-auto">
            @if ($messages->isEmpty())
                <div wire:loading.remove wire:target="envoyer" class="m-auto text-center py-12">
                    <p class="text-[17px] font-medium text-ink-primary mb-1">Bonjour {{ $prenom }}</p>
                    <p class="text-sm text-ink-muted">Posez une question sur les documents du secteur {{ $secteur->nom }}.</p>
                    @if ($aucunDocumentPret)
                        <p class="text-xs text-warn mt-3">Aucun document n'est encore disponible dans ce secteur : un chef doit en déposer dans l'onglet Documents.</p>
                    @endif
                </div>
            @endif

            @foreach ($messages as $m)
                @if ($m->role === 'utilisateur')
                    <div class="self-end max-w-[80%] bg-accent-soft text-ink-primary rounded-lg px-3 py-2 text-sm" wire:key="msg-{{ $m->id }}">{{ $m->contenu }}</div>
                @else
                    <div class="self-start max-w-[85%] text-sm" wire:key="msg-{{ $m->id }}">
                        <div @class(['rounded-lg px-3 py-2 border',
                                     'border-app-border bg-app-bg text-ink-primary' => $m->statut === 'complet',
                                     'border-danger-border bg-danger-soft text-danger' => $m->statut !== 'complet'])>{!! nl2br(e($m->contenu)) !!}</div>
                        @if ($m->statut === 'interrompu')
                            <p class="text-[11px] text-danger mt-1">Réponse interrompue.</p>
                        @endif
                        @if ($m->statut !== 'complet')
                            @if ($m->signale_at)
                                <span class="text-[11px] text-ink-muted">Signalé ✓</span>
                            @else
                                <button type="button" wire:click="signaler({{ $m->id }})" class="text-[11px] text-accent hover:text-accent-pressed">Signaler</button>
                            @endif
                        @endif
                        @if (! empty($m->sources))
                            <p class="text-[11px] text-ink-muted mt-1">
                                Sources :
                                @foreach ($m->sources as $s)
                                    @php $libelle = $s['nom'].($s['page'] ? ' p.'.$s['page'] : ''); @endphp
                                    @if (in_array($s['document_id'], $documentsExistants, true))
                                        <a href="{{ route('secteurs.documents.consulter', [$secteur, $s['document_id']]) }}{{ $s['page'] ? '#page='.$s['page'] : '' }}"
                                           target="_blank" class="text-accent hover:text-accent-pressed">{{ $libelle }}</a>
                                    @else
                                        <span>{{ $s['nom'] }} (document supprimé)</span>
                                    @endif
                                    @if (! $loop->last) · @endif
                                @endforeach
                            </p>
                        @endif
                    </div>
                @endif
            @endforeach

            {{-- Pendant l'envoi : question et réponse diffusées au fil de l'eau --}}
            <div wire:loading.flex wire:target="envoyer" class="flex-col gap-3">
                <div class="self-end max-w-[80%] bg-accent-soft text-ink-primary rounded-lg px-3 py-2 text-sm" wire:stream="question-en-cours"></div>
                <div class="self-start max-w-[85%] rounded-lg px-3 py-2 border border-app-border bg-app-bg text-sm text-ink-primary"
                     wire:stream="reponse-en-cours"><span class="text-ink-muted animate-pulse">L'assistant réfléchit…</span></div>
            </div>
        </div>

        {{-- Saisie --}}
        <form wire:submit="envoyer" class="mt-4">
            <div class="flex gap-2">
                <textarea wire:model="question" rows="2" placeholder="Posez votre question…"
                          x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                          class="flex-1 bg-app-elevated border border-app-border rounded-md px-3 py-2 text-sm text-ink-primary focus:outline-none focus:border-accent"></textarea>
                <x-primary-button wire:loading.attr="disabled" wire:target="envoyer">➤</x-primary-button>
            </div>
            @error('question')
                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-[11px] text-ink-muted">Secteur : {{ $secteur->nom }}</p>
        </form>
    </x-card>
</div>
