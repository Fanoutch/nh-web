{{--
    Zone de saisie, collée en bas de la conversation. La question est vidée côté serveur avant le
    premier fragment du flux (voir AssistantChat::envoyer) ; on la vide aussi côté client dès
    l'envoi pour ne pas attendre la fin du flux, le navigateur étant servi en HTTP simple.

    Pièces jointes : bouton 📎 ou glisser-déposer sur la conversation (voile de dépôt géré dans
    assistant-chat.blade.php, sur la carte englobante). Le fichier choisi est confié à Livewire
    (wire:model="pieces"), qui déclenche AssistantChat::updatedPieces() côté serveur (validation,
    dépôt sur le disque, indexation en tâche de fond). Le bouton Envoyer reste indisponible tant
    qu'une pièce jointe de la conversation est encore en préparation.

    Pendant l'écriture de la réponse, le bouton ➤ est remplacé par ■ Arrêter : le worker PHP qui
    écrit la réponse est occupé, donc l'arrêt part d'une requête HTTP séparée (fetch, jeton CSRF)
    vers la route secteurs.assistant.arreter, qui se contente de poser un drapeau lu par
    AssistantChat::envoyer(). Le texte déjà reçu est conservé (voir AssistantMessage::INTERROMPU).
--}}
<div class="border-t border-app-border-soft px-4 py-3">
    <form wire:submit="envoyer"
          x-on:submit="$refs.question.value = ''; $refs.question.style.height = 'auto'; arretEnCours = false"
          class="max-w-[760px] mx-auto">

        <input type="file" x-ref="pieceJointe" wire:model="pieces" multiple hidden
               accept=".pdf,.docx,.txt" aria-label="Joindre un document">

        @if ($piecesEnAttente->isNotEmpty())
            <div class="flex flex-wrap gap-2 mb-2">
                @foreach ($piecesEnAttente as $p)
                    <div wire:key="pj-attente-{{ $p->id }}">
                        @include('livewire.assistant-chat.piece-jointe', [
                            'piece' => ['id' => $p->id, 'nom' => $p->nom, 'format' => $p->format, 'statut' => $p->statut, 'message' => $p->message],
                            'retirable' => true,
                        ])
                    </div>
                @endforeach
            </div>
        @endif
        @error('pieces.*')
            <p class="mb-2 text-xs text-danger">{{ $message }}</p>
        @enderror

        <div class="flex items-end gap-2 bg-app-bg border border-app-border rounded-xl px-3 py-2 focus-within:border-accent transition">
            <button type="button" x-on:click="$refs.pieceJointe.click()"
                    class="w-9 h-9 shrink-0 rounded-lg text-ink-secondary hover:bg-app-elevated hover:text-ink-primary transition flex items-center justify-center text-base"
                    aria-label="Joindre un document"
                    title="Joindre un document (PDF, Word, TXT — 20 Mo, 5 par conversation)">📎</button>
            <textarea x-ref="question" wire:model="question" rows="1" placeholder="Posez votre question…" aria-label="Votre question"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                      class="flex-1 resize-none bg-transparent border-0 focus:outline-none focus:ring-0 text-sm text-ink-primary py-1 max-h-[200px]"></textarea>
            {{-- :disabled (attribut dynamique Blade) : ComponentAttributeBag omet l'attribut si faux, l'affiche nu si vrai. --}}
            <x-primary-button type="submit" wire:loading.remove wire:target="envoyer" :disabled="$piecesEnPreparation" class="!rounded-lg !px-3 !py-2 shrink-0">➤</x-primary-button>
            <button type="button" wire:loading.flex wire:target="envoyer"
                    x-bind:disabled="arretEnCours"
                    x-on:click="
                        if (arretEnCours) return;
                        // Conversation tout juste créée : l'identifiant n'est pas encore arrivé par le
                        // flux (conversation-id-en-cours). On n'envoie rien plutôt qu'un identifiant vide
                        // (le serveur répondrait 422) — le bouton reste cliquable normalement, prêt à
                        // réessayer dès que l'identifiant sera connu, quelques instants plus tard.
                        const idConversation = $refs.conversationIdEnCours.textContent.trim();
                        if (idConversation === '') return;
                        arretEnCours = true;
                        fetch('{{ route('secteurs.assistant.arreter', $secteur) }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ conversation_id: idConversation }),
                        }).then((r) => { if (! r.ok) arretEnCours = false; }).catch(() => { arretEnCours = false; });
                    "
                    class="items-center gap-1.5 !rounded-lg !px-3 !py-2 shrink-0 border border-app-border bg-app-elevated text-ink-primary text-sm font-medium hover:border-accent transition disabled:opacity-60 disabled:cursor-not-allowed">
                <span aria-hidden="true">■</span>
                <span x-text="arretEnCours ? 'Arrêt en cours…' : 'Arrêter'"></span>
            </button>
        </div>
        @error('question')
            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
        @enderror
        <p class="mt-1.5 text-[11px] text-ink-muted text-center">Secteur : {{ $secteur->nom }} · L'assistant répond à partir des documents du secteur et de vos pièces jointes</p>
    </form>
</div>
