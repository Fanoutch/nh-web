<div x-data="{
        glisseType: null,
        glisseId: null,
        renommerId: null,
        renommerNom: '',
        ouvrirRenommer(id, nom) { this.renommerId = id; this.renommerNom = nom; $dispatch('open-modal', 'renommer-dossier'); },
    }"
    x-on:action-dossier-reussie.window="$dispatch('close-modal', 'nouveau-dossier'); $dispatch('close-modal', 'renommer-dossier')">

    <x-section-label class="mb-2">Documents du secteur {{ $secteur->nom }}</x-section-label>

    <input type="file" id="fichier-doc" x-ref="fichierDoc" multiple wire:model="fichiers" class="hidden" />
    <div wire:loading wire:target="fichiers" class="mb-2 text-sm text-ink-secondary">Envoi en cours…</div>

    @error('fichiers.*')
        <p class="mb-2 text-sm text-danger">{{ $message }}</p>
    @enderror
    @if ($erreur)
        <p class="mb-2 text-sm text-danger">{{ $erreur }}</p>
    @endif
    @if ($notice)
        <p class="mb-2 text-sm text-ok">{{ $notice }}</p>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-[240px_1fr] gap-4 min-h-[520px]" @if ($enPreparation) wire:poll.3s @endif>
        {{-- Arbre des dossiers --}}
        <x-card class="p-2 overflow-y-auto">
            <x-section-label class="px-2 py-1.5">Dossiers</x-section-label>
            <button type="button" wire:click="ouvrirDossier"
                    x-on:dragover.prevent="if (glisseType) $el.classList.add('bg-accent-soft')"
                    x-on:dragleave="$el.classList.remove('bg-accent-soft')"
                    x-on:drop.prevent="$el.classList.remove('bg-accent-soft'); if (glisseType) { $wire.deplacer(glisseType, glisseId, null); glisseType = null; glisseId = null; }"
                    @class([
                        'w-full flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[13px] text-left truncate',
                        'bg-accent-soft-strong font-medium text-ink-primary' => $dossierId === null,
                        'text-ink-secondary hover:bg-app-bg' => $dossierId !== null,
                    ])>
                🗂️ Documents du secteur
                <span class="ml-auto shrink-0 font-mono text-[10px] text-ink-muted">{{ $totalDocuments }}</span>
            </button>
            @foreach ($enfantsParParent->get(null, collect()) as $racine)
                <x-assistant-dossier-noeud :dossier="$racine" :enfants="$enfantsParParent" :compteurs="$compteurs"
                    :dossier-id="$dossierId" :ids-ouverts="$idsOuverts" :profondeur="1" />
            @endforeach
        </x-card>

        {{-- Contenu du dossier ouvert --}}
        <x-card class="flex flex-col min-w-0 min-h-0 relative overflow-hidden"
                x-on:dragover.prevent="if (! glisseType && [...($event.dataTransfer?.types || [])].includes('Files')) $el.classList.add('border-accent')"
                x-on:dragleave="$el.classList.remove('border-accent')"
                x-on:drop.prevent="$el.classList.remove('border-accent'); if (! glisseType && $event.dataTransfer.files.length) { $refs.fichierDoc.files = $event.dataTransfer.files; $refs.fichierDoc.dispatchEvent(new Event('change')); }">
            {{-- Barre : fil d'Ariane + actions --}}
            <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-app-border-soft">
                <div class="flex items-center gap-1 text-[13px] mr-auto flex-wrap">
                    <button type="button" wire:click="ouvrirDossier"
                            @class(['hover:text-ink-primary', $dossierId === null ? 'font-semibold text-ink-primary' : 'text-ink-secondary'])>
                        Documents
                    </button>
                    @foreach ($ariane as $noeud)
                        <span class="text-ink-muted">›</span>
                        <button type="button" wire:click="ouvrirDossier({{ $noeud['id'] }})"
                                @class(['hover:text-ink-primary', $dossierId === $noeud['id'] ? 'font-semibold text-ink-primary' : 'text-ink-secondary'])>
                            {{ $noeud['nom'] }}
                        </button>
                    @endforeach
                </div>
                <x-secondary-button type="button" x-on:click="$dispatch('open-modal', 'nouveau-dossier')">＋ Nouveau dossier</x-secondary-button>
                <x-primary-button type="button" x-on:click="$refs.fichierDoc.click()"
                                   title="PDF, Word ou TXT — jusqu'à {{ $tailleMax }} Mo par fichier">⤒ Déposer des fichiers</x-primary-button>
            </div>

            {{-- Même nom : que faire ? --}}
            @foreach ($conflits as $index => $conflit)
                <x-card class="mx-4 mt-3 px-4 py-3" wire:key="conflit-{{ $index }}">
                    <p class="text-sm text-ink-primary">
                        « {{ $conflit['nom'] }} » existe déjà ici (déposé par {{ $conflit['depose_par'] }} le {{ $conflit['depose_le'] }}).
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <x-primary-button type="button" wire:click="remplacer({{ $index }})">Remplacer</x-primary-button>
                        <x-secondary-button type="button" wire:click="ajouterQuandMeme({{ $index }})">Ajouter quand même</x-secondary-button>
                        <x-secondary-button type="button" wire:click="annuler({{ $index }})">Annuler</x-secondary-button>
                    </div>
                </x-card>
            @endforeach

            {{-- Tableau : sous-dossiers puis documents du dossier ouvert --}}
            <div class="flex-1 overflow-auto">
                <table class="w-full text-[12px]">
                    <thead>
                        <tr class="border-b border-app-border">
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Nom</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Déposé le</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Par</th>
                            <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">État</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sousDossiers as $d)
                            @php $c = $compteurs[$d->id] ?? ['dossiers' => 0, 'documents' => 0]; @endphp
                            <tr wire:key="dossier-{{ $d->id }}" draggable="true"
                                x-on:dragstart="glisseType = 'dossier'; glisseId = {{ $d->id }}"
                                x-on:dragend="glisseType = null; glisseId = null"
                                x-on:dragover.prevent="if (glisseType) $el.classList.add('bg-accent-soft')"
                                x-on:dragleave="$el.classList.remove('bg-accent-soft')"
                                x-on:drop.prevent="$el.classList.remove('bg-accent-soft'); if (glisseType) { $wire.deplacer(glisseType, glisseId, {{ $d->id }}); glisseType = null; glisseId = null; }"
                                class="border-b border-app-border-soft hover:bg-app-card">
                                <td class="px-4 py-2.5">
                                    <button type="button" wire:click="ouvrirDossier({{ $d->id }})"
                                            class="font-medium text-ink-primary hover:underline">📁 {{ $d->nom }}</button>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-ink-muted">—</td>
                                <td class="px-4 py-2.5 text-xs text-ink-muted">—</td>
                                <td class="px-4 py-2.5 text-xs text-ink-muted">
                                    {{ $c['documents'] }} document{{ $c['documents'] > 1 ? 's' : '' }}@if ($c['dossiers']), {{ $c['dossiers'] }} sous-dossier{{ $c['dossiers'] > 1 ? 's' : '' }}@endif
                                </td>
                                <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                    <button type="button" title="Renommer" x-on:click="ouvrirRenommer({{ $d->id }}, @js($d->nom))"
                                            class="text-ink-muted hover:text-ink-primary text-sm leading-none px-1">✎</button>
                                    <button type="button" title="Supprimer le dossier" wire:click="supprimerDossier({{ $d->id }})"
                                            wire:confirm="Supprimer le dossier « {{ $d->nom }} » et tout son contenu ({{ $c['dossiers'] }} sous-dossier{{ $c['dossiers'] > 1 ? 's' : '' }}, {{ $c['documents'] }} document{{ $c['documents'] > 1 ? 's' : '' }}) ?"
                                            class="text-ink-muted hover:text-danger text-sm leading-none px-1">🗑</button>
                                </td>
                            </tr>
                        @empty
                        @endforelse

                        @forelse ($documents as $document)
                            <tr wire:key="document-{{ $document->id }}"
                                draggable="{{ $document->statut !== 'en_cours' ? 'true' : 'false' }}"
                                x-on:dragstart="if (@js($document->statut !== 'en_cours')) { glisseType = 'document'; glisseId = {{ $document->id }}; }"
                                x-on:dragend="glisseType = null; glisseId = null"
                                class="border-b border-app-border-soft hover:bg-app-card">
                                <td class="px-4 py-2.5 text-xs text-ink-primary">
                                    @if ($document->statut !== 'en_cours')
                                        <a href="{{ route('secteurs.documents.consulter', [$secteur, $document]) }}" target="_blank"
                                           class="hover:underline hover:text-accent-pressed">{{ $document->nom }}</a>
                                    @else
                                        <span>{{ $document->nom }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 font-mono text-xs text-ink-secondary">{{ $document->created_at->format('d/m/Y') }}</td>
                                <td class="px-4 py-2.5 text-xs text-ink-secondary">{{ $document->user?->name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs">
                                    @if ($document->statut === 'pret')
                                        <span class="text-ok">✓ Prêt</span>
                                    @elseif ($document->statut === 'erreur')
                                        <span class="text-danger">⚠ {{ $document->message }}</span>
                                        @if ($document->signale_at)
                                            <span class="ml-2 text-ink-muted">Signalé ✓</span>
                                        @else
                                            <button type="button" wire:click="signaler({{ $document->id }})"
                                                    class="ml-2 text-accent hover:text-accent-pressed">Signaler</button>
                                        @endif
                                    @else
                                        <span class="text-warn animate-pulse">En cours…</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if ($document->statut !== 'en_cours')
                                        <button type="button" wire:click="supprimer({{ $document->id }})"
                                                wire:confirm="Supprimer « {{ $document->nom }} » ? L'assistant ne s'en servira plus."
                                                class="text-ink-muted hover:text-danger text-sm leading-none" title="Supprimer">×</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                        @endforelse

                        @if ($sousDossiers->isEmpty() && $documents->isEmpty())
                            <tr>
                                <td colspan="5" class="px-4 py-12 text-center text-ink-muted text-sm">
                                    Ce dossier est vide.<br>Glissez des fichiers ici ou utilisez « ⤒ Déposer des fichiers ».
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    {{-- Nouveau dossier --}}
    <x-modal name="nouveau-dossier" focusable max-width="sm">
        <form wire:submit="creerDossier" class="p-6">
            <h3 class="text-base font-semibold text-ink-primary mb-3">Nouveau dossier</h3>
            @if ($erreur)
                <p class="text-xs text-danger mb-3">{{ $erreur }}</p>
            @endif
            <input type="text" wire:model="nouveauNom" maxlength="100" placeholder="Nom du dossier"
                   class="w-full bg-app-elevated border border-app-border rounded-md px-3 py-2 text-sm text-ink-primary focus:outline-none focus:border-accent" />
            <div class="mt-4 flex justify-end gap-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'nouveau-dossier')">Annuler</x-secondary-button>
                <x-primary-button type="submit">Créer</x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- Renommer un dossier --}}
    <x-modal name="renommer-dossier" focusable max-width="sm">
        <form x-on:submit.prevent="$wire.renommerDossier(renommerId, renommerNom)" class="p-6">
            <h3 class="text-base font-semibold text-ink-primary mb-3">Renommer le dossier</h3>
            @if ($erreur)
                <p class="text-xs text-danger mb-3">{{ $erreur }}</p>
            @endif
            <input type="text" x-model="renommerNom" maxlength="100"
                   class="w-full bg-app-elevated border border-app-border rounded-md px-3 py-2 text-sm text-ink-primary focus:outline-none focus:border-accent" />
            <div class="mt-4 flex justify-end gap-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'renommer-dossier')">Annuler</x-secondary-button>
                <x-primary-button type="submit">Renommer</x-primary-button>
            </div>
        </form>
    </x-modal>
</div>
