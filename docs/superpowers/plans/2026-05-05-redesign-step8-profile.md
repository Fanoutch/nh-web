# Redesign Étape 8 — `/profile` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre `/profile` selon le proto : header section-label "Compte" + h1 "Profil" + 3 cards empilées max-width 680px (Informations / Mot de passe / Zone de danger). Strings français, layout 2 colonnes pour info, modal delete avec backdrop-blur.

**Architecture:** 4 fichiers Blade modifiés (1 vue principale + 3 partials). Aucune modification PHP. Composants `<x-text-input>`, `<x-input-label>`, boutons et `<x-modal>` déjà re-stylés aux étapes précédentes.

**Tech Stack:** Laravel 12, Blade, Alpine.js (modal + transitions), Tailwind CSS, Pest 3, Playwright MCP.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step8-profile-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step8-profile-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step8-profile.md`

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??`.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step8-profile-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step8-profile.md
git commit -m "docs: add spec and plan for redesign step 8 (/profile)"
```

---

## Task 2: Refondre `profile/edit.blade.php`

**Files:**
- Modify: `resources/views/profile/edit.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/profile/edit.blade.php
git commit -m "feat(profile): redesign /profile wrapper with section-label and 3-card layout"
```

---

## Task 3: Refondre `update-profile-information-form.blade.php`

**Files:**
- Modify: `resources/views/profile/partials/update-profile-information-form.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/profile/partials/update-profile-information-form.blade.php
git commit -m "feat(profile): redesign profile information form with French labels and 2-col grid"
```

---

## Task 4: Refondre `update-password-form.blade.php`

**Files:**
- Modify: `resources/views/profile/partials/update-password-form.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 3: Commit**

```bash
git add resources/views/profile/partials/update-password-form.blade.php
git commit -m "feat(profile): redesign password form with French labels and amber CTA"
```

---

## Task 5: Refondre `delete-user-form.blade.php`

**Files:**
- Modify: `resources/views/profile/partials/delete-user-form.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

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

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants. Si `ProfileTest` cherche "Delete Account" en anglais, adapter le test (changer asserts vers "Supprimer mon compte").

- [ ] **Step 3: Commit**

```bash
git add resources/views/profile/partials/delete-user-form.blade.php
# si test ajusté : git add tests/Feature/ProfileTest.php
git commit -m "feat(profile): redesign delete-account section with danger zone and FR modal"
```

---

## Task 6: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests finaux**

```bash
npm run build
php artisan test
```

Expected: tout vert.

- [ ] **Step 2: Screenshot Playwright `/profile`**

Naviguer `http://127.0.0.1:8000/profile` (connecté). Capture viewport 1440×900.

Vérifier :
- Header section-label "COMPTE" + h1 "Profil"
- Page max-width 680px (les cards font 680px max, le reste à droite vide)
- Card 1 "Informations du compte" : header avec divider sous le titre, grid 2 colonnes (Nom / Email), bouton "Enregistrer" amber à droite
- Card 2 "Changer le mot de passe" : header avec divider, 3 inputs password empilés, bouton "Modifier le mot de passe" amber à droite
- Card 3 (border danger rouge) "Zone de danger" : titre rouge avec divider rouge, paragraphe explicatif, bouton "Supprimer mon compte" rouge soft

- [ ] **Step 3: Test du modal delete**

Cliquer "Supprimer mon compte" → modal apparaît.

Vérifier :
- Overlay sombre + backdrop-blur visible
- Box bg-app-card border rounded-xl max-w-560px
- Titre "Confirmer la suppression du compte"
- Paragraphe explicatif
- Input password (avec placeholder "Mot de passe")
- 2 boutons : "Annuler" ghost + "Supprimer définitivement" rouge

Cliquer "Annuler" → modal disparaît.

- [ ] **Step 4: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error.

---

## Notes / pièges connus

- **`x-slot name="header"` supprimé** : l'ancien layout Breeze utilisait un slot header. Notre nouveau `app.blade.php` (étape 1) ne l'utilise plus, donc on retire l'élément `<x-slot name="header">` pour éviter qu'il soit ignoré silencieusement.
- **`MustVerifyEmail`** : ce bloc conditionnel n'est probablement pas activé sur ce projet (User n'implémente pas l'interface) mais on le garde pour ne pas casser la logique Breeze. Strings français au cas où.
- **Test Pest `ProfileTest`** : peut chercher des strings anglaises (ex : "Profile Information", "Save", "Delete Account"). Si test échoue, adapter le test : changer les `assertSee('Save')` en `assertSee('Enregistrer')` etc. Le comportement (POST, validation, session) reste identique car les routes et form fields ne changent pas.
- **`<x-modal>`** : déjà re-stylé étape 3 (overlay backdrop-blur, box bg-app-card border rounded-xl, max-width 560px). Pas de modification ici, juste utilisation.
- **`border-danger-border` sur `<x-card>`** : cette classe override la border par défaut (`border border-app-border` du composant). Tailwind compile ça correctement car les deux classes sont reconnues — la dernière (border-danger-border) gagne par spécificité de l'attribute merge.
- **`text-ok` pour les messages "Enregistré."** : la couleur `ok` (vert `#1a7a48`) est déjà définie dans la palette étape 1.
- **Layout 2 cols sur info** : `grid grid-cols-2 gap-4` — les inputs sont côte à côte. Sur très petit viewport ça peut être serré mais on est en desktop-first.
