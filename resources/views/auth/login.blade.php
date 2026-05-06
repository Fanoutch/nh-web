<x-guest-layout>
    <div class="w-[380px] max-w-[95vw] p-10 bg-app-card border border-app-border rounded-xl shadow-[0_24px_64px_rgba(26,34,53,0.15)]">
        {{-- Logo + branding --}}
        <div class="text-center mb-8">
            <img src="{{ asset('images/logo-flottille-31f.png') }}" alt="Flottille 31F"
                 class="mx-auto h-16 w-auto mb-4" />
            <div class="text-xl font-semibold text-ink-primary">NH Project</div>
            <div class="text-xs text-ink-muted mt-1">Fleet Maintenance System</div>
        </div>

        {{-- Status messages --}}
        <x-auth-session-status class="mb-4" :status="session('status')" />

        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-3.5">
            @csrf

            {{-- Email --}}
            <div>
                <x-input-label for="email" :value="__('Email')" class="mb-1.5" />
                <x-text-input id="email" type="email" name="email" :value="old('email')"
                              required autofocus autocomplete="username"
                              placeholder="vous@organisation.mil" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            {{-- Password --}}
            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <x-input-label for="password" :value="__('Mot de passe')" />
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}"
                           class="text-[11px] text-accent hover:text-accent-pressed transition-colors">
                            {{ __('Mot de passe oublié ?') }}
                        </a>
                    @endif
                </div>
                <x-text-input id="password" type="password" name="password"
                              required autocomplete="current-password"
                              placeholder="••••••••" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            {{-- Submit (full width) --}}
            <x-primary-button class="!w-full !justify-center !py-2.5 !text-[13px] mt-3">
                {{ __('Se connecter') }}
            </x-primary-button>
        </form>
    </div>
</x-guest-layout>
