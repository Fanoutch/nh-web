<section>
    <header class="mb-3 pb-3 border-b border-danger-border">
        <h2 class="text-sm font-semibold text-danger">Zone de danger</h2>
    </header>

    <p class="text-[13px] text-ink-secondary leading-relaxed mb-4">
        La suppression du compte est définitive. Toutes vos données seront effacées. Cette action ne peut pas être annulée.
    </p>

    <x-danger-button x-data="" x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')">
        Supprimer mon compte
    </x-danger-button>

    <x-modal name="confirm-user-deletion" :show="$errors->userDeletion->isNotEmpty()" focusable>
        <form method="post" action="{{ route('profile.destroy') }}" class="p-6">
            @csrf
            @method('delete')

            <h2 class="text-[15px] font-semibold text-ink-primary">
                Confirmer la suppression du compte
            </h2>
            <p class="text-[13px] text-ink-secondary mt-2 leading-relaxed">
                Toutes vos données seront effacées définitivement. Entrez votre mot de passe pour confirmer.
            </p>

            <div class="mt-5">
                <x-input-label for="password" value="Mot de passe" class="sr-only" />
                <x-text-input id="password" name="password" type="password"
                              class="w-3/4" placeholder="Mot de passe" />
                <x-input-error :messages="$errors->userDeletion->get('password')" class="mt-2" />
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <x-secondary-button x-on:click="$dispatch('close')">Annuler</x-secondary-button>
                <x-danger-button>Supprimer définitivement</x-danger-button>
            </div>
        </form>
    </x-modal>
</section>
