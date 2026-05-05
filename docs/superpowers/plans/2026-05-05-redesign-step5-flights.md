# Redesign Étape 5 — `/flights/*` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre les 4 sous-pages `/flights/*` (show, pannes-conservees, pannes-isolees, non-vol) selon le proto : breadcrumbs ghost, headers DSN+#num avec accent ambre, card meta, panne rows en card list (border-left coloré par statut), alert amber sur isolées, livewire hybride (search + panneau détail conservés ; tableau → card list ; modal manquante → form en bas). Pannes isolées en read-only avec écart temporel.

**Architecture:** Pas de migration. 4 vues refondues + 1 livewire view restructuré + 1 nouveau composant Blade `<x-panne-row>`. Si `FlightController@show` ne fournit pas déjà `$counts`, on l'ajoute (1 méthode contrôleur).

**Tech Stack:** Laravel 12, Blade, Livewire, Alpine.js, Tailwind CSS, Pest 3, Playwright MCP.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step5-flights-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step5-flights-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step5-flights.md` (ce fichier)

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??`.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step5-flights-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step5-flights.md
git commit -m "docs: add spec and plan for redesign step 5 (/flights/*)"
```

---

## Task 2: Créer `<x-panne-row>` (NEW)

**Files:**
- Create: `resources/views/components/panne-row.blade.php`

- [ ] **Step 1: Créer le fichier avec ce contenu**

```blade
@props(['status' => 'pending', 'readonly' => false])

@php
    $borderClass = match ($status) {
        'validated' => 'border-l-ok',
        'rejected'  => 'border-l-danger',
        'readonly'  => 'border-l-app-border',
        default     => 'border-l-accent',
    };
    $opacityClass = $status === 'rejected' ? 'opacity-60' : '';
@endphp

<div class="px-5 py-4 bg-app-card border border-app-border rounded-md border-l-[3px] {{ $borderClass }} {{ $opacityClass }} flex items-start gap-4">
    <div class="flex-1 min-w-0">
        {{ $slot }}
    </div>
    @isset($actions)
        <div class="w-[200px] shrink-0 flex flex-col gap-2 items-end">
            {{ $actions }}
        </div>
    @endisset
</div>
```

- [ ] **Step 2: Build Vite**

```bash
npm run build
```

Expected: succès, classes `border-l-ok`, `border-l-danger`, `border-l-app-border`, `border-l-accent` reconnues (Tailwind les génère automatiquement à partir des couleurs étendues).

- [ ] **Step 3: Commit**

```bash
git add resources/views/components/panne-row.blade.php
git commit -m "feat(components): add x-panne-row with status-coded border-left"
```

---

## Task 3: Refondre `flights/show.blade.php`

**Files:**
- Modify: `resources/views/flights/show.blade.php` (replace entirely)

- [ ] **Step 1: Vérifier que le contrôleur fournit `$counts`**

```bash
grep -A 30 "function show" /root/camille2/nh-web/app/Http/Controllers/FlightController.php | head -40
```

Si `$counts` (avec clés `conservees` et `isolees`) n'est PAS dans le `compact(...)` final → ajouter au contrôleur :

```php
$counts = [
    'conservees' => $flight->technicalEvents()->where('status', 'conservee')->count(),
    'isolees'    => $flight->technicalEvents()->where('status', 'isolee')->count(),
];
```

Et changer la dernière ligne pour inclure `'counts'` dans `compact()`. Si déjà présent, passer.

- [ ] **Step 2: Replace the entire content of `resources/views/flights/show.blade.php` with**

```blade
<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.show', $flight->machine->hc_id) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← {{ $flight->machine->hc_id }}
        </a>
    </div>

    {{-- Header --}}
    <div class="flex items-start justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Vol · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
            <h1 class="text-[20px] font-semibold text-ink-primary">
                {{ $flight->dsn }} <span class="font-mono text-accent">#{{ $flight->num }}</span>
            </h1>
        </div>
        <a href="{{ route('flights.xml', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <path d="M6.5 1v8M3.5 6.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                <path d="M1 10v1.5a.5.5 0 00.5.5h10a.5.5 0 00.5-.5V10" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
            Télécharger XML épuré
        </a>
    </div>

    {{-- Meta strip --}}
    <x-card class="px-5 py-4 mb-5">
        <div class="flex gap-8 flex-wrap">
            <div>
                <x-section-label class="mb-1">Appareil</x-section-label>
                <div class="font-mono text-sm text-accent">{{ $flight->machine->hc_id }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Date</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ $flight->start_datetime->format('d/m/Y') }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Durée</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ number_format($flight->flight_hours, 1) }}h</div>
            </div>
            <div>
                <x-section-label class="mb-1">Carburant</x-section-label>
                <div class="font-mono text-sm text-ink-primary">{{ $flight->consumed_fuel ?? '—' }}{{ $flight->consumed_fuel ? ' kg' : '' }}</div>
            </div>
            <div>
                <x-section-label class="mb-1">Type</x-section-label>
                <div class="text-sm text-ink-primary">Normal</div>
            </div>
        </div>
    </x-card>

    {{-- 2 cards Pannes --}}
    <div class="grid grid-cols-2 gap-4">
        <a href="{{ route('flights.pannes-conservees', $flight) }}"
           class="block bg-app-card border border-app-border rounded-lg p-5 hover:border-accent transition group">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm text-ink-primary">Pannes conservées</div>
                <span class="font-mono text-[22px] font-medium text-accent">{{ $counts['conservees'] }}</span>
            </div>
            <div class="text-xs text-ink-muted leading-relaxed">
                Pannes retenues par la pipeline dans la fenêtre de 48h. Validation technicien requise.
            </div>
            <div class="mt-3 text-xs text-accent group-hover:text-accent-pressed">Ouvrir le tableau →</div>
        </a>
        <a href="{{ route('flights.pannes-isolees', $flight) }}"
           class="block bg-app-card border border-app-border rounded-lg p-5 hover:border-ink-secondary transition group">
            <div class="flex items-center justify-between mb-2">
                <div class="font-semibold text-sm text-ink-primary">Pannes isolées</div>
                <span class="font-mono text-[22px] font-medium text-ink-secondary">{{ $counts['isolees'] }}</span>
            </div>
            <div class="text-xs text-ink-muted leading-relaxed">
                Pannes filtrées (date hors fenêtre 48h). Information uniquement.
            </div>
            <div class="mt-3 text-xs text-ink-secondary">Ouvrir le tableau →</div>
        </a>
    </div>
</x-app-layout>
```

- [ ] **Step 3: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants.

- [ ] **Step 4: Commit**

```bash
git add resources/views/flights/show.blade.php
# si controller modifié : git add app/Http/Controllers/FlightController.php
git commit -m "feat(flights): redesign /flights/{id} with header, meta strip and 2 panne cards"
```

---

## Task 4: Refondre `flights/pannes-isolees.blade.php` (read-only)

**Files:**
- Modify: `resources/views/flights/pannes-isolees.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/flights/pannes-isolees.blade.php` with**

```blade
<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('flights.show', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Vol {{ $flight->dsn }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-4">
        <x-section-label class="mb-1">{{ $flight->machine->hc_id }} · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            Pannes isolées <span class="font-mono text-ink-secondary">{{ $pannes->count() }}</span>
        </h1>
    </div>

    {{-- Alert amber --}}
    <div class="mb-4 px-4 py-3 bg-accent-soft border border-accent-soft-border rounded-md text-sm text-warn">
        <div class="font-semibold mb-0.5">Pannes hors fenêtre 48h</div>
        Ces pannes ont été détectées mais datent de plus de 48h avant/après la date du vol. Information uniquement (pas de validation requise).
    </div>

    {{-- Card list read-only --}}
    <div class="space-y-2">
        @forelse ($pannes as $p)
            @php
                $teDesc = data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') ?? $p->technical_event_id;
                $sysDesc = data_get($p->details, 'SystemDescription') ?? '—';
                $typeDesc = data_get($p->details, 'TypeDescription') ?? '—';
                $failureCode = data_get($p->details, 'FailureCode') ?? '—';
                $ecart = data_get($p->details, 'ecart') ?? data_get($p->details, 'ecart_vol') ?? '—';
                $raise = $p->raise_datetime?->format('d/m/Y H:i') ?? '—';
            @endphp
            <div class="px-5 py-4 bg-app-card border border-app-border rounded-md border-l-[3px] border-l-app-border">
                <div class="font-mono text-[10px] text-ink-muted mb-1.5">
                    {{ $sysDesc }} · Fault Code {{ $failureCode }} · ×{{ $p->nombre_occurrences }} occurrence{{ $p->nombre_occurrences > 1 ? 's' : '' }}
                </div>
                <div class="text-[13px] font-medium text-ink-primary">{{ $teDesc }}</div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-ink-muted mt-1.5">
                    <span>Système : {{ $sysDesc }} | Type : {{ $typeDesc }}</span>
                    <span class="text-warn font-medium">Hors fenêtre {{ $ecart }}</span>
                    <span class="font-mono">Détectée le {{ $raise }}</span>
                </div>
            </div>
        @empty
            <x-card class="p-12 text-center text-ink-muted text-sm">
                Aucune panne isolée pour ce vol.
            </x-card>
        @endforelse
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
git add resources/views/flights/pannes-isolees.blade.php
git commit -m "feat(flights): redesign pannes-isolees read-only with alert and ecart info"
```

---

## Task 5: Refondre `flights/non-vol.blade.php`

**Files:**
- Modify: `resources/views/flights/non-vol.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/flights/non-vol.blade.php` with**

```blade
<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('machines.show', $flight->machine->hc_id) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← {{ $flight->machine->hc_id }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-6">
        <x-section-label class="mb-1">Non-Vol · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            {{ $flight->dsn }} <span class="font-mono text-ink-secondary">#{{ $flight->num }}</span>
        </h1>
    </div>

    {{-- 2 cards --}}
    <div class="grid grid-cols-2 gap-4 mb-6">
        <x-card class="p-5">
            <x-section-label class="mb-3">Motif de classification</x-section-label>
            <div class="space-y-2">
                <div class="flex items-start gap-3 px-3 py-2.5 bg-app-bg rounded">
                    <div class="w-2 h-2 bg-accent rounded-full shrink-0 mt-1.5"></div>
                    <div>
                        <div class="text-sm text-ink-primary font-medium">Type détecté : {{ $flight->flight_type }}</div>
                        <div class="text-[11px] text-ink-muted font-mono mt-1">Pas de phase de vol active identifiée par la pipeline.</div>
                    </div>
                </div>
            </div>
        </x-card>
        <x-card class="p-5">
            <x-section-label class="mb-3">Métadonnées</x-section-label>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between"><dt class="text-ink-muted">Appareil</dt><dd class="font-mono text-accent">{{ $flight->machine->hc_id }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">DSN</dt><dd class="font-mono text-ink-secondary">{{ $flight->dsn }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">Date</dt><dd class="font-mono text-ink-primary">{{ $flight->start_datetime->format('d/m/Y') }}</dd></div>
                <div class="flex justify-between"><dt class="text-ink-muted">Statut</dt><dd><x-badge variant="nonvol">Non-Vol</x-badge></dd></div>
            </dl>
        </x-card>
    </div>

    {{-- Action ou alert --}}
    @if ($flight->flagged_as_error)
        <div class="px-4 py-3 bg-danger-soft border border-danger-border rounded-md text-sm text-danger">
            <div class="font-semibold mb-0.5">Signalement enregistré</div>
            Ce vol a été marqué comme mal classifié par <strong>{{ $flight->flaggedBy?->email ?? 'utilisateur' }}</strong> le {{ $flight->flagged_at?->format('d/m/Y H:i') }}. Page en lecture seule.
        </div>
    @else
        <div class="flex justify-end">
            <form method="POST" action="{{ route('flights.flag-as-error', $flight) }}">
                @csrf
                <x-danger-button>
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M6.5 2v5M6.5 9.5v1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        <circle cx="6.5" cy="6.5" r="5.5" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    Signaler comme erreur de classification
                </x-danger-button>
            </form>
        </div>
    @endif
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
git add resources/views/flights/non-vol.blade.php
git commit -m "feat(flights): redesign non-vol with motif card, metadata and danger CTA"
```

---

## Task 6: Refondre `flights/pannes-conservees.blade.php` (wrapper)

**Files:**
- Modify: `resources/views/flights/pannes-conservees.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content of `resources/views/flights/pannes-conservees.blade.php` with**

```blade
<x-app-layout>
    {{-- Breadcrumb --}}
    <div class="mb-2">
        <a href="{{ route('flights.show', $flight) }}"
           class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
            ← Vol {{ $flight->dsn }}
        </a>
    </div>

    {{-- Header --}}
    <div class="mb-5">
        <x-section-label class="mb-1">{{ $flight->machine->hc_id }} · {{ $flight->start_datetime->format('d/m/Y') }}</x-section-label>
        <h1 class="text-[20px] font-semibold text-ink-primary">
            Pannes conservées <span class="font-mono text-accent">{{ $flight->technicalEvents()->where('status', 'conservee')->count() }}</span>
        </h1>
    </div>

    <livewire:pannes-conservees-table :flight="$flight" />
</x-app-layout>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants. Note : la livewire view est encore l'ancien design — la page sera fonctionnelle mais le rendu est mid-transition. Sera fini à Task 7.

- [ ] **Step 3: Commit**

```bash
git add resources/views/flights/pannes-conservees.blade.php
git commit -m "feat(flights): redesign pannes-conservees wrapper with breadcrumb and header"
```

---

## Task 7: Refondre `livewire/pannes-conservees-table.blade.php` (la grosse)

**Files:**
- Modify: `resources/views/livewire/pannes-conservees-table.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<div class="space-y-4">
    {{-- Top bar : search + count badges --}}
    <div class="flex items-center justify-between gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (description, code, système…)"
               class="w-96 bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />
        <div class="flex items-center gap-2">
            @php
                $allPannes = $flight->technicalEvents()->where('status', 'conservee')->get();
                $countPending   = $allPannes->where('validation_status', 'pending')->count();
                $countValidated = $allPannes->where('validation_status', 'validated')->count();
                $countRejected  = $allPannes->where('validation_status', 'rejected')->count();
            @endphp
            <x-badge variant="pending">{{ $countPending }} pending</x-badge>
            <x-badge variant="ok">{{ $countValidated }} validées</x-badge>
            <x-badge variant="error">{{ $countRejected }} rejetées</x-badge>
        </div>
    </div>

    {{-- Card list pannes --}}
    <div class="space-y-2">
        @forelse ($pannes as $p)
            @php
                $status = $p->validation_status;
                $statusBadgeVariant = match ($status) {
                    'validated' => 'ok',
                    'rejected'  => 'error',
                    default     => 'pending',
                };
                $statusLabel = match ($status) {
                    'validated' => 'Validée',
                    'rejected'  => 'Rejetée',
                    default     => 'Pending',
                };
                $teDesc = data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') ?? $p->technical_event_id;
                $sysDesc = data_get($p->details, 'SystemDescription') ?? '—';
                $typeDesc = data_get($p->details, 'TypeDescription') ?? '—';
                $failureCode = data_get($p->details, 'FailureCode') ?? '—';
                $isValid = $status === 'validated';
                $isReject = $status === 'rejected';
                $actionValid  = $isValid  ? 'pending' : 'validated';
                $actionReject = $isReject ? 'pending' : 'rejected';
            @endphp
            <x-panne-row :status="$status" wire:key="te-{{ $p->id }}">
                <div class="flex items-center gap-2 mb-1.5">
                    <x-badge :variant="$statusBadgeVariant">{{ $statusLabel }}</x-badge>
                    <span class="font-mono text-[10px] text-ink-muted">
                        {{ $sysDesc }} · Fault Code {{ $failureCode }} · ×{{ $p->nombre_occurrences }} occurrence{{ $p->nombre_occurrences > 1 ? 's' : '' }}
                    </span>
                </div>
                <button wire:click="openDetail({{ $p->id }})"
                        class="text-[13px] font-medium text-ink-primary hover:text-accent-pressed text-left transition-colors">
                    {{ $teDesc }}
                </button>
                <div class="text-[11px] text-ink-muted mt-1">
                    Système : {{ $sysDesc }} | Type : {{ $typeDesc }}
                </div>

                <x-slot:actions>
                    <div class="flex gap-1.5">
                        @if ($isValid)
                            <x-ok-button wire:click="setValidation({{ $p->id }}, '{{ $actionValid }}')">✓ Validée</x-ok-button>
                        @else
                            <x-secondary-button wire:click="setValidation({{ $p->id }}, '{{ $actionValid }}')">✓ Valider</x-secondary-button>
                        @endif
                        @if ($isReject)
                            <x-danger-button type="button" wire:click="setValidation({{ $p->id }}, '{{ $actionReject }}')">✕ Rejetée</x-danger-button>
                        @else
                            <x-secondary-button wire:click="setValidation({{ $p->id }}, '{{ $actionReject }}')">✕ Rejeter</x-secondary-button>
                        @endif
                    </div>
                    @if (auth()->user()?->isAdmin() && $p->validated_by)
                        <div class="text-[10px] text-ink-muted text-right leading-snug">
                            par {{ $p->validator?->name ?? 'inconnu' }}<br>
                            {{ $p->validated_at?->format('d/m/Y H:i') }}
                        </div>
                    @endif
                </x-slot:actions>
            </x-panne-row>
        @empty
            <x-card class="p-12 text-center text-ink-muted text-sm">
                Aucune panne conservée pour ce vol.
            </x-card>
        @endforelse
    </div>

    {{-- Form panne manquante (collapsible Alpine) --}}
    <x-card class="p-5" x-data="{ open: false }">
        <div class="flex items-center justify-between">
            <div class="font-semibold text-sm text-ink-primary">Signaler une panne manquante</div>
            <button @click="open = !open" type="button"
                    class="text-xs text-accent hover:text-accent-pressed font-medium transition-colors">
                <span x-show="!open">+ Ouvrir le formulaire</span>
                <span x-show="open" x-cloak>− Fermer</span>
            </button>
        </div>
        <div x-show="open" x-cloak class="mt-4 space-y-3">
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted mb-1.5">Failure Code *</label>
                <input type="text" wire:model="newFailureCode" placeholder="ex: 46-830"
                       class="w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />
                @error('newFailureCode')
                    <p class="text-[11px] text-danger mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="block text-[11px] font-semibold uppercase tracking-[0.06em] text-ink-muted mb-1.5">
                    Description <span class="text-ink-muted lowercase font-normal normal-case">(facultatif)</span>
                </label>
                <textarea wire:model="newDescription" rows="3" placeholder="Description de la panne…"
                          class="w-full bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition resize-y"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <x-secondary-button @click="open = false">Annuler</x-secondary-button>
                <x-primary-button wire:click="submitMissingPanne">Signaler la panne</x-primary-button>
            </div>
        </div>
    </x-card>

    {{-- Side detail panel --}}
    @if ($selected)
        <div wire:click.self="closeDetail"
             class="fixed inset-0 bg-ink-primary/50 backdrop-blur-sm z-40"
             x-data x-on:keydown.escape.window="$wire.closeDetail()"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-[400px] bg-app-card border-l border-app-border shadow-2xl z-50 p-6 overflow-y-auto">
            <div class="flex items-start justify-between mb-4">
                <div>
                    <x-section-label class="mb-0.5">Détail panne</x-section-label>
                    <div class="text-sm font-medium text-ink-primary">{{ data_get($selected->details, 'TechnicalEventDescription') ?? $selected->technical_event_id }}</div>
                </div>
                <button wire:click="closeDetail" class="text-ink-muted hover:text-ink-primary text-lg leading-none transition-colors">×</button>
            </div>
            <dl class="space-y-2.5 text-sm">
                @foreach ($selected->details as $k => $v)
                    <div>
                        <dt class="text-[10px] font-semibold uppercase tracking-[0.06em] text-ink-muted">{{ $k }}</dt>
                        <dd class="text-ink-primary text-[13px] break-words mt-0.5">{{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    @endif

    {{-- Liste pannes manquantes signalées --}}
    @if ($missingPannes->isNotEmpty())
        <x-card class="p-5">
            <x-section-label class="mb-3">Pannes manquantes signalées ({{ $missingPannes->count() }})</x-section-label>
            <ul class="divide-y divide-app-border-soft">
                @foreach ($missingPannes as $m)
                    <li class="py-3 flex items-start justify-between gap-3" wire:key="missing-{{ $m->id }}">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs bg-accent-soft-strong text-warn px-2 py-0.5 rounded">{{ $m->failure_code }}</span>
                                @if ($m->description)
                                    <span class="text-[13px] text-ink-primary">{{ $m->description }}</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-ink-muted mt-1">
                                Signalée par {{ $m->reporter->email ?? 'inconnu' }}
                                · {{ $m->reported_at->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        @if ($m->reported_by === auth()->id())
                            <button wire:click="deleteMissing({{ $m->id }})"
                                    class="text-[11px] text-danger hover:underline transition-colors self-start">
                                Supprimer
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif
</div>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert. Si `PannesConserveesTableTest` échoue à cause d'un texte (ex : ancienne string "Rechercher (ID, description...)"), adapter le test (changer la string attendue).

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/pannes-conservees-table.blade.php
# si test ajusté : git add tests/Feature/PannesConserveesTableTest.php
git commit -m "feat(livewire): redesign pannes-conservees-table with card list and inline form"
```

---

## Task 8: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests**

```bash
npm run build
php artisan test
```

Expected: tout vert.

- [ ] **Step 2: Trouver un vol pour les screenshots**

```bash
php artisan tinker --execute="\$f = \App\Models\Flight::where('is_non_vol', false)->latest('start_datetime')->first(); echo \$f ? '/flights/' . \$f->id : 'none';"
```

Note l'URL et l'utilise dans les étapes suivantes.

- [ ] **Step 3: Screenshot `/flights/{id}` (vol normal)**

Naviguer vers l'URL trouvée. Vérifier :
- Breadcrumb "← {HcId}" ghost
- Header section-label "Vol · {date}" + h1 "{DSN} #{num}" (num en font-mono accent)
- Lien "Télécharger XML épuré" en haut à droite (ghost button)
- Card meta strip : 5 champs (Appareil mono accent, Date mono, Durée mono "X.Xh", Carburant, Type "Normal")
- 2 cards "Pannes conservées" (count amber) / "Pannes isolées" (count gris) avec descriptions

- [ ] **Step 4: Screenshot `/flights/{id}/pannes-conservees`**

Cliquer la card "Pannes conservées" pour navigate. Vérifier :
- Breadcrumb "← Vol {DSN}"
- Header section-label HcId+date + h1 "Pannes conservées N" (count amber mono)
- Search input avec border accent au focus
- Badges count à droite (pending/validées/rejetées)
- Card list de pannes avec border-left coloré (amber pending, vert validated, rouge rejected)
- Boutons "Valider" / "Rejeter" ghost à droite (ou "Validée" ok-button / "Rejetée" danger-button si déjà set)
- Card "Signaler une panne manquante" en bas, repliable

Cliquer "+ Ouvrir le formulaire" → form apparaît avec 2 inputs + bouton primary "Signaler".

Cliquer la description d'une panne → side panel s'ouvre avec détails.

- [ ] **Step 5: Screenshot `/flights/{id}/pannes-isolees`**

Naviguer (peut être 0 pannes — vérifier empty state). Si pannes présentes :
- Breadcrumb + header similaires
- Alert amber "Pannes hors fenêtre 48h" en haut
- Card list read-only (border-left gris, pas de boutons)
- Chaque ligne : description + "Hors fenêtre Xh" en warn + "Détectée le {date}" en mono

- [ ] **Step 6: Screenshot `/flights/{id}/non-vol`** (si on a un non-vol)

```bash
php artisan tinker --execute="\$f = \App\Models\Flight::where('is_non_vol', true)->where('flagged_as_error', false)->first(); echo \$f ? '/flights/' . \$f->id . '/non-vol' : 'none';"
```

Si trouvé, naviguer. Vérifier :
- Breadcrumb + header "Non-Vol · {date}" + DSN+#num
- 2 cards : "Motif de classification" / "Métadonnées"
- Bouton "Signaler comme erreur de classification" en bas droite (danger-button)

- [ ] **Step 7: Vérifier console JS**

Pas d'erreur dans `browser_console_messages` level=error.

---

## Notes / pièges connus

- **`<x-panne-row>` slot `actions`** : utilise la syntaxe `<x-slot:actions>` (Laravel 9+). Vérifier que `@isset($actions)` est bien `@isset($actions)` (avec le name slot). Si problème, fallback : utiliser `{{ $actions ?? '' }}` après `@isset` check.
- **Search Tailwind compile** : les classes utilisées dans le livewire view (search input, focus rings) doivent être détectées par le glob `content` dans `tailwind.config.js`. Le glob `'./resources/views/**/*.blade.php'` les couvre (livewire vue est dans `resources/views/livewire/`).
- **`PannesConserveesTableTest`** : test feature existant. Si il vérifie du HTML précis ou un texte spécifique de l'ancienne UI ("Rechercher (ID, description...)" ou les boutons cercle), l'ajuster — c'est l'attendu qui change, pas le comportement.
- **`$flight->technicalEvents()` vs `$flight->technicalEvents`** : la propriété (sans parenthèses) déclenche un `lazyLoad` ; la méthode permet de chaîner `where()`. Dans le livewire view, on utilise la méthode pour les counts par status.
- **Border-left dynamic colors** : Tailwind génère automatiquement `border-l-ok`, `border-l-danger`, `border-l-app-border`, `border-l-accent` à partir des couleurs étendues dans `tailwind.config.js` (étape 1 Foundation). Vérifier au build que toutes ces classes compilent.
- **`x-cloak`** : la règle CSS est dans `app.css` étape 1 — `[x-cloak] { display: none !important; }`. Garantit que les éléments Alpine `x-show="open"` ne flashent pas avant init.
- **Side panel z-index** : overlay z-40, panneau z-50 — fixed positioning. Échappe au shell main (sidebar reste visible derrière l'overlay backdrop-blur).
- **Empty states** : tous les `@empty` retournent un `<x-card class="p-12 text-center text-ink-muted">`.
- **`$showMissingModal` du livewire PHP** : variable existante non utilisée dans la vue refactorée (form Alpine local). On la laisse côté composant PHP — elle ne casse rien.
