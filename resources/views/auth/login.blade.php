<x-guest-layout>
    <div class="w-[380px] max-w-[95vw] p-10 bg-app-card border border-app-border rounded-xl shadow-[0_24px_64px_rgba(26,34,53,0.15)]">
        {{-- Logo + branding --}}
        <div class="text-center mb-8">
            <div class="w-11 h-11 mx-auto bg-accent rounded-lg flex items-center justify-center mb-4">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L21 7v5l-3 1.5L12 11 6 8.5 3 12V7L12 2z" fill="#0e1117"/>
                    <path d="M6 12v7l6 1.5 6-1.5V12" stroke="#0e1117" stroke-width="1.8" fill="none"/>
                </svg>
            </div>
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
