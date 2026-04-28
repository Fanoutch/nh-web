<div class="space-y-6">
    <div class="flex justify-between items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (ID, description, code, systeme...)"
               class="border rounded px-3 py-2 text-sm w-96" />
        <button wire:click="openMissingModal"
                class="bg-orange-500 text-white px-4 py-2 rounded hover:bg-orange-600 text-sm">
            Signaler une panne manquante
        </button>
    </div>

    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="p-3">Description</th>
                    <th class="p-3">Failure Code</th>
                    <th class="p-3">Failure Start Time</th>
                    <th class="p-3">Occ.</th>
                    <th class="p-3">Validation</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($pannes as $p)
                    <tr class="border-t hover:bg-gray-50" wire:key="te-{{ $p->id }}">
                        <td class="p-3 max-w-md">
                            <button wire:click="openDetail({{ $p->id }})"
                                    class="text-blue-600 hover:underline text-left">
                                {{ data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') ?? $p->technical_event_id }}
                            </button>
                        </td>
                        <td class="p-3 font-mono">{{ data_get($p->details, 'FailureCode') ?? '—' }}</td>
                        <td class="p-3">{{ $p->raise_datetime->format('d/m/Y H:i:s') }}</td>
                        <td class="p-3">{{ $p->nombre_occurrences }}</td>
                        <td class="p-3">
                            @php
                                $isValid = $p->validation_status === 'validated';
                                $isReject = $p->validation_status === 'rejected';
                                // Clic sur un etat actif -> retour a pending
                                $actionValid  = $isValid  ? 'pending' : 'validated';
                                $actionReject = $isReject ? 'pending' : 'rejected';
                            @endphp
                            <div class="flex items-center gap-2">
                                <button wire:click="setValidation({{ $p->id }}, '{{ $actionValid }}')"
                                        title="{{ $isValid ? 'Retirer la validation' : 'Valider cette panne' }}"
                                        @class([
                                            'w-7 h-7 rounded-full flex items-center justify-center transition',
                                            'bg-green-500 text-white shadow-sm' => $isValid,
                                            'bg-gray-100 text-gray-400 hover:bg-green-100 hover:text-green-600' => !$isValid,
                                        ])>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                </button>
                                <button wire:click="setValidation({{ $p->id }}, '{{ $actionReject }}')"
                                        title="{{ $isReject ? 'Retirer le rejet' : 'Rejeter cette panne' }}"
                                        @class([
                                            'w-7 h-7 rounded-full flex items-center justify-center transition',
                                            'bg-red-500 text-white shadow-sm' => $isReject,
                                            'bg-gray-100 text-gray-400 hover:bg-red-100 hover:text-red-600' => !$isReject,
                                        ])>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                @if ($isValid)
                                    <span class="ml-auto flex items-center gap-1 text-green-600 text-xs font-medium">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                        Validée
                                    </span>
                                @elseif ($isReject)
                                    <span class="ml-auto flex items-center gap-1 text-red-600 text-xs font-medium">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                                        </svg>
                                        Rejetée
                                    </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="p-6 text-center text-gray-500">
                            Aucune panne conservee pour ce vol.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Panneau lateral detail --}}
    @if ($selected)
        <div wire:click.self="closeDetail"
             class="fixed inset-0 bg-black/40 z-40"
             x-on:keydown.escape.window="$wire.closeDetail()"></div>
        <aside class="fixed top-0 right-0 bottom-0 w-96 bg-white shadow-xl z-50 p-6 overflow-y-auto border-l">
            <div class="flex justify-between items-start mb-4">
                <h3 class="font-semibold">Detail panne</h3>
                <button wire:click="closeDetail" class="text-gray-400 hover:text-gray-600 text-xl leading-none">×</button>
            </div>
            <dl class="space-y-2 text-sm">
                @foreach ($selected->details as $k => $v)
                    <div>
                        <dt class="text-gray-500 text-xs">{{ $k }}</dt>
                        <dd class="break-words">{{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</dd>
                    </div>
                @endforeach
            </dl>
        </aside>
    @endif

    {{-- Modale panne manquante --}}
    @if ($showMissingModal)
        <div wire:click.self="$set('showMissingModal', false)"
             class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
            <div class="bg-white rounded shadow-lg p-6 w-[28rem]">
                <h3 class="font-semibold mb-4">Signaler une panne manquante</h3>

                <label class="block text-sm mb-1 font-medium">Failure Code *</label>
                <input type="text" wire:model="newFailureCode"
                       placeholder="ex: 46-830"
                       class="border rounded w-full px-3 py-2 mb-3" />
                @error('newFailureCode')
                    <p class="text-red-500 text-xs mb-2 -mt-2">{{ $message }}</p>
                @enderror

                <label class="block text-sm mb-1 font-medium">Description <span class="text-gray-400 font-normal">(facultatif)</span></label>
                <textarea wire:model="newDescription" rows="3"
                          placeholder="Description de la panne..."
                          class="border rounded w-full px-3 py-2 mb-4"></textarea>

                <div class="flex justify-end gap-2">
                    <button wire:click="$set('showMissingModal', false)"
                            class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">
                        Annuler
                    </button>
                    <button wire:click="submitMissingPanne"
                            class="px-4 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700">
                        Signaler
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Liste pannes manquantes --}}
    @if ($missingPannes->isNotEmpty())
        <div class="bg-white shadow rounded p-4">
            <h3 class="font-semibold mb-3">Pannes manquantes signalees ({{ $missingPannes->count() }})</h3>
            <ul class="divide-y text-sm">
                @foreach ($missingPannes as $m)
                    <li class="py-3 flex justify-between gap-3" wire:key="missing-{{ $m->id }}">
                        <div class="flex-1">
                            <span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $m->failure_code }}</span>
                            @if ($m->description)
                                <span class="text-gray-700 ml-2">{{ $m->description }}</span>
                            @endif
                            <p class="text-xs text-gray-400 mt-1">
                                Signalee par {{ $m->reporter->email ?? 'inconnu' }}
                                · {{ $m->reported_at->format('d/m/Y H:i') }}
                            </p>
                        </div>
                        @if ($m->reported_by === auth()->id())
                            <button wire:click="deleteMissing({{ $m->id }})"
                                    class="text-red-500 text-xs hover:text-red-700 self-start">
                                Supprimer
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
