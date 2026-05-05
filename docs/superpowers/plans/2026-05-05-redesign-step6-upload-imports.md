# Redesign Étape 6 — `/upload` + `/imports` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refondre `/upload` (drop zone amber + liste fichiers staged) et `/imports` (polling indicator + tabs simplifiés Tous/En attente + tableau 8 colonnes badges color-coded). Aucune modification PHP.

**Architecture:** 4 fichiers Blade modifiés (2 vues + 2 partials Livewire). Aucun nouveau composant. Aucun changement contrôleur ou logique Livewire.

**Tech Stack:** Laravel 12, Blade, Livewire (existant), Tailwind CSS, Alpine.js, Pest 3, Playwright MCP.

**Spec source:** `docs/superpowers/specs/2026-05-05-redesign-step6-upload-imports-design.md`

---

## Task 1: Commit du spec et du plan

**Files:**
- Existant : `docs/superpowers/specs/2026-05-05-redesign-step6-upload-imports-design.md`
- Existant : `docs/superpowers/plans/2026-05-05-redesign-step6-upload-imports.md`

- [ ] **Step 1: Vérifier les 2 fichiers untracked**

```bash
cd /root/camille2/nh-web
git status -s docs/superpowers/
```

Expected: 2 fichiers `??`.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/specs/2026-05-05-redesign-step6-upload-imports-design.md \
        docs/superpowers/plans/2026-05-05-redesign-step6-upload-imports.md
git commit -m "docs: add spec and plan for redesign step 6 (/upload + /imports)"
```

---

## Task 2: Refondre `upload.blade.php`

**Files:**
- Modify: `resources/views/upload.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Pipeline</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Upload XML</h1>
    </div>
    <livewire:xml-uploader />
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
git add resources/views/upload.blade.php
git commit -m "feat(upload): redesign /upload header with section-label"
```

---

## Task 3: Refondre `livewire/xml-uploader.blade.php`

**Files:**
- Modify: `resources/views/livewire/xml-uploader.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<div>
    {{-- Drop zone --}}
    <label for="stagedFilesInput"
           class="block bg-app-card border-2 border-dashed border-app-border rounded-xl p-12 text-center cursor-pointer hover:border-accent hover:bg-accent-soft transition-colors">
        <div class="w-12 h-12 mx-auto bg-app-bg rounded-lg flex items-center justify-center mb-4">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                <path d="M12 3v14M6 9l6-6 6 6" stroke="#f5b731" stroke-width="1.8" stroke-linecap="round"/>
                <path d="M3 19v1.5A1.5 1.5 0 004.5 22h15a1.5 1.5 0 001.5-1.5V19" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" class="text-ink-muted"/>
            </svg>
        </div>
        <p class="text-[15px] font-medium text-ink-primary mb-1">Glisser-déposer vos fichiers XML</p>
        <p class="text-sm text-ink-muted mb-4">ou cliquer pour sélectionner — jusqu'à 50 Mo par fichier</p>
        <span class="inline-block bg-accent-soft border border-accent-soft-border text-warn px-3.5 py-1 rounded text-xs font-medium">
            Parcourir…
        </span>
        <input type="file" id="stagedFilesInput" multiple accept=".xml,application/xml,text/xml"
               wire:model="stagedFiles" class="hidden" />
    </label>

    {{-- Upload progress --}}
    <div wire:loading wire:target="stagedFiles" class="mt-4 text-sm text-ink-secondary">
        Upload en cours…
        <div class="h-2 mt-2 bg-app-border-soft rounded overflow-hidden">
            <div class="h-full bg-accent animate-pulse" style="width:100%"></div>
        </div>
    </div>

    @error('stagedFiles.*')
        <p class="mt-3 text-sm text-danger">{{ $message }}</p>
    @enderror

    {{-- Staged files list --}}
    @if (count($stagedFiles) > 0)
        <div class="mt-6">
            <div class="flex items-center justify-between mb-3">
                <div class="text-sm font-semibold text-ink-primary">{{ count($stagedFiles) }} fichier(s) en attente</div>
                <x-primary-button wire:click="submit" wire:loading.attr="disabled" wire:target="submit">
                    <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                        <path d="M6.5 1L12 6.5 6.5 12" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                        <path d="M1 6.5h11" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
                    </svg>
                    Traiter {{ count($stagedFiles) }} fichier(s)
                </x-primary-button>
            </div>
            <x-card class="overflow-hidden">
                <ul class="divide-y divide-app-border-soft">
                    @foreach ($stagedFiles as $i => $file)
                        <li class="flex items-center gap-3 px-4 py-2.5" wire:key="staged-{{ $i }}">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" class="text-ink-muted shrink-0">
                                <rect x="1" y="1" width="9" height="12" rx="1.5" stroke="currentColor" stroke-width="1.2"/>
                                <path d="M4 4h5M4 7h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                            <span class="font-mono text-xs text-ink-primary flex-1 truncate">{{ $file->getClientOriginalName() }}</span>
                            <span class="text-[11px] text-ink-muted">{{ number_format($file->getSize() / 1024, 0) }} KB</span>
                            <button type="button" wire:click="removeStaged({{ $i }})"
                                    class="text-ink-muted hover:text-danger transition-colors text-sm leading-none">×</button>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    @endif
</div>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert, 59 tests passants. Si `XmlUploaderTest` échoue à cause d'un texte modifié (ex : "fichier(s) en staging" → "fichier(s) en attente", "Retirer" → "×"), adapter le test.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/xml-uploader.blade.php
# si test ajusté : git add tests/Feature/XmlUploaderTest.php
git commit -m "feat(uploader): redesign drop zone with amber accents and staged files card"
```

---

## Task 4: Refondre `imports.blade.php`

**Files:**
- Modify: `resources/views/imports.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<x-app-layout>
    <div class="flex items-end justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Suivi</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Imports</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                <div class="w-1.5 h-1.5 bg-accent rounded-full animate-pulse-amber"></div>
                Polling 2s
            </div>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
                + Upload
            </a>
        </div>
    </div>
    <livewire:imports-tracker />
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
git add resources/views/imports.blade.php
git commit -m "feat(imports): redesign /imports header with polling indicator and upload shortcut"
```

---

## Task 5: Refondre `livewire/imports-tracker.blade.php`

**Files:**
- Modify: `resources/views/livewire/imports-tracker.blade.php` (replace entirely)

- [ ] **Step 1: Replace the entire content with**

```blade
<div wire:poll.2s>
    @php
        $filters = ['all' => 'Tous', 'pending' => 'En attente'];
    @endphp

    {{-- Tabs filter (simplifiés : Tous + En attente uniquement) --}}
    <div class="border-b border-app-border mb-0">
        <nav class="flex gap-1">
            @foreach ($filters as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        @class([
                            'px-4 py-2.5 text-[13px] font-medium border-b-2 -mb-px transition-colors',
                            'text-accent-pressed border-accent' => $filter === $key,
                            'text-ink-secondary border-transparent hover:text-ink-primary' => $filter !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Tableau --}}
    <x-card class="rounded-t-none border-t-0 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Fichier</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Date upload</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Statut</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">HcId</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">DSN</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Pannes</th>
                        <th class="text-right px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">H. vol</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Lien</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($imports as $import)
                        @php
                            $statusBadgeVariant = match ($import->status) {
                                'ok' => 'ok',
                                'processing' => 'processing',
                                'pending' => 'pending',
                                'non_vol' => 'nonvol',
                                'already_processed' => 'already',
                                'error' => 'error',
                                default => 'pending',
                            };
                            $statusLabel = match ($import->status) {
                                'pending' => 'Pending',
                                'processing' => 'Processing',
                                'ok' => 'OK',
                                'already_processed' => 'Déjà traité',
                                'non_vol' => 'Non-Vol',
                                'error' => 'Erreur',
                                default => $import->status,
                            };
                            $isProcessing = $import->status === 'processing';
                        @endphp
                        <tr wire:key="import-{{ $import->id }}"
                            @class([
                                'border-b border-app-border-soft',
                                'bg-accent-soft' => $isProcessing,
                            ])>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-primary truncate max-w-xs">{{ $import->filename }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-2.5">
                                <x-badge :variant="$statusBadgeVariant">{{ $statusLabel }}</x-badge>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-accent">{{ data_get($import->result, 'hc_id', '—') }}</td>
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ data_get($import->result, 'dsn', '—') }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($import->status === 'ok')
                                    <span class="text-ink-primary">{{ data_get($import->result, 'pannes_conservees_count', 0) }}</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right font-mono text-xs">
                                @if ($import->status === 'ok')
                                    <span class="text-ink-primary">{{ number_format(data_get($import->result, 'flight_hours', 0), 1) }}h</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($import->flight_id && $import->status === 'ok')
                                    <a href="{{ route('flights.show', $import->flight_id) }}"
                                       class="font-mono text-[11px] text-accent hover:text-accent-pressed transition-colors">Voir vol →</a>
                                @elseif ($import->flight_id && $import->status === 'non_vol')
                                    <a href="{{ route('flights.non-vol', $import->flight_id) }}"
                                       class="font-mono text-[11px] text-accent hover:text-accent-pressed transition-colors">Voir →</a>
                                @elseif ($import->status === 'error')
                                    <span class="font-mono text-[11px] text-danger">{{ \Illuminate\Support\Str::limit(data_get($import->result, 'message', 'Parse error'), 30) }}</span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun import{{ $filter === 'pending' ? ' en attente' : '' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
```

- [ ] **Step 2: Build + tests**

```bash
npm run build
php artisan test
```

Expected: build vert. Si `ImportsTrackerTest` échoue à cause d'un texte modifié (ex : "Traite" → "OK", "Non vol" → "Non-Vol", "Voir" → "Voir vol →"), adapter le test.

- [ ] **Step 3: Commit**

```bash
git add resources/views/livewire/imports-tracker.blade.php
# si test ajusté : git add tests/Feature/ImportsTrackerTest.php
git commit -m "feat(imports): redesign tracker with simplified tabs and processing row tint"
```

---

## Task 6: Validation finale (smoke Playwright)

Cette tâche est exécutée par le contrôleur (avec accès Playwright MCP).

- [ ] **Step 1: Build et tests**

```bash
npm run build
php artisan test
```

Expected: tout vert.

- [ ] **Step 2: Screenshot Playwright `/upload`**

Naviguer `http://127.0.0.1:8000/upload`. Capture viewport 1440×900.

Vérifier :
- Header section-label "PIPELINE" + h1 "Upload XML"
- Drop zone large avec border dashed app-border, icône SVG amber au centre, titre + sous-titre, bouton "Parcourir…" amber soft pill
- Hover sur drop zone : border devient amber, fond passe à `bg-accent-soft`
- (Optionnel) Si on arrive à upload un fichier via Playwright : liste fichiers avec icône XML mono, nom mono, taille KB, bouton × et bouton "Traiter X fichier(s)" amber

- [ ] **Step 3: Screenshot Playwright `/imports`**

Naviguer `http://127.0.0.1:8000/imports`. Vérifier :
- Header section-label "SUIVI" + h1 "Imports"
- Polling indicator point ambre animé "Polling 2s" + bouton ghost "+ Upload" à droite
- 2 tabs : Tous (actif amber underline) / En attente
- Tableau 8 colonnes, headers uppercase tracking muted
- Lignes : Filename mono, Date mono, Badge statut color-coded, HcId mono ambre, DSN mono, Pannes/H.vol mono ou "—", Lien "Voir vol →" amber
- Si un import est `processing` : ligne avec fond `bg-accent-soft` + badge "Processing" pulse-amber
- Empty state si aucun import

- [ ] **Step 4: Cliquer sur tab "En attente"**

URL devient `/imports?filter=pending`. Le tab "En attente" devient actif (amber underline). Si pas d'import pending, afficher "Aucun import en attente." dans le tableau.

- [ ] **Step 5: Vérifier console JS**

`browser_console_messages` level=error → 0 erreur.

---

## Notes / pièges connus

- **Polling indicator `animate-pulse-amber`** : keyframe défini dans `tailwind.config.js` étape 1. Vérifier que la classe compile.
- **Tabs simplifiés** : le composant Livewire `ImportsTracker.php` accepte toujours `filter='done'` ou `filter='errors'` en interne (via URL query). On ne touche pas le PHP — c'est juste que la vue n'expose plus ces tabs. Si un user a un bookmark `?filter=done` ou `?filter=errors`, le filtre fonctionne mais aucun tab n'est visuellement actif (les 2 tabs Tous/En attente seront tous deux non-actifs).
- **`bg-accent-soft` sur ligne processing** : couleur très claire (#fef9eb). Visible mais discrète, ce qui est l'effet voulu.
- **Tests Pest existants** : `XmlUploaderTest` et `ImportsTrackerTest` peuvent référencer des strings spécifiques :
  - "fichier(s) en staging" → maintenant "fichier(s) en attente"
  - "Retirer" (bouton) → maintenant "×"
  - "Traite" (statut OK) → maintenant "OK"
  - "Non vol" → maintenant "Non-Vol"
  - "Deja traite" → maintenant "Déjà traité"
  - Tabs "Termines"/"Erreurs" → supprimés
  Adapter les tests si nécessaire.
- **Format taille fichier** : `number_format($file->getSize() / 1024, 0) . ' KB'`. Affiche en KB sans décimale. Si on veut plus précis, changer.
- **Status `pending` côté Livewire** : couvre `whereIn('status', ['pending', 'processing'])` dans le composant PHP (déjà comme ça aujourd'hui — pas de changement).
