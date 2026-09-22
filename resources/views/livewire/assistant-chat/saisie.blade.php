{{--
    Zone de saisie, collée en bas de la conversation. La question est vidée côté serveur avant le
    premier fragment du flux (voir AssistantChat::envoyer) ; on la vide aussi côté client dès
    l'envoi pour ne pas attendre la fin du flux, le navigateur étant servi en HTTP simple.
    Un bouton 📎 (pièces jointes) viendra se loger dans la ligne de saisie dans une tâche suivante.
--}}
<div class="border-t border-app-border-soft px-4 py-3">
    <form wire:submit="envoyer" x-on:submit="$refs.question.value = ''; $refs.question.style.height = 'auto'" class="max-w-[760px] mx-auto">
        <div class="flex items-end gap-2 bg-app-bg border border-app-border rounded-xl px-3 py-2 focus-within:border-accent transition">
            <textarea x-ref="question" wire:model="question" rows="1" placeholder="Posez votre question…" aria-label="Votre question"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                      class="flex-1 resize-none bg-transparent border-0 focus:outline-none focus:ring-0 text-sm text-ink-primary py-1 max-h-[200px]"></textarea>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="envoyer" class="!rounded-lg !px-3 !py-2 shrink-0">➤</x-primary-button>
        </div>
        @error('question')
            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
        @enderror
        <p class="mt-1.5 text-[11px] text-ink-muted text-center">Secteur : {{ $secteur->nom }}</p>
    </form>
</div>
