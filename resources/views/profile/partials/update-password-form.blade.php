<section>
    <header class="mb-5 pb-3 border-b border-app-border-soft">
        <h2 class="text-sm font-semibold text-ink-primary">Changer le mot de passe</h2>
    </header>

    <form method="post" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        @method('put')

        <div>
            <x-input-label for="update_password_current_password" :value="__('Mot de passe actuel')" class="mb-1.5" />
            <x-text-input id="update_password_current_password" name="current_password" type="password"
                          autocomplete="current-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->updatePassword->get('current_password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password" :value="__('Nouveau mot de passe')" class="mb-1.5" />
            <x-text-input id="update_password_password" name="password" type="password"
                          autocomplete="new-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->updatePassword->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="update_password_password_confirmation" :value="__('Confirmer le nouveau mot de passe')" class="mb-1.5" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password"
                          autocomplete="new-password" placeholder="••••••••" />
            <x-input-error :messages="$errors->updatePassword->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center gap-3 justify-end">
            @if (session('status') === 'password-updated')
                <p x-data="{ show: true }" x-show="show" x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-xs text-ok">Enregistré.</p>
            @endif
            <x-primary-button>Modifier le mot de passe</x-primary-button>
        </div>
    </form>
</section>
