# Redesign Foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pose la fondation visuelle du redesign : config Tailwind étendue (palette sémantique amber/dark sidebar/light main + DM Sans/Mono), refonte des 3 layouts (`app`, `sidebar`, `guest`), re-style des 3 boutons Breeze + 4 nouveaux composants Blade (`<x-ok-button>`, `<x-badge>`, `<x-card>`, `<x-section-label>`).

**Architecture:** Aucune modification PHP métier. On étend uniquement la couche présentation (CSS / Tailwind / Blade). Les vues internes restent intactes — elles consomment les composants Breeze re-stylés et le nouveau layout sidebar mais leurs templates ne sont pas touchés (attendu : look "moitié refait" pendant cette étape, sera complété aux étapes 2-8).

**Tech Stack:** Laravel 12, Tailwind CSS 3, Alpine.js (via Breeze), DM Sans + DM Mono via fonts.bunny.net, Vite.

**Spec source:** `docs/superpowers/specs/2026-05-04-redesign-foundation-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-04-redesign-foundation-design.md` (déjà écrit)
- Existant : `docs/superpowers/plans/2026-05-04-redesign-foundation.md` (ce fichier)

- [ ] **Step 1: Vérifier que les 2 fichiers sont sur disque et untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??` (untracked), spec et plan.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-04-redesign-foundation-design.md \
        docs/superpowers/plans/2026-05-04-redesign-foundation.md
git commit -m "docs: add spec and plan for redesign foundation (light theme)"
```

---

## Task 2: Étendre `tailwind.config.js`

**Files:**
- Modify: `tailwind.config.js`

- [ ] **Step 1: Lire le fichier actuel**

```bash
cat tailwind.config.js
```

Confirme la structure existante (`fontFamily.sans: ['Figtree', ...]`, plugins `forms`).

- [ ] **Step 2: Remplacer le contenu de `tailwind.config.js`**

Remplacer **tout** le contenu par :

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['DM Sans', ...defaultTheme.fontFamily.sans],
                mono: ['DM Mono', ...defaultTheme.fontFamily.mono],
            },

            colors: {
                app: {
                    bg:            '#eef1f6',
                    card:          '#f5f7fb',
                    elevated:      '#ffffff',
                    border:        '#dde2ec',
                    'border-soft': '#e8ecf4',
                    hover:         '#eef1f6',
                },
                sidebar: {
                    bg:             '#0a0d12',
                    border:         '#1a2030',
                    panel:          '#1d2433',
                    'panel-border': '#2a3347',
                },
                ink: {
                    primary:         '#1a2235',
                    secondary:       '#5a6682',
                    muted:           '#9aa3b8',
                    'on-dark':       '#e8ecf5',
                    'muted-on-dark': '#8b95ad',
                },
                accent: {
                    DEFAULT:       '#f5b731',
                    hover:         '#fbc84a',
                    pressed:       '#c49010',
                    soft:          '#fef9eb',
                    'soft-strong': '#fef3d0',
                    'soft-border': '#f0d070',
                },
                ok:      { DEFAULT: '#1a7a48', soft: '#d1f5e4', border: '#a8e8c8' },
                danger:  { DEFAULT: '#c0391a', soft: '#fde8e2', border: '#f5c0b0' },
                warn:    { DEFAULT: '#a07010', soft: '#fef3d0', border: '#f0d070' },
                neutral: { DEFAULT: '#5a6682', soft: '#e8ecf5', border: '#c4cad8' },
            },

            keyframes: {
                pulseAmber: {
                    '0%,100%': { opacity: '1' },
                    '50%':     { opacity: '0.45' },
                },
                fadeInPage: {
                    from: { opacity: '0', transform: 'translateY(4px)' },
                    to:   { opacity: '1', transform: 'translateY(0)'  },
                },
            },
            animation: {
                'pulse-amber': 'pulseAmber 1.5s ease-in-out infinite',
                'fade-in':     'fadeInPage 0.18s ease-out',
            },
        },
    },

    plugins: [forms],
};
```

- [ ] **Step 3: Lancer un build de validation Vite**

```bash
npm run build
```

Expected: build réussi sans erreur Tailwind. Output `Vite build done`. Pas d'erreur "unknown utility class".

- [ ] **Step 4: Commit**

```bash
git add tailwind.config.js
git commit -m "feat(theme): extend tailwind config with semantic palette and DM fonts"
```

---

## Task 3: Mettre à jour `resources/css/app.css`

**Files:**
- Modify: `resources/css/app.css`

- [ ] **Step 1: Lire le fichier actuel**

```bash
cat resources/css/app.css
```

Expected : 3 lignes `@tailwind base/components/utilities`.

- [ ] **Step 2: Remplacer le contenu**

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
    body {
        @apply font-sans antialiased bg-app-bg text-ink-primary;
    }

    /* Scrollbars custom (light theme) */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: #e4e8f0; }
    ::-webkit-scrollbar-thumb { background: #c4cad8; border-radius: 3px; }
    ::-webkit-scrollbar-thumb:hover { background: #a8b0c4; }

    [x-cloak] { display: none !important; }
}
```

- [ ] **Step 3: Rebuild Vite et confirmer**

```bash
npm run build
```

Expected: build réussit. Aucune erreur sur `bg-app-bg` ou `text-ink-primary` (les utilitaires existent grâce à Task 2).

- [ ] **Step 4: Commit**

```bash
git add resources/css/app.css
git commit -m "feat(css): set body base styles, scrollbars, x-cloak rule"
```

---

## Task 4: Refondre `layouts/app.blade.php`

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Remplacer le contenu**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'NH Project') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600|dm-mono:400,500&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-app-bg text-ink-primary overflow-hidden">
    <div class="flex h-screen">
        @include('layouts.sidebar')

        <main class="flex-1 overflow-y-auto">
            <div class="px-8 py-7 min-h-full">
                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
```

- [ ] **Step 2: Tester le rendu**

Lancer le serveur (déjà tournant via `php artisan serve` sur :8000). Charger `http://127.0.0.1:8000/machines` (login si besoin avec `test@nh.local` / `password`).

Expected:
- La page se charge sans erreur 500
- Sidebar visible à gauche (encore avec l'ancien style sombre `slate-900` car `sidebar.blade.php` n'est pas encore refait — c'est attendu)
- Polices DM Sans visible (le texte change d'aspect par rapport à Figtree)
- Pas d'erreur dans la console JS

- [ ] **Step 3: Suite Pest pour vérifier régression nulle**

```bash
php artisan test
```

Expected: tous les tests passent (les `assertSee()` continuent à matcher du texte).

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(layout): rebuild app layout shell with split sidebar/main and DM fonts"
```

---

## Task 5: Refonte de `layouts/sidebar.blade.php`

**Files:**
- Modify: `resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1: Remplacer entièrement le contenu**

```blade
@php
    $active = request()->route()?->getName() ?? '';

    $links = [
        [
            'route' => 'machines.index',
            'label' => 'Machines',
            'prefixes' => ['machines.', 'flights.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1" y="3" width="5" height="9" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="1" width="5" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M6 8h3" stroke="currentColor" stroke-width="1.3"/></svg>',
        ],
        [
            'route' => 'upload.index',
            'label' => 'Upload',
            'prefixes' => ['upload.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M7.5 1.5V10M4.5 4.5l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M2 11v2a.5.5 0 00.5.5h10a.5.5 0 00.5-.5v-2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
        [
            'route' => 'imports.index',
            'label' => 'Imports',
            'prefixes' => ['imports.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><circle cx="7.5" cy="7.5" r="6" stroke="currentColor" stroke-width="1.3"/><path d="M7.5 4v4l2.5 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
        [
            'route' => 'dashboards.index',
            'label' => 'Dashboards',
            'prefixes' => ['dashboards.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1.5" y="8" width="3" height="5.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="6" y="5" width="3" height="8.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="10.5" y="2" width="3" height="11.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/></svg>',
        ],
    ];

    $processingCount = \App\Models\Import::where('status', 'processing')->count();
    $user = auth()->user();
    $initials = collect(explode(' ', $user?->name ?? 'U'))
        ->map(fn ($s) => mb_substr($s, 0, 1))
        ->take(2)->implode('');
@endphp

<aside class="w-[200px] shrink-0 bg-sidebar-bg border-r border-sidebar-border flex flex-col">
    {{-- Logo --}}
    <div class="px-4 py-5 border-b border-sidebar-border flex items-center gap-2.5">
        <div class="w-7 h-7 bg-accent rounded flex items-center justify-center shrink-0">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M8 2L14 5v3l-2 1-4-2-4 2-2-1V5L8 2z" fill="#0e1117"/>
                <path d="M4 9v4l4 1 4-1V9" stroke="#0e1117" stroke-width="1.2" fill="none"/>
            </svg>
        </div>
        <div>
            <div class="font-semibold text-[13px] text-ink-on-dark leading-none">NH Project</div>
            <div class="text-[10px] text-ink-muted-on-dark mt-0.5">Fleet Maintenance</div>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 p-2 flex flex-col gap-0.5">
        @foreach ($links as $link)
            @php $isActive = collect($link['prefixes'])->contains(fn ($p) => str_starts_with($active, $p)); @endphp
            <a href="{{ route($link['route']) }}"
               @class([
                   'flex items-center gap-2.5 px-3.5 py-2.5 rounded-md text-[13px] font-medium transition',
                   'bg-sidebar-panel text-accent' => $isActive,
                   'text-ink-muted-on-dark hover:bg-sidebar-panel hover:text-ink-on-dark' => !$isActive,
               ])>
                {!! $link['icon'] !!}
                <span class="flex-1">{{ $link['label'] }}</span>
                @if ($link['route'] === 'imports.index' && $processingCount > 0)
                    <span class="font-mono text-[10px] font-semibold bg-accent-soft-strong text-accent px-1.5 py-0.5 rounded">
                        {{ $processingCount }}
                    </span>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- User dropdown --}}
    <div class="border-t border-sidebar-border p-2 relative" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" @click="open = !open"
                class="w-full flex items-center gap-2 px-2.5 py-2 rounded-md hover:bg-sidebar-panel transition text-left">
            <div class="w-[26px] h-[26px] bg-sidebar-panel-border rounded-full flex items-center justify-center text-[11px] font-semibold text-accent shrink-0">
                {{ strtoupper($initials) }}
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-[12px] font-medium text-ink-on-dark truncate">{{ $user?->name ?? '—' }}</div>
                <div class="text-[10px] text-ink-muted-on-dark truncate">{{ $user?->email }}</div>
            </div>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" class="text-ink-muted-on-dark">
                <path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute bottom-[64px] left-2 right-2 bg-sidebar-panel border border-sidebar-panel-border rounded-md overflow-hidden shadow-xl z-50">
            <a href="{{ route('profile.edit') }}"
               class="block px-3 py-2 text-[12px] text-ink-on-dark hover:bg-sidebar-panel-border transition">
                Profil
            </a>
            <hr class="border-sidebar-panel-border">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-left px-3 py-2 text-[12px] text-danger hover:bg-sidebar-panel-border transition">
                    Déconnexion
                </button>
            </form>
        </div>
    </div>
</aside>
```

- [ ] **Step 2: Tester visuellement**

Ouvrir `http://127.0.0.1:8000/machines` dans un navigateur. Vérifier :
- Sidebar : fond sombre `#0a0d12`, logo amber 28px en haut, 4 items nav
- Item actif (`Machines`) : fond `bg-sidebar-panel`, texte amber
- Hover sur les autres items : fond panel, texte clair
- Badge processing : si une import a `status='processing'`, badge ambre apparaît à droite de "Imports"
- En bas : avatar avec initiales amber, nom + email tronqués
- Clic sur l'avatar : dropdown apparaît avec "Profil" et "Déconnexion"
- Clic en dehors : dropdown se ferme
- Clic sur "Profil" : navigation vers `/profile`
- Clic sur "Déconnexion" : POST logout, retour à `/login`

- [ ] **Step 3: Suite Pest**

```bash
php artisan test
```

Expected: tous verts. Note : si un test cherche du texte spécifique de l'ancienne sidebar (par ex. "Deconnexion" sans accent vs "Déconnexion"), corriger le test plutôt que la sidebar.

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/sidebar.blade.php
git commit -m "feat(sidebar): redesign with amber accent, processing badge and Alpine user dropdown"
```

---

## Task 6: Mettre à jour `layouts/guest.blade.php`

**Files:**
- Modify: `resources/views/layouts/guest.blade.php`

- [ ] **Step 1: Lire l'existant**

```bash
cat resources/views/layouts/guest.blade.php
```

- [ ] **Step 2: Remplacer le contenu**

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'NH Project') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600|dm-mono:400,500&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased text-ink-primary" style="background:#e4e8f0;">
    <div class="min-h-screen flex items-center justify-center">
        {{ $slot }}
    </div>
</body>
</html>
```

- [ ] **Step 3: Vérifier que `/login` se charge**

Se déconnecter (via le dropdown sidebar ou directement `http://127.0.0.1:8000/logout`), puis charger `http://127.0.0.1:8000/login`.

Expected:
- Fond gris clair `#e4e8f0`
- Le formulaire Breeze (logo, email, password, "log in" button) apparaît centré
- Le formulaire reste avec son ancien look Breeze (sera refait à l'étape 2 — c'est attendu)

- [ ] **Step 4: Commit**

```bash
git add resources/views/layouts/guest.blade.php
git commit -m "feat(layout): rebuild guest shell with DM fonts and centered card background"
```

---

## Task 7: Supprimer `layouts/navigation.blade.php`

**Files:**
- Delete: `resources/views/layouts/navigation.blade.php`

- [ ] **Step 1: Vérifier non-utilisation (déjà fait dans le spec, on confirme)**

```bash
grep -rn "layouts.navigation\|@include.*navigation" resources/views/ 2>/dev/null
```

Expected: aucun résultat (le fichier n'est référencé nulle part).

- [ ] **Step 2: Supprimer le fichier**

```bash
git rm resources/views/layouts/navigation.blade.php
```

- [ ] **Step 3: Suite Pest pour confirmer aucune régression**

```bash
php artisan test
```

Expected: tous verts.

- [ ] **Step 4: Commit**

```bash
git commit -m "chore: remove unused Breeze top-nav layout"
```

---

## Task 8: Re-styler `<x-primary-button>`

**Files:**
- Modify: `resources/views/components/primary-button.blade.php`

- [ ] **Step 1: Remplacer le contenu**

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Vérifier visuellement**

Charger `/profile` (ou `/upload`). Le bouton "Enregistrer" / "Traiter X fichiers" doit être amber `#f5b731` avec texte sombre, sans uppercase ni tracking-widest.

---

## Task 9: Re-styler `<x-secondary-button>`

**Files:**
- Modify: `resources/views/components/secondary-button.blade.php`

- [ ] **Step 1: Remplacer le contenu**

```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

---

## Task 10: Re-styler `<x-danger-button>`

**Files:**
- Modify: `resources/views/components/danger-button.blade.php`

- [ ] **Step 1: Remplacer le contenu**

```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-danger-soft border border-danger-border text-danger text-xs font-medium hover:bg-danger-border focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit les 3 boutons re-stylés ensemble**

```bash
git add resources/views/components/primary-button.blade.php \
        resources/views/components/secondary-button.blade.php \
        resources/views/components/danger-button.blade.php
git commit -m "refactor(buttons): restyle Breeze buttons (primary/secondary/danger) to new design"
```

---

## Task 11: Créer `<x-ok-button>`

**Files:**
- Create: `resources/views/components/ok-button.blade.php`

- [ ] **Step 1: Créer le fichier**

```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-ok-soft border border-ok-border text-ok text-xs font-medium hover:bg-ok-border focus:outline-none focus:ring-2 focus:ring-ok focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès, classes `bg-ok-soft text-ok` reconnues.

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/ok-button.blade.php
git commit -m "feat(buttons): add x-ok-button component for validation actions"
```

---

## Task 12: Créer `<x-badge>`

**Files:**
- Create: `resources/views/components/badge.blade.php`

- [ ] **Step 1: Créer le fichier**

```blade
@props(['variant' => 'pending'])

@php
    $classes = match ($variant) {
        'ok', 'validated'      => 'bg-ok-soft text-ok',
        'error', 'rejected'    => 'bg-danger-soft text-danger',
        'pending'              => 'bg-neutral-soft text-neutral border border-neutral-border',
        'processing'           => 'bg-accent-soft-strong text-warn animate-pulse-amber',
        'nonvol'               => 'bg-neutral-soft text-neutral border border-neutral-border',
        'already', 'amber'     => 'bg-accent-soft-strong text-warn',
        default                => 'bg-neutral-soft text-neutral border border-neutral-border',
    };
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 px-2 py-0.5 rounded font-mono text-[11px] font-medium tracking-wide $classes"]) }}>
    {{ $slot }}
</span>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Smoke test du composant**

Créer un fichier temporaire `resources/views/_smoke.blade.php` (juste pour validation visuelle, supprimé en step 5) :

```blade
<x-app-layout>
    <div class="space-y-2">
        <x-badge variant="ok">Validée</x-badge>
        <x-badge variant="error">Rejetée</x-badge>
        <x-badge variant="pending">Pending</x-badge>
        <x-badge variant="processing">Processing</x-badge>
        <x-badge variant="nonvol">Non-Vol</x-badge>
        <x-badge variant="already">Déjà traité</x-badge>
        <x-badge variant="amber">Custom amber</x-badge>
    </div>
</x-app-layout>
```

Ajouter une route temporaire dans `routes/web.php` (à supprimer en step 5) :

```php
Route::get('/_smoke-badges', fn () => view('_smoke'))->middleware('auth');
```

Charger `http://127.0.0.1:8000/_smoke-badges` et vérifier les 7 badges affichés correctement (vert, rouge, gris pending, ambre pulse, gris nonvol, ambre, ambre).

- [ ] **Step 4: Suite Pest**

```bash
php artisan test
```

Expected: tous verts.

- [ ] **Step 5: Nettoyer la route et la vue de smoke**

```bash
rm resources/views/_smoke.blade.php
```

Et retirer manuellement la route `Route::get('/_smoke-badges', ...)` de `routes/web.php`.

- [ ] **Step 6: Commit**

```bash
git add resources/views/components/badge.blade.php
git commit -m "feat(badges): add x-badge component with semantic variants"
```

---

## Task 13: Créer `<x-card>`

**Files:**
- Create: `resources/views/components/card.blade.php`

- [ ] **Step 1: Créer le fichier**

```blade
@props(['variant' => 'default'])

@php
    $bg = $variant === 'elevated' ? 'bg-app-elevated' : 'bg-app-card';
@endphp

<div {{ $attributes->merge(['class' => "$bg border border-app-border rounded-lg"]) }}>
    {{ $slot }}
</div>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/card.blade.php
git commit -m "feat(cards): add x-card component with default and elevated variants"
```

---

## Task 14: Créer `<x-section-label>`

**Files:**
- Create: `resources/views/components/section-label.blade.php`

- [ ] **Step 1: Créer le fichier**

```blade
<div {{ $attributes->merge(['class' => 'text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-muted']) }}>
    {{ $slot }}
</div>
```

- [ ] **Step 2: Rebuild Vite**

```bash
npm run build
```

Expected: succès.

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/section-label.blade.php
git commit -m "feat(labels): add x-section-label component"
```

---

## Task 15: Validation finale

- [ ] **Step 1: Build complet**

```bash
npm run build
```

Expected: succès, taille bundle CSS raisonnable (~30-50 KB compressée).

- [ ] **Step 2: Suite Pest globale**

```bash
php artisan test
```

Expected: **tous les tests passent** (les `assertSee()` continuent à fonctionner — le HTML rendu contient toujours les mêmes textes, juste avec des classes Tailwind différentes).

- [ ] **Step 3: Smoke manuel**

Vérifications obligatoires dans le navigateur :

1. **`/machines`** (connecté)
   - Sidebar : fond sombre, logo amber, 4 items nav avec icônes
   - Item actif "Machines" : fond panel + texte amber
   - User menu en bas : avatar avec initiales amber
   - Clic avatar : dropdown apparaît, "Profil" + "Déconnexion"
   - Page principale : encore look Breeze (cards blanches, bleu) — c'est attendu
   - Police DM Sans visible (différent de Figtree)
2. **`/profile`** (connecté)
   - Le bouton "Enregistrer" est amber (plus indigo)
   - Le bouton "Annuler" / fermeture est ghost (border + transparent)
   - Le bouton "Supprimer mon compte" est rouge soft (plus rouge plein)
3. **`/login`** (déconnecté)
   - Fond gris clair `#e4e8f0`
   - Card centrée
   - Police DM Sans
4. **Console JS** ouverte → aucune erreur

- [ ] **Step 4: Push de la branche (sans merger)**

```bash
git log --oneline -20    # vérifier l'historique
# ne pas pusher tout de suite — laisser l'utilisateur valider
```

Note : on ne pousse pas la branche `feature/redesign-light` à ce stade — la décision de push appartient à l'utilisateur, qui peut vouloir continuer avec les étapes 2-8 avant.

---

## Notes / pièges connus

- **Tailwind JIT et palette nested** : Les classes comme `bg-app-card`, `text-ink-on-dark`, `bg-ok-soft` sont des **utilitaires custom** générés par Tailwind à partir de la config étendue. Si une classe ne marche pas après build, vérifier qu'elle est bien lue par le glob `content` (les `.blade.php` doivent être dans `resources/views/**`).
- **`$processingCount` dans la sidebar** : 1 query SELECT COUNT par render de page. Coût négligeable (table `imports` petite, index sur `status`). Si plus tard on veut éviter cette query, on peut utiliser un **view composer** qui cache 30s.
- **Initiales utilisateur** : `mb_substr` pour gérer les caractères Unicode (accents). Fallback "U" si pas de nom.
- **Position du dropdown user** : `bottom-[64px]` est calculé par rapport à la hauteur du bloc user-menu. Si on change la hauteur du user-menu, ajuster.
- **Suppression de `navigation.blade.php`** : confirmé par grep zéro référence. Si jamais un fichier le référence (faux positif du grep), `php artisan view:clear` puis re-run pour reproduire.
- **`x-cloak`** : Alpine ajoute cet attribut sur les éléments hidden au chargement. La règle `[x-cloak] { display: none !important; }` empêche le flash visuel avant l'init Alpine.
- **Breeze CSS classes (`focus:ring-indigo-500`)** : on les remplace toutes par `focus:ring-accent` ou `focus:ring-ok`/`focus:ring-danger` selon le bouton. `ring-offset-2` reste, c'est neutre.
- **Pas de `<x-input-label>` ou `<x-text-input>`** modifié dans cette étape : ils restent Breeze pour /login et /profile, et seront re-stylés à leurs étapes respectives.
