# Design — Étape 1 / Foundation : Tailwind + Layout + Composants

**Date** : 2026-05-04
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff (light theme, sidebar dark) — bundle `frontv2/nh90-caiman/`

## 1. Contexte

Refonte visuelle complète du front Laravel pour adopter l'identité du handoff Claude Design : light theme content + sidebar dark + accent amber `#f5b731`, polices DM Sans / DM Mono. La refonte est faite sur une seule branche `feature/redesign-light` mergée en une seule fois quand les 8 étapes sont terminées.

Cette étape (Étape 1 sur 8) pose la **fondation** : config Tailwind étendue, polices, layout global (`app.blade.php`, `sidebar.blade.php`, `guest.blade.php`), composants Blade réutilisables. Aucune page interne n'est touchée — elles utilisent toujours leurs templates actuels et resteront visuellement Breeze jusqu'à leur étape dédiée.

## 2. Objectifs

- Étendre `tailwind.config.js` avec une palette sémantique nested (app, sidebar, ink, accent, ok, danger, warn, neutral).
- Charger DM Sans + DM Mono via `fonts.bunny.net` (même CDN que Figtree actuel).
- Refondre les 3 layouts : `app.blade.php` (split sidebar/main), `sidebar.blade.php` (refonte complète, accent amber, dropdown user Alpine), `guest.blade.php` (centered card, fond `#e4e8f0`).
- Re-styler les 3 composants Breeze existants (`<x-primary-button>`, `<x-secondary-button>`, `<x-danger-button>`) pour matcher le proto, sans changer leur API.
- Ajouter 4 nouveaux composants Blade : `<x-ok-button>`, `<x-badge>`, `<x-card>`, `<x-section-label>`.
- Supprimer `layouts/navigation.blade.php` (code mort Breeze).
- Ajouter `[x-cloak]` rule dans `app.css` pour les transitions Alpine.

**Non-objectifs** :
- Toucher aux pages internes (`/machines`, `/flights/*`, `/imports`, `/dashboards`, `/profile`, `/login`).
- Implémenter le mode dark complet (gardé en référence dans `frontv2/`).
- Gestion mobile / responsive < 768px.
- Composants spécifiques aux pages (`<x-panne-row>`, `<x-counter-pill>`) — créés à leurs étapes respectives.

## 3. Architecture / fichiers touchés

```
nh-web/
├── tailwind.config.js                       (modifié — palette + fonts + animations étendues)
├── resources/
│   ├── css/app.css                          (modifié — body base, scrollbars, x-cloak)
│   └── views/
│       ├── layouts/
│       │   ├── app.blade.php                (modifié — fonts + body classes)
│       │   ├── sidebar.blade.php            (refonte complète)
│       │   ├── guest.blade.php              (modifié — fonts + body bg)
│       │   └── navigation.blade.php         (SUPPRIMÉ)
│       └── components/
│           ├── primary-button.blade.php     (re-stylé — amber)
│           ├── secondary-button.blade.php   (re-stylé — ghost)
│           ├── danger-button.blade.php      (re-stylé — red soft)
│           ├── ok-button.blade.php          (NOUVEAU — green soft)
│           ├── badge.blade.php              (NOUVEAU)
│           ├── card.blade.php               (NOUVEAU)
│           └── section-label.blade.php      (NOUVEAU)
```

Aucun fichier PHP non-vue (controller, model, service, migration, route) n'est touché.

## 4. Tailwind config

### Palette sémantique nested

Stratégie : noms par rôle (pas par couleur) pour permettre un futur swap dark sans réécrire les classes Blade.

```js
colors: {
    // Background / surfaces (light theme)
    app: {
        bg:            '#eef1f6',  // viewport
        card:          '#f5f7fb',  // cards default
        elevated:      '#ffffff',  // cards elevated, inputs
        border:        '#dde2ec',  // primary border
        'border-soft': '#e8ecf4',  // soft dividers
        hover:         '#eef1f6',  // tr hover bg
    },

    // Sidebar (always dark)
    sidebar: {
        bg:             '#0a0d12',
        border:         '#1a2030',
        panel:          '#1d2433',
        'panel-border': '#2a3347',
    },

    // Text scale
    ink: {
        primary:         '#1a2235',
        secondary:       '#5a6682',
        muted:           '#9aa3b8',
        'on-dark':       '#e8ecf5',
        'muted-on-dark': '#8b95ad',
    },

    // Amber accent
    accent: {
        DEFAULT:       '#f5b731',
        hover:         '#fbc84a',
        pressed:       '#c49010',
        soft:          '#fef9eb',
        'soft-strong': '#fef3d0',
        'soft-border': '#f0d070',
    },

    // Status semantics
    ok:      { DEFAULT: '#1a7a48', soft: '#d1f5e4', border: '#a8e8c8' },
    danger:  { DEFAULT: '#c0391a', soft: '#fde8e2', border: '#f5c0b0' },
    warn:    { DEFAULT: '#a07010', soft: '#fef3d0', border: '#f0d070' },
    neutral: { DEFAULT: '#5a6682', soft: '#e8ecf5', border: '#c4cad8' },
},
```

### Mapping sémantique des badges

| Status proto | Variant Blade `<x-badge variant>` | Couleurs Tailwind |
|---|---|---|
| `ok`, `validated` | `ok` ou `validated` | `bg-ok-soft text-ok` |
| `error`, `rejected` | `error` ou `rejected` | `bg-danger-soft text-danger` |
| `pending` | `pending` | `bg-neutral-soft text-neutral border border-neutral-border` |
| `processing` | `processing` | `bg-accent-soft-strong text-warn animate-pulse-amber` |
| `non-vol` | `nonvol` | `bg-neutral-soft text-neutral border border-neutral-border` |
| `already-processed` | `already` | `bg-accent-soft-strong text-warn` |
| `amber` (générique) | `amber` | `bg-accent-soft-strong text-warn` |

### Polices

```js
fontFamily: {
    sans: ['DM Sans', ...defaultTheme.fontFamily.sans],
    mono: ['DM Mono', ...defaultTheme.fontFamily.mono],
},
```

Lien fonts.bunny.net dans les 2 layouts (`app.blade.php`, `guest.blade.php`) :

```html
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600|dm-mono:400,500&display=swap" rel="stylesheet" />
```

### Animations

```js
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
```

## 5. CSS (`resources/css/app.css`)

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

## 6. Layouts

### `layouts/app.blade.php`

Shell principal avec sidebar dark à gauche et main scrollable à droite.

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

Le `h-screen overflow-hidden` sur body + `overflow-y-auto` sur main reproduit le comportement du proto (sidebar fixe, main scrolle).

### `layouts/sidebar.blade.php`

Refonte complète, largeur fixe 200px. Items nav + badge "processing count" sur Imports + dropdown user Alpine en bas.

```blade
@php
    $active = request()->route()?->getName() ?? '';

    // SVG icônes (copiées du proto, version simplifiée)
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

### `layouts/guest.blade.php`

Shell minimal pour les pages auth (login, register), centered card sur fond `#e4e8f0`. La page `/login` elle-même reste actuellement Breeze — sera refaite à l'étape 2.

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

### `layouts/navigation.blade.php`

**Supprimé.** Vérification `grep -r "layouts.navigation"` confirme zéro référence dans le reste du repo (ancien layout Breeze top-nav, jamais inclus depuis le passage en sidebar).

## 7. Composants Blade

### Buttons re-stylés

#### `components/primary-button.blade.php`
```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-accent text-ink-primary text-xs font-medium hover:bg-accent-hover focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

#### `components/secondary-button.blade.php` (ghost)
```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition']) }}>
    {{ $slot }}
</button>
```

#### `components/danger-button.blade.php` (red soft)
```blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-danger-soft border border-danger-border text-danger text-xs font-medium hover:bg-danger-border focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

#### `components/ok-button.blade.php` (NOUVEAU — green soft)
```blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded bg-ok-soft border border-ok-border text-ok text-xs font-medium hover:bg-ok-border focus:outline-none focus:ring-2 focus:ring-ok focus:ring-offset-2 transition']) }}>
    {{ $slot }}
</button>
```

### `components/badge.blade.php`

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

Variantes acceptées : `ok`, `error`, `pending`, `processing`, `nonvol`, `already`, `amber`, `validated`, `rejected`. Toute autre valeur fallback sur le style `pending`.

### `components/card.blade.php`

```blade
@props(['variant' => 'default'])

@php
    $bg = $variant === 'elevated' ? 'bg-app-elevated' : 'bg-app-card';
@endphp

<div {{ $attributes->merge(['class' => "$bg border border-app-border rounded-lg"]) }}>
    {{ $slot }}
</div>
```

### `components/section-label.blade.php`

```blade
<div {{ $attributes->merge(['class' => 'text-[10px] font-semibold uppercase tracking-[0.1em] text-ink-muted']) }}>
    {{ $slot }}
</div>
```

## 8. Plan de tests

Cette étape ne touche **aucune logique métier** — pas de test Pest à écrire. Validation :

1. **Build CSS** : `npm run build` (ou `npm run dev`) doit compiler sans erreur Tailwind.
2. **Suite Pest existante** : `php artisan test` doit rester verte (les `assertSee()` continuent à matcher du texte présent dans les vues, qui n'a pas changé).
3. **Smoke manuel** :
   - Charger `http://127.0.0.1:8000/` (ancienne page) → la sidebar a le nouveau look amber + dark, le main reste avec l'ancien style des pages internes.
   - Charger `/login` (déconnecté) → guest layout actif, fond `#e4e8f0`, formulaire Breeze visible mais pas encore re-stylé.
   - Cliquer sur l'avatar utilisateur en bas de la sidebar → dropdown s'ouvre, lien "Profil" + "Déconnexion" visibles.
   - Vérifier que le badge processing count apparaît sur Imports si une import est en cours (sinon absent).
4. **Régression visuelle** : noter que les pages internes (`/machines`, `/flights/*`, etc.) ont un look inconsistant temporaire (sidebar nouveau / main ancien) — c'est attendu et sera résolu aux étapes suivantes.

## 9. Hors-scope (étapes ultérieures)

| Étape | Pages |
|---|---|
| 2 | `/login` (page formulaire) |
| 3 | `/machines` (la plus importante) |
| 4 | `/machines/{HcId}` |
| 5 | `/flights/{id}` + sous-pages (conservees / isolees / non-vol) |
| 6 | `/upload` + `/imports` |
| 7 | `/dashboards` (avec filtre actives ≥ 3 vols / 30j discuté précédemment) |
| 8 | `/profile` |

Composants spécifiques créés à leurs étapes : `<x-panne-row>`, `<x-counter-pill>`, `<x-status-bar>`.

Mode dark : prototype conservé dans `frontv2/nh90-caiman/project/nh-project-dark.html`. Aucune implémentation prévue.
