# Design — Étape 8 / Refonte `/profile`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — section `/profile`

## 1. Contexte

Page de profil utilisateur (Breeze). 3 sections empilées : informations du compte / changement de mot de passe / suppression de compte. Tous les composants utilisés (`<x-text-input>`, `<x-input-label>`, `<x-primary-button>`, `<x-danger-button>`, `<x-modal>`) ont déjà été re-stylés aux étapes précédentes — il reste à refondre le wrapper, les headers de section et les strings (français au lieu d'anglais).

## 2. Objectifs

- Refondre `resources/views/profile/edit.blade.php` : header section-label "Compte" + h1 "Profil" + 3 cards empilées (max-width 680px), la 3e (delete) avec border danger.
- Refondre les 3 partials `profile/partials/*.blade.php` :
  - Headers de section avec divider sous le titre.
  - Strings en français (Nom, Mot de passe, Enregistrer, etc.).
  - Boutons alignés à droite avec confirmation `Enregistré.` à gauche.
- Modal delete account : titre + description + input password + boutons Annuler/Supprimer définitivement.

**Non-objectifs** :
- Modifications du contrôleur `ProfileController` ou des `*Request` form requests.
- Modifications de la logique d'authentification ou de session.
- Changements de routes.
- Changements aux composants Blade `<x-text-input>` etc. (déjà stylés).

## 3. Architecture / fichiers touchés

```
nh-web/
└── resources/views/profile/
    ├── edit.blade.php                                 (refonte wrapper)
    └── partials/
        ├── update-profile-information-form.blade.php  (refonte — strings FR + layout)
        ├── update-password-form.blade.php             (refonte — strings FR + layout)
        └── delete-user-form.blade.php                 (refonte — danger zone + modal)
```

4 fichiers Blade. Aucun nouveau composant.

## 4. Vue `profile/edit.blade.php`

```blade
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
```

Le `<x-slot name="header">` Breeze est supprimé (le nouveau layout `app.blade.php` n'utilise plus de slot header). Page max-width 680px (proto).

## 5. Partial `update-profile-information-form.blade.php`

```blade
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
```

Notes :
- Layout 2 colonnes pour Nom + Email (proto).
- Section header avec divider `border-app-border-soft` sous le titre.
- Bouton "Enregistrer" aligné à droite, message confirmation `text-ok` à sa gauche.
- Le bloc `MustVerifyEmail` est conservé (logique Breeze) avec strings traduits.

## 6. Partial `update-password-form.blade.php`

```blade
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
```

Layout 1 colonne (3 inputs password empilés). Strings français.

## 7. Partial `delete-user-form.blade.php`

```blade
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
```

Card englobante (côté `edit.blade.php`) a la classe `border-danger-border` qui remplace la border par défaut. Header avec divider rouge. Modal réutilise `<x-modal>` re-stylé étape 3 (overlay backdrop-blur, box rounded-xl).

## 8. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test`. `ProfileTest` (Breeze) peut chercher "Save" ou "Profile" en anglais → adapter le test si nécessaire (changer asserts vers "Enregistrer" / "Profil"). Le comportement (POST, validation, session) reste identique.
- **Smoke Playwright** :
  - `/profile` : header "COMPTE / Profil" + 3 cards empilées (max-width 680px)
  - Card 1 : "Informations du compte" + 2 inputs (Nom / Email) en grid + bouton "Enregistrer"
  - Card 2 : "Changer le mot de passe" + 3 inputs password + bouton "Modifier le mot de passe"
  - Card 3 (border danger) : "Zone de danger" + texte + bouton "Supprimer mon compte"
  - Cliquer "Supprimer" → modal avec backdrop-blur, titre "Confirmer la suppression", input password, boutons Annuler / Supprimer définitivement
  - Console JS : 0 erreur

## 9. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 9 | `/admin/users` + `/admin/audit-log` |

Aucune autre dépendance.
