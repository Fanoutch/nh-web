{{--
    Zone de saisie, collée en bas de la conversation. La question est vidée côté serveur avant le
    premier fragment du flux (voir AssistantChat::envoyer) ; on la vide aussi côté client dès
    l'envoi pour ne pas attendre la fin du flux, le navigateur étant servi en HTTP simple.
    Un bouton 📎 (pièces jointes) viendra se loger dans la ligne de saisie dans une tâche suivante.

    Pendant l'écriture de la réponse, le bouton ➤ est remplacé par ■ Arrêter : le worker PHP qui
    écrit la réponse est occupé, donc l'arrêt part d'une requête HTTP séparée (fetch, jeton CSRF)
    vers la route secteurs.assistant.arreter, qui se contente de poser un drapeau lu par
    AssistantChat::envoyer(). Le texte déjà reçu est conservé (voir AssistantMessage::INTERROMPU).
--}}
<div class="border-t border-app-border-soft px-4 py-3">
    <form wire:submit="envoyer"
          x-on:submit="$refs.question.value = ''; $refs.question.style.height = 'auto'; arretEnCours = false"
          class="max-w-[760px] mx-auto">
        <div class="flex items-end gap-2 bg-app-bg border border-app-border rounded-xl px-3 py-2 focus-within:border-accent transition">
            <textarea x-ref="question" wire:model="question" rows="1" placeholder="Posez votre question…" aria-label="Votre question"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                      class="flex-1 resize-none bg-transparent border-0 focus:outline-none focus:ring-0 text-sm text-ink-primary py-1 max-h-[200px]"></textarea>
            <x-primary-button type="submit" wire:loading.remove wire:target="envoyer" class="!rounded-lg !px-3 !py-2 shrink-0">➤</x-primary-button>
            <button type="button" wire:loading.flex wire:target="envoyer"
                    x-bind:disabled="arretEnCours"
                    x-on:click="
                        if (arretEnCours) return;
                        arretEnCours = true;
                        fetch('{{ route('secteurs.assistant.arreter', $secteur) }}', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ conversation_id: $refs.conversationIdEnCours.textContent }),
                        });
                    "
                    class="items-center gap-1.5 !rounded-lg !px-3 !py-2 shrink-0 border border-app-border bg-app-elevated text-ink-primary text-sm font-medium hover:border-accent transition disabled:opacity-60 disabled:cursor-not-allowed">
                <span aria-hidden="true">■</span>
                <span x-text="arretEnCours ? 'Arrêt en cours…' : 'Arrêter'"></span>
            </button>
        </div>
        @error('question')
            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
        @enderror
        <p class="mt-1.5 text-[11px] text-ink-muted text-center">Secteur : {{ $secteur->nom }}</p>
    </form>
</div>
