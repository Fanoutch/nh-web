<div class="space-y-4">
    {{-- Filtres --}}
    <div class="flex items-center gap-3">
        <select wire:model.live="logName" class="border rounded px-3 py-2 text-sm">
            <option value="">Tous les modules</option>
            @foreach ($logNames as $ln)
                <option value="{{ $ln }}">{{ ucfirst($ln) }}</option>
            @endforeach
        </select>

        <select wire:model.live="event" class="border rounded px-3 py-2 text-sm">
            <option value="">Toutes les actions</option>
            @foreach ($events as $ev)
                <option value="{{ $ev }}">{{ ucfirst($ev) }}</option>
            @endforeach
        </select>

        <span class="text-xs text-gray-500 ml-auto">
            {{ $activities->total() }} entree{{ $activities->total() > 1 ? 's' : '' }}
        </span>
    </div>

    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="p-3 w-44">Date</th>
                    <th class="p-3 w-48">Utilisateur</th>
                    <th class="p-3 w-32">Module</th>
                    <th class="p-3 w-32">Action</th>
                    <th class="p-3">Cible</th>
                    <th class="p-3">Changements</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($activities as $a)
                    <tr class="border-t hover:bg-gray-50 align-top" wire:key="act-{{ $a->id }}">
                        <td class="p-3 text-xs text-gray-700 font-mono whitespace-nowrap">
                            {{ $a->created_at->format('d/m/Y H:i:s') }}
                        </td>
                        <td class="p-3">
                            @if ($a->causer)
                                <span class="font-medium">{{ $a->causer->name }}</span>
                                <div class="text-xs text-gray-500">{{ $a->causer->email }}</div>
                            @else
                                <span class="text-gray-400 italic">systeme</span>
                            @endif
                        </td>
                        <td class="p-3">
                            <span class="bg-blue-50 text-blue-700 text-xs px-2 py-0.5 rounded font-medium">
                                {{ $a->log_name ?? '—' }}
                            </span>
                        </td>
                        <td class="p-3 text-xs">
                            @php
                                $eventClasses = match ($a->event) {
                                    'created' => 'bg-green-50 text-green-700',
                                    'updated' => 'bg-yellow-50 text-yellow-700',
                                    'deleted' => 'bg-red-50 text-red-700',
                                    default => 'bg-gray-50 text-gray-600',
                                };
                            @endphp
                            <span class="px-2 py-0.5 rounded font-medium {{ $eventClasses }}">
                                {{ $a->event ?? '—' }}
                            </span>
                        </td>
                        <td class="p-3 text-xs">
                            @if ($a->subject_type)
                                <span class="font-mono text-gray-600">
                                    {{ class_basename($a->subject_type) }}#{{ $a->subject_id }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="p-3 text-xs">
                            @php
                                $old = data_get($a->properties, 'old', []);
                                $new = data_get($a->properties, 'attributes', []);
                                $keys = array_unique(array_merge(array_keys((array) $old), array_keys((array) $new)));
                            @endphp
                            @if (!empty($keys))
                                <ul class="space-y-0.5">
                                    @foreach ($keys as $k)
                                        <li>
                                            <span class="text-gray-500">{{ $k }}:</span>
                                            <span class="text-red-600 line-through">{{ data_get($old, $k) ?? '∅' }}</span>
                                            <span class="text-gray-400">→</span>
                                            <span class="text-green-700 font-medium">{{ data_get($new, $k) ?? '∅' }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-6 text-center text-gray-500">
                            Aucune activite enregistree.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($activities->hasPages())
        <div>
            {{ $activities->links() }}
        </div>
    @endif
</div>
