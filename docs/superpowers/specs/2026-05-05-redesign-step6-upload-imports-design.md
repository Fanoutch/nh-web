# Design — Étape 6 / Refonte `/upload` + `/imports`

**Date** : 2026-05-05
**Repo** : `nh-web` (Laravel)
**Branche** : `feature/redesign-light`
**Source design** : `nh90-cAIman` handoff — sections `/upload` et `/imports`

## 1. Contexte

Deux pages liées au pipeline d'ingestion XML :
- `/upload` : drag & drop multi-fichiers + liste stagée + bouton "Traiter".
- `/imports` : suivi temps réel (polling Livewire 2s) avec filtres tabs et tableau résultats.

Les deux pages utilisent des composants Livewire (`xml-uploader`, `imports-tracker`) déjà fonctionnels. La refonte est purement présentation — pas de modification de la logique PHP.

## 2. Objectifs

- Refondre `resources/views/upload.blade.php` (header + livewire mount) et `resources/views/livewire/xml-uploader.blade.php` (drop zone amber + liste fichiers).
- Refondre `resources/views/imports.blade.php` (header avec polling indicator + bouton "+ Upload" en remplacement du bouton "Actualiser") et `resources/views/livewire/imports-tracker.blade.php` (tabs simplifiés + tableau 8 colonnes + badges color-coded + ligne processing teintée ambre).
- **Simplifier les tabs** sur /imports à **Tous + En attente** uniquement (les tabs Terminés et Erreurs sont retirées : Terminés est redondant avec Tous, Erreurs sera traité côté `/admin/audit-log`).
- Aucune modification de la logique PHP des composants Livewire.

**Non-objectifs** :
- Ajout d'erreurs d'import dans `/admin/audit-log` (back change séparé, à voir plus tard si besoin).
- Modifications de `XmlUploader` ou `ImportsTracker` côté PHP.
- Modifications de la queue, du Job, ou du contrôleur.

## 3. Architecture / fichiers touchés

```
nh-web/
└── resources/views/
    ├── upload.blade.php                            (refonte — header)
    ├── imports.blade.php                           (refonte — header + bouton)
    └── livewire/
        ├── xml-uploader.blade.php                  (refonte complète)
        └── imports-tracker.blade.php               (refonte complète)
```

4 fichiers blade. Aucun nouveau composant.

## 4. Page `upload.blade.php`

```blade
<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Pipeline</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Upload XML</h1>
    </div>
    <livewire:xml-uploader />
</x-app-layout>
```

## 5. Livewire `xml-uploader.blade.php` refondu

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

## 6. Page `imports.blade.php`

Header avec polling indicator (point ambre animé) + bouton "+ Upload" qui remplace l'ancien bouton "Actualiser" (le polling rend l'actualisation manuelle inutile).

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

## 7. Livewire `imports-tracker.blade.php` refondu

Tabs réduits à 2 (Tous + En attente). Tableau 8 colonnes avec badges color-coded. Ligne processing avec fond accent-soft. Lien "Voir vol →" en ambre.

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

## 8. Notes côté composants Livewire PHP

`ImportsTracker.php` accepte actuellement 4 valeurs de `$filter` : `all`, `pending`, `done`, `errors`. La vue refondue n'expose que `all` et `pending`. Le code PHP n'est **pas modifié** :
- `$filter='done'` ou `$filter='errors'` reste valide en interne et fonctionne via les query strings.
- Si un user a un bookmark avec `?filter=done`, ça continuera à filtrer correctement (juste le tab actif ne sera pas visible).
- On nettoiera le PHP côté `ImportsTracker.php` plus tard si besoin (yagni).

## 9. Plan de tests

- **Build** : `npm run build` vert.
- **Suite Pest** : `php artisan test` reste vert. Vérifier `XmlUploaderTest` et `ImportsTrackerTest` :
  - Tests qui vérifient des actions (clic, file add) : pas impactés (on ne change pas les `wire:click`).
  - Tests qui vérifient un texte précis ("En staging", "Terminés", etc.) : adapter si les strings ont changé.
- **Smoke Playwright** :
  - `/upload` :
    - Drop zone large avec border dashed app-border, hover/focus → border accent + bg accent-soft.
    - Icon amber 24px en haut, titre "Glisser-déposer vos fichiers XML", sous-titre, bouton "Parcourir…" amber soft pill.
    - Si fichier ajouté → liste card avec icône XML, nom mono, taille, bouton ×.
    - Bouton "Traiter N fichier(s)" primary amber.
  - `/imports` :
    - Header section-label "Suivi" + h1 "Imports".
    - Polling indicator point ambre animé "Polling 2s" + bouton ghost "+ Upload" à droite.
    - 2 tabs (Tous / En attente) avec underline amber sur active.
    - Tableau 8 colonnes : Fichier mono / Date mono / Badge statut color-coded / HcId mono ambre / DSN mono / Pannes / H. vol / Lien.
    - Si une ligne est en `processing` → fond `bg-accent-soft` + badge "Processing" pulse-amber.
    - Lien "Voir vol →" en ambre sur statut OK.

## 10. Hors-scope (étapes ultérieures)

| Étape | Cible |
|---|---|
| 7 | `/dashboards` |
| 8 | `/profile` |
| 9 | `/admin/users` + `/admin/audit-log` |

Erreurs d'import dans `/admin/audit-log` : à évaluer plus tard. Le proto et l'utilisateur ont indiqué que les erreurs devraient apparaître dans le journal d'audit, mais ça nécessite d'instrumenter le `ProcessXmlJob` avec Spatie ActivityLog côté back. Pas pour cette étape.
