<section>
    <header class="mb-5 pb-3 border-b border-app-border-soft">
        <h2 class="text-sm font-semibold text-ink-primary">Informations du compte</h2>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

    <form method="post" action="{{ route('profile.update') }}" class="space-y-4">
        @csrf
        @method('patch')

        <div class="grid grid-cols-2 gap-4">
            <div>
                <x-input-label for="name" :value="__('Nom')" class="mb-1.5" />
                <x-text-input id="name" name="name" type="text" :value="old('name', $user->name)"
                              required autofocus autocomplete="name" />
                <x-input-error class="mt-2" :messages="$errors->get('name')" />
            </div>
            <div>
                <x-input-label for="email" :value="__('Email')" class="mb-1.5" />
                <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)"
                              required autocomplete="username" />
                <x-input-error class="mt-2" :messages="$errors->get('email')" />
            </div>
        </div>

        @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
            <div class="text-xs text-ink-muted">
                {{ __('Votre adresse email n\'est pas vérifiée.') }}
                <button form="send-verification" class="underline text-accent hover:text-accent-pressed">
                    {{ __('Renvoyer l\'email de vérification') }}
                </button>
            </div>
            @if (session('status') === 'verification-link-sent')
                <p class="text-xs text-ok">Un nouveau lien de vérification a été envoyé.</p>
            @endif
        @endif

        <div class="flex items-center gap-3 justify-end">
            @if (session('status') === 'profile-updated')
                <p x-data="{ show: true }" x-show="show" x-transition
                   x-init="setTimeout(() => show = false, 2000)"
                   class="text-xs text-ok">Enregistré.</p>
            @endif
            <x-primary-button>Enregistrer</x-primary-button>
        </div>
    </form>
</section>
