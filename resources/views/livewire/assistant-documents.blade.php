<div>
    {{-- Dépôt --}}
    <label for="fichiersAssistant"
           class="block bg-app-card border-2 border-dashed border-app-border rounded-xl p-10 text-center cursor-pointer hover:border-accent hover:bg-accent-soft transition-colors">
        <p class="text-[15px] font-medium text-ink-primary mb-1">Déposez les documents du secteur (PDF, Word, TXT)</p>
        <p class="text-sm text-ink-muted">ou cliquez pour les sélectionner — jusqu'à {{ $tailleMax }} Mo par fichier</p>
        <input type="file" id="fichiersAssistant" multiple wire:model="fichiers" class="hidden" />
    </label>

    <div wire:loading wire:target="fichiers" class="mt-3 text-sm text-ink-secondary">Envoi en cours…</div>

    @error('fichiers.*')
        <p class="mt-3 text-sm text-danger">{{ $message }}</p>
    @enderror
    @if ($erreur)
        <p class="mt-3 text-sm text-danger">{{ $erreur }}</p>
    @endif
    @if ($notice)
        <p class="mt-3 text-sm text-ok">{{ $notice }}</p>
    @endif

    {{-- Même nom : que faire ? --}}
    @foreach ($conflits as $index => $conflit)
        <x-card class="mt-4 px-5 py-4" wire:key="conflit-{{ $index }}">
            <p class="text-sm text-ink-primary">
                « {{ $conflit['nom'] }} » existe déjà dans le secteur {{ $secteur->nom }}
                (déposé par {{ $conflit['depose_par'] }} le {{ $conflit['depose_le'] }}).
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-primary-button type="button" wire:click="remplacer({{ $index }})">Remplacer</x-primary-button>
                <x-secondary-button type="button" wire:click="ajouterQuandMeme({{ $index }})">Ajouter quand même</x-secondary-button>
                <x-secondary-button type="button" wire:click="annuler({{ $index }})">Annuler</x-secondary-button>
            </div>
        </x-card>
    @endforeach

    {{-- Liste --}}
    <div class="mt-10" @if ($enPreparation) wire:poll.3s @endif>
        <x-section-label class="mb-3">Documents du secteur {{ $secteur->nom }}</x-section-label>
        <x-card class="overflow-hidden">
            <table class="w-full text-[12px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Document</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Déposé le</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Par</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">État</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($documents as $document)
                        <tr class="border-b border-app-border-soft" wire:key="document-{{ $document->id }}">
                            <td class="px-4 py-2.5 text-xs text-ink-primary">{{ $document->nom }}</td>
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
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun document pour le moment.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
</div>
