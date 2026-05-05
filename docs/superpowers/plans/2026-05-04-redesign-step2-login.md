# Redesign Étape 2 — `/login` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre visuellement la page `/login` selon le proto Claude Design (card 380px ombrée, logo amber 44px, inputs avec focus accent, bouton "Se connecter" pleine largeur). Re-styler `<x-text-input>` et `<x-input-label>` pour cohérence avec les autres pages auth + `/profile`.

**Architecture:** Aucune modification PHP métier — purement présentation. 3 fichiers Blade modifiés. Pas de nouveau composant. Validation auto via Pest + smoke browser via Playwright.

**Tech Stack:** Laravel 12, Blade, Tailwind CSS (palette `accent`/`app`/`ink` posée à l'étape 1), Pest 3, Playwright MCP pour smoke.

**Spec source:** `docs/superpowers/specs/2026-05-04-redesign-step2-login-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-04-redesign-step2-login-design.md`
- Existant : `docs/superpowers/plans/2026-05-04-redesign-step2-login.md` (ce fichier)

- [ ] **Step 1: Vérifier que les 2 fichiers sont sur disque**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??` (untracked) — spec et plan de l'étape 2.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-04-redesign-step2-login-design.md \
        docs/superpowers/plans/2026-05-04-redesign-step2-login.md
git commit -m "docs: add spec and plan for redesign step 2 (/login)"
```

---

## Task 2: Re-styler `<x-text-input>`

**Files:**
- Modify: `resources/views/components/text-input.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/components/text-input.blade.php` with**

```blade
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition-colors']) }}>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès, pas d'erreur sur `bg-app-elevated` / `border-app-border` / `focus:border-accent` / `focus:ring-accent/20`.

- [ ] **Step 3: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 passed (la suite ne dépend pas du style des inputs).

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/text-input.blade.php
git commit -m "refactor(inputs): restyle x-text-input to redesign palette"
```

---

## Task 3: Re-styler `<x-input-label>`

**Files:**
- Modify: `resources/views/components/input-label.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/components/input-label.blade.php` with**

```blade
@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted']) }}>
    {{ $value ?? $slot }}
</label>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/views/components/input-label.blade.php
git commit -m "refactor(inputs): restyle x-input-label to uppercase muted style"
```

---

## Task 4: Refondre `auth/login.blade.php`

**Files:**
- Modify: `resources/views/auth/login.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/auth/login.blade.php` with**

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

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Run Pest test suite**

```bash
php artisan test
```

Expected: 59 passed. Si un test Breeze cherche `Log in` (anglais) ou `Forgot your password?`, il échouera — adapter le test plutôt que la vue (la nouvelle vue est correcte). Documenter les ajustements éventuels.

NB pratique : la commande `grep -rn "Log in\|Forgot your password\|Remember me" tests/` permet d'identifier rapidement les tests à ajuster.

- [ ] **Step 4: Smoke Playwright (sera fait par le contrôleur après dispatch — ne pas exécuter dans le subagent)**

Le contrôleur prendra un screenshot de `/login` après commit pour vérifier visuellement.

- [ ] **Step 5: Commit**

```bash
git add resources/views/auth/login.blade.php
# si tests adjustés : git add tests/...
git commit -m "feat(login): redesign login page with branded card and amber CTA"
```

---

## Task 5: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP), pas par un subagent.

- [ ] **Step 1: Build et tests**

```bash
npm run build
php artisan test
```

Expected: tout vert.

- [ ] **Step 2: Screenshot Playwright de `/login` (déconnecté)**

Naviguer `http://127.0.0.1:8000/logout` (POST) puis `http://127.0.0.1:8000/login`. Capture viewport.

Vérifier :
- Card 380px centrée sur fond `#e4e8f0`
- Logo amber 44px carré centré, "NH Project" en titre, "Fleet Maintenance System" en sous-titre
- Input Email avec placeholder `vous@organisation.mil`
- Input Password avec placeholder `••••••••`
- Lien "Mot de passe oublié ?" en haut à droite du label "Mot de passe", couleur amber
- Bouton "Se connecter" pleine largeur amber
- Pas de "Remember me" checkbox

- [ ] **Step 3: Smoke fonctionnel — login via Playwright**

Soumettre le formulaire avec `test@nh.local` / `password`. Expected: redirect vers `/machines`.

- [ ] **Step 4: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error.

---

## Notes / pièges connus

- **Tests Breeze "Log in" / "Forgot your password?"** : Breeze crée des tests feature qui peuvent chercher ces strings anglaises dans la réponse HTML. Avec le passage en français (`Se connecter` / `Mot de passe oublié ?`), ces tests échoueront. Adapter le test (changer la string attendue) ou ajouter une string de fallback dans `lang/`. Préférer adapter le test.
- **Override `!important` sur `<x-primary-button>`** : on utilise `!w-full !justify-center !py-2.5 !text-[13px]` pour battre les `inline-flex px-3.5 py-1.5` du composant. C'est un override ponctuel propre à /login. Si l'usage devient récurrent, créer une variante `<x-primary-button size="lg" full>` plus tard (YAGNI pour l'instant).
- **Suppression de Remember me** : la fonctionnalité Laravel "remember" reste activée côté serveur (la colonne `remember_token` sur `users` est toujours là). Seule la checkbox UI disparaît. Si on veut la réactiver plus tard, on remet le bloc.
- **Re-style `<x-text-input>`** : impacte aussi `/profile`, `register`, `forgot-password`, etc. — c'est attendu et désiré (cohérence visuelle inputs).
- **`focus:ring-accent/20`** : Tailwind comprend la syntaxe `/20` pour 20% d'opacité sur les couleurs étendues. Si build échoue, vérifier la version Tailwind (≥ 3.0).
- **Logo SVG inline** : le SVG est dans login + sidebar (déjà présent). Si on veut factoriser, créer plus tard `<x-app-logo size="44|28">`. YAGNI.
