<x-app-layout>
    <div class="max-w-[680px]">
        <div class="mb-6">
            <x-section-label class="mb-1">Compte</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Profil</h1>
        </div>

        <div class="space-y-4">
            <x-card class="p-6">
                @include('profile.partials.update-profile-information-form')
            </x-card>

            <x-card class="p-6">
                @include('profile.partials.update-password-form')
            </x-card>

            <x-card class="p-6 border-danger-border">
                @include('profile.partials.delete-user-form')
            </x-card>
        </div>
    </div>
</x-app-layout>
