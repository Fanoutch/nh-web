{{--
    Onglet « Assistant IA » : deux colonnes (historique personnel / conversation), sur toute la
    hauteur disponible. La donnée Alpine du conteneur gère uniquement des états d'affichage
    éphémères (menu ⋯, défilement, copie) — aucune donnée sensible n'y transite jamais.
--}}
<div class="grid grid-cols-1 md:grid-cols-[260px_1fr] gap-4 h-[calc(100vh-220px)] min-h-[560px]"
     x-data="{
         menuOuvert: null,
         copie: null,
         collePresDuBas: true,
         copier(texte, id) {
             const succes = () => { this.copie = id; setTimeout(() => { if (this.copie === id) this.copie = null; }, 1500); };
             const repli = () => {
                 const zone = document.createElement('textarea');
                 zone.value = texte; zone.style.position = 'fixed'; zone.style.opacity = '0';
                 document.body.appendChild(zone); zone.select();
                 try { document.execCommand('copy'); } catch (e) {}
                 zone.remove(); succes();
             };
             if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(texte).then(succes).catch(repli); }
             else { repli(); }
         },
     }">

    {{-- Historique personnel des conversations, groupé par date --}}
    <x-card class="p-3 flex flex-col min-h-0">
        <x-section-label class="mb-2">Historique</x-section-label>

        <button type="button" wire:click="nouvelle"
                class="mb-3 flex items-center gap-2 rounded-lg border border-app-border bg-app-elevated px-3 py-2 text-[13px] font-medium text-ink-primary hover:border-accent transition">
            <span class="w-5 h-5 shrink-0 rounded bg-accent text-sidebar-bg grid place-items-center text-xs font-semibold leading-none">＋</span>
            Nouvelle conversation
        </button>

        <div class="flex-1 flex flex-col gap-0.5 overflow-y-auto" x-on:click.outside="menuOuvert = null">
            @forelse ($conversationsGroupees as $groupe => $items)
                <div class="px-2 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-[0.08em] text-ink-muted first:pt-0">{{ $groupe }}</div>
                @foreach ($items as $c)
                    <div wire:key="conv-{{ $c->id }}" class="relative">
                        @if ($renommageId === $c->id)
                            {{-- Renommage en ligne : propriété locale « titreRenomme », validée par renommer() --}}
                            <input type="text" wire:model="titreRenomme" maxlength="100" autofocus
                                   wire:keydown.enter="renommer({{ $c->id }}, $event.target.value)"
                                   wire:keydown.escape="annulerRenommage"
                                   x-on:blur="$wire.annulerRenommage()"
                                   aria-label="Nouveau titre de la conversation"
                                   class="w-full bg-app-elevated border border-accent rounded px-2 py-1.5 text-[13px] text-ink-primary focus:outline-none" />
                        @else
                            <div @class([
                                    'group/conv flex items-center gap-1 rounded px-1',
                                    'bg-accent-soft-strong' => $c->id === $conversationId,
                                    'hover:bg-app-bg' => $c->id !== $conversationId,
                                ])>
                                <button type="button" wire:click="ouvrir({{ $c->id }})" title="{{ $c->titre }}"
                                        @class([
                                            'flex-1 min-w-0 text-left truncate px-1.5 py-1.5 text-[13px]',
                                            'font-medium text-ink-primary' => $c->id === $conversationId,
                                            'text-ink-secondary' => $c->id !== $conversationId,
                                        ])>{{ $c->titre }}</button>
                                <button type="button" aria-label="Actions"
                                        x-on:click.stop="menuOuvert = menuOuvert === {{ $c->id }} ? null : {{ $c->id }}"
                                        x-bind:class="menuOuvert === {{ $c->id }} ? 'opacity-100' : 'opacity-0 group-hover/conv:opacity-100'"
                                        class="shrink-0 px-1.5 py-1 rounded text-ink-secondary hover:bg-app-border transition-opacity">⋯</button>
                            </div>

                            <div x-show="menuOuvert === {{ $c->id }}" x-cloak x-on:click.stop
                                 class="absolute right-0 top-8 z-10 min-w-[150px] bg-app-elevated border border-app-border rounded-lg shadow-lg p-1">
                                <button type="button" wire:click="commencerRenommage({{ $c->id }})" x-on:click="menuOuvert = null"
                                        class="w-full text-left px-2.5 py-1.5 rounded text-[13px] text-ink-primary hover:bg-app-bg">Renommer</button>
                                <button type="button" wire:click="supprimerConversation({{ $c->id }})"
                                        wire:confirm="Supprimer la conversation « {{ $c->titre }} » ?"
                                        x-on:click="menuOuvert = null"
                                        class="w-full text-left px-2.5 py-1.5 rounded text-[13px] text-danger hover:bg-danger-soft">Supprimer</button>
                            </div>
                        @endif
                    </div>
                @endforeach
            @empty
                <p class="text-[12px] text-ink-muted italic px-2 py-1">Aucune conversation.</p>
            @endforelse
        </div>
    </x-card>

    {{-- Conversation --}}
    <x-card class="flex flex-col min-h-0 relative overflow-hidden">
        {{-- Fil des messages : le défilement automatique s'arrête si l'utilisateur remonte de plus de 80 px --}}
        <div class="flex-1 overflow-y-auto px-4 py-5" x-ref="fil"
             x-on:scroll="collePresDuBas = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) <= 80"
             x-init="
                 $el.scrollTop = $el.scrollHeight;
                 new MutationObserver(() => { if (collePresDuBas) $el.scrollTop = $el.scrollHeight; }).observe($el, { childList: true, subtree: true, characterData: true });
             ">
            <div class="max-w-[760px] mx-auto min-h-full flex flex-col justify-end gap-4">
                @if ($messages->isEmpty())
                    @include('livewire.assistant-chat.accueil')
                @endif

                @foreach ($messages as $m)
                    @if ($m->role === 'utilisateur')
                        @include('livewire.assistant-chat.message-utilisateur', ['m' => $m])
                    @else
                        @include('livewire.assistant-chat.message-assistant', ['m' => $m])
                    @endif
                @endforeach

                {{-- Pendant l'envoi : question et réponse diffusées au fil de l'eau (wire:stream) --}}
                <div wire:loading.flex wire:target="envoyer" class="flex-col gap-4">
                    <div class="self-end max-w-[80%] bg-app-bg border border-app-border-soft text-ink-primary rounded-2xl rounded-br-sm px-3.5 py-2.5 text-sm whitespace-pre-wrap"
                         wire:stream="question-en-cours"></div>
                    <div class="self-start max-w-[85%] rounded-2xl rounded-bl-sm px-3.5 py-2.5 border border-app-border bg-app-bg text-sm text-ink-primary leading-relaxed"
                         wire:stream="reponse-en-cours"><span class="text-ink-muted animate-pulse">L'assistant réfléchit…</span></div>
                </div>
            </div>
        </div>

        @include('livewire.assistant-chat.saisie')
    </x-card>
</div>
