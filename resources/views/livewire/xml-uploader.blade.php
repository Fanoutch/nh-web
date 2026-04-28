<div>
    <label for="stagedFilesInput"
           class="block border-2 border-dashed border-slate-300 rounded-xl p-12 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/30 transition bg-white">
        <div class="flex justify-center mb-4">
            <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-6 h-6 text-slate-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5m0 0L7.5 12M12 7.5v9" />
                </svg>
            </div>
        </div>
        <p class="text-base font-semibold text-slate-900">Glissez-deposez vos fichiers XML ici</p>
        <p class="text-sm text-slate-500 mt-1">ou cliquez pour selectionner — jusqu'a 50 Mo par fichier</p>
        <input type="file" id="stagedFilesInput" multiple accept=".xml,application/xml,text/xml"
               wire:model="stagedFiles" class="hidden" />
    </label>

    <div wire:loading wire:target="stagedFiles" class="mt-4 text-sm text-slate-600">
        Upload en cours...
        <div class="h-2 mt-2 bg-slate-200 rounded overflow-hidden">
            <div class="h-full bg-blue-500 animate-pulse" style="width: 100%"></div>
        </div>
    </div>

    @error('stagedFiles.*')
        <p class="mt-3 text-red-500 text-sm">{{ $message }}</p>
    @enderror

    @if (count($stagedFiles) > 0)
        <div class="mt-6 bg-white border border-slate-200 rounded-xl overflow-hidden">
            <div class="px-4 py-3 border-b border-slate-100">
                <h3 class="text-sm font-semibold text-slate-700">
                    {{ count($stagedFiles) }} fichier(s) en staging
                </h3>
            </div>
            <ul class="divide-y divide-slate-100">
                @foreach ($stagedFiles as $i => $file)
                    <li class="flex justify-between items-center py-3 px-4" wire:key="staged-{{ $i }}">
                        <span class="flex items-center gap-2 text-sm text-slate-700">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-green-500">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                            </svg>
                            {{ $file->getClientOriginalName() }}
                        </span>
                        <button type="button" wire:click="removeStaged({{ $i }})"
                                class="text-red-500 text-xs hover:text-red-700">
                            Retirer
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-4">
        <button wire:click="submit"
                wire:loading.attr="disabled"
                wire:target="submit"
                @disabled(count($stagedFiles) === 0)
                @class([
                    'px-5 py-2.5 rounded-lg font-medium text-sm transition',
                    'bg-blue-600 text-white hover:bg-blue-700' => count($stagedFiles) > 0,
                    'bg-blue-200 text-white cursor-not-allowed' => count($stagedFiles) === 0,
                ])>
            Traiter {{ count($stagedFiles) }} fichier(s)
        </button>
    </div>
</div>
