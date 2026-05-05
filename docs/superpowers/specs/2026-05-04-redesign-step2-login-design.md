# Design — Étape 2 / Refonte `/login`

**Date** : 2026-05-04
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — section "/login" du proto

## 1. Contexte

L'étape 1 (Foundation, mergée dans la branche) a posé : Tailwind palette sémantique, polices DM Sans/Mono, layout `guest.blade.php` (fond `#e4e8f0`, centered flex), composants Breeze re-stylés (`<x-primary-button>` amber). La page `/login` actuelle s'affiche dans ce shell mais utilise encore les composants Breeze bruts pour les inputs/labels.

Cette étape refait visuellement `/login` selon le proto : card 380px ombrée avec logo NH Project + sous-titre, inputs avec border accent au focus, bouton "Se connecter" pleine largeur amber. Re-style également les composants Blade `<x-text-input>` et `<x-input-label>` pour cohérence avec les autres pages auth et `/profile`.

## 2. Objectifs

- Re-styler `<x-text-input>` (border `app-border`, focus `accent`, fond `app-elevated`).
- Re-styler `<x-input-label>` (uppercase tracking-wide, text muted).
- Refondre `auth/login.blade.php` selon le proto :
  - Card 380px sur fond `#e4e8f0` (déjà fourni par `guest.blade.php`)
  - Logo amber 44px + "NH Project" + "Fleet Maintenance System"
  - Email + password fields
  - Lien "Mot de passe oublié ?" inline avec le label password (pas en bas)
  - Bouton "Se connecter" pleine largeur amber
- Suivre le proto strictement : pas de checkbox "Remember me".
- Strings français (`Mot de passe`, `Mot de passe oublié ?`, `Se connecter`).

**Non-objectifs** :
- Refonte des autres vues auth (`register`, `forgot-password`, `reset-password`, `verify-email`, `confirm-password`) — elles bénéficient automatiquement du re-style des composants `<x-text-input>` et `<x-input-label>` mais leur layout reste Breeze.
- Refonte de `/profile` — étape 8.
- Modification de la logique d'authentification (route, controller, middleware, `LoginRequest`) — purement front.
- Suppression du Remember me côté serveur (la fonctionnalité Laravel reste, juste la checkbox UI absente).

## 3. Architecture / fichiers touchés

```
nh-web/
├── resources/views/
│   ├── auth/
│   │   └── login.blade.php              (refonte complète)
│   └── components/
│       ├── text-input.blade.php         (re-stylé)
│       └── input-label.blade.php        (re-stylé)
```

Aucun fichier PHP non-vue n'est touché. Aucun nouveau composant Blade créé.

## 4. Composants Breeze re-stylés

### `components/text-input.blade.php`

```blade
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition-colors']) }}>
```

**Changements** :
- `border-gray-300` → `border-app-border`
- `focus:border-indigo-500 focus:ring-indigo-500` → `focus:border-accent focus:ring-2 focus:ring-accent/20`
- Ajout de `w-full bg-app-elevated text-ink-primary px-3 py-2 text-sm placeholder:text-ink-muted transition-colors`
- `rounded-md` conservé
- Le `shadow-sm` est supprimé (le proto n'en a pas)

### `components/input-label.blade.php`

```blade
@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted']) }}>
    {{ $value ?? $slot }}
</label>
```

**Changements** :
- `font-medium text-sm text-gray-700` → `text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted`

## 5. Page `auth/login.blade.php`

```blade
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
```

### Détails

- **Card** : `w-[380px] max-w-[95vw] p-10 bg-app-card border border-app-border rounded-xl shadow-[0_24px_64px_rgba(26,34,53,0.15)]`. Le shadow custom inline reproduit `box-shadow: 0 24px 64px rgba(26,34,53,0.15)` du proto.
- **Logo** : carré 44px (`w-11 h-11`) bg-accent, SVG hélico inline (couleur intérieure `#0e1117`).
- **Lien "Mot de passe oublié ?"** : inline avec le label password (`flex items-center justify-between`), text-[11px] amber.
- **Bouton "Se connecter"** : utilise `<x-primary-button>` avec override `!w-full !justify-center !py-2.5 !text-[13px]` — les `!` sont nécessaires pour battre les `inline-flex px-3.5 py-1.5` du composant. Override ponctuel propre à /login.
- **Pas de "Remember me"**.
- **Suppression du `<a>` "Forgot your password?"** en bas du formulaire (présent dans la version actuelle Breeze).

### Strings i18n

Les strings sont entourées de `__()` mais non traduites (le projet n'utilise pas l'i18n). Les valeurs littérales sont en français :
- `__('Email')` → "Email"
- `__('Mot de passe')` → "Mot de passe"
- `__('Mot de passe oublié ?')` → "Mot de passe oublié ?"
- `__('Se connecter')` → "Se connecter"

Si une traduction est ajoutée plus tard via `lang/`, le wrapper `__()` permettra de la prendre en compte sans changer la vue.

## 6. Plan de tests

Pas de test Pest à écrire. Validation :

1. **Build** : `npm run build` vert.
2. **Suite Pest** : `php artisan test` vert. Le test feature `AuthenticationTest` (Breeze) :
   - Cherche le formulaire à `/login` → toujours présent.
   - Soumet `email` + `password` → noms de champs inchangés.
   - Vérifie redirect vers `/dashboard` ou route équivalente.
   - Si un test cherche le texte "Log in" ou "Forgot your password?" en anglais → il échoue. Adapter le test : `assertSee('Se connecter')` à la place.
3. **Smoke browser via Playwright** :
   - `/login` (déconnecté) → screenshot, vérifier card centrée 380px, logo amber, inputs avec focus accent, bouton pleine largeur, lien "Mot de passe oublié ?" inline.
   - Soumettre le formulaire avec `test@nh.local` / `password` → redirect vers `/machines` (login fonctionne).
   - Tester le tab order : Email → Password → "Mot de passe oublié ?" → Se connecter.

## 7. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 3 | `/machines` — la page principale |
| 4 | `/machines/{HcId}` |
| 5 | `/flights/{id}` + sous-pages |
| 6 | `/upload` + `/imports` |
| 7 | `/dashboards` |
| 8 | `/profile` |

Refonte des autres vues auth (`register`, `forgot-password`, `reset-password`, `verify-email`, `confirm-password`) : pas planifiée. Elles continueront à fonctionner avec les composants Breeze re-stylés (`<x-text-input>`, `<x-input-label>`, `<x-primary-button>`) mais leur layout restera Breeze. Si besoin un jour, refonte ad-hoc.
