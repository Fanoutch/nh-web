<div class="space-y-4">
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- Recherche + info role --}}
    <div class="flex justify-between items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (nom, email)"
               class="border rounded px-3 py-2 text-sm w-96" />

        <div class="flex items-center gap-3 text-xs">
            @if ($currentIsSuperAdmin)
                <span class="bg-purple-50 text-purple-700 border border-purple-200 px-2 py-1 rounded font-medium">
                    Vous etes super admin
                </span>
            @else
                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded">
                    Vous etes admin (lecture seule sur cette page)
                </span>
            @endif
            <span class="text-gray-500">
                {{ $users->total() }} utilisateur{{ $users->total() > 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="p-3 w-12">ID</th>
                    <th class="p-3">Nom</th>
                    <th class="p-3">Email</th>
                    <th class="p-3 w-40">Statut</th>
                    <th class="p-3 w-72">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $u)
                    <tr class="border-t hover:bg-gray-50" wire:key="user-{{ $u->id }}">
                        <td class="p-3 text-gray-400 font-mono text-xs">{{ $u->id }}</td>
                        <td class="p-3 font-medium">{{ $u->name }}</td>
                        <td class="p-3 text-gray-600">{{ $u->email }}</td>
                        <td class="p-3">
                            @if ($u->is_super_admin)
                                <span class="inline-flex items-center gap-1 bg-purple-50 text-purple-700 border border-purple-200 text-xs px-2 py-1 rounded font-medium">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3">
                                        <path fill-rule="evenodd" d="M9.243 3.03a1 1 0 01.728 1.213l-1 4a1 1 0 11-1.94-.486l1-4a1 1 0 011.213-.728zm5.06 0a1 1 0 011.213.728l1 4a1 1 0 11-1.94.486l-1-4a1 1 0 01.728-1.213zM2.793 5.793a1 1 0 011.414 0l2 2a1 1 0 01-1.414 1.414l-2-2a1 1 0 010-1.414zm14.414 0a1 1 0 010 1.414l-2 2a1 1 0 11-1.414-1.414l2-2a1 1 0 011.414 0zM2 11a1 1 0 011-1h.5a1 1 0 110 2H3a1 1 0 01-1-1zm14 0a1 1 0 011-1h.5a1 1 0 110 2H17a1 1 0 01-1-1z" clip-rule="evenodd" />
                                    </svg>
                                    Super Admin
                                </span>
                            @elseif ($u->is_admin)
                                <span class="inline-flex items-center gap-1 bg-yellow-50 text-yellow-700 border border-yellow-200 text-xs px-2 py-1 rounded font-medium">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3">
                                        <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.007 5.404.433c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 17.347 7.373 20.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.433 2.082-5.007Z" clip-rule="evenodd" />
                                    </svg>
                                    Admin
                                </span>
                            @else
                                <span class="text-gray-500 text-xs">Utilisateur</span>
                            @endif
                        </td>
                        <td class="p-3">
                            @if ($u->id === auth()->id())
                                <span class="text-xs text-gray-400 italic">vous-meme</span>
                            @elseif (!$currentIsSuperAdmin)
                                <span class="text-xs text-gray-400 italic">—</span>
                            @else
                                <div class="flex gap-2 flex-wrap">
                                    {{-- Toggle super admin (sauf pour user normal qui n'est pas encore admin) --}}
                                    @if ($u->is_super_admin)
                                        <button
                                            wire:click="toggleSuperAdmin({{ $u->id }})"
                                            wire:confirm="Retirer le statut super admin de {{ $u->name }} ?"
                                            class="px-3 py-1 rounded text-xs font-medium transition bg-red-50 text-red-700 hover:bg-red-100 border border-red-200">
                                            Retirer super admin
                                        </button>
                                    @else
                                        <button
                                            wire:click="toggleSuperAdmin({{ $u->id }})"
                                            wire:confirm="Promouvoir {{ $u->name }} super admin ?"
                                            class="px-3 py-1 rounded text-xs font-medium transition bg-purple-50 text-purple-700 hover:bg-purple-100 border border-purple-200">
                                            Promouvoir super admin
                                        </button>
                                    @endif

                                    {{-- Toggle admin (pas dispo si deja super admin) --}}
                                    @if (!$u->is_super_admin)
                                        <button
                                            wire:click="toggleAdmin({{ $u->id }})"
                                            wire:confirm="{{ $u->is_admin ? 'Retirer le statut admin de ' . $u->name . ' ?' : 'Rendre ' . $u->name . ' admin ?' }}"
                                            @class([
                                                'px-3 py-1 rounded text-xs font-medium transition',
                                                'bg-red-50 text-red-700 hover:bg-red-100 border border-red-200' => $u->is_admin,
                                                'bg-green-50 text-green-700 hover:bg-green-100 border border-green-200' => !$u->is_admin,
                                            ])>
                                            {{ $u->is_admin ? 'Retirer admin' : 'Promouvoir admin' }}
                                        </button>
                                    @endif
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="p-6 text-center text-gray-500">
                            Aucun utilisateur trouve.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($users->hasPages())
        <div>
            {{ $users->links() }}
        </div>
    @endif
</div>
