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
