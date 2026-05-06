<div class="space-y-4">
    {{-- Flash messages --}}
    @if (session('success'))
        <div class="px-4 py-3 bg-ok-soft border border-ok-border text-ok rounded text-sm">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="px-4 py-3 bg-danger-soft border border-danger-border text-danger rounded text-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- Search + role info --}}
    <div class="flex justify-between items-center gap-3">
        <input type="text" wire:model.live.debounce.300ms="search"
               placeholder="Rechercher (nom, email)"
               class="w-96 bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-2 text-sm placeholder:text-ink-muted focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20 transition" />

        <div class="flex items-center gap-3 text-xs">
            @if ($currentIsSuperAdmin)
                <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] px-2 py-1 rounded font-mono font-medium">
                    Vous êtes super admin
                </span>
            @else
                <span class="inline-flex items-center gap-1 bg-neutral-soft text-neutral border border-neutral-border px-2 py-1 rounded font-mono">
                    Vous êtes admin (lecture seule)
                </span>
            @endif
            <span class="text-ink-muted">
                {{ $users->total() }} utilisateur{{ $users->total() > 1 ? 's' : '' }}
            </span>
        </div>
    </div>

    <x-card class="overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-[13px]">
                <thead>
                    <tr class="border-b border-app-border">
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-12">ID</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Nom</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted">Email</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-40">Statut</th>
                        <th class="text-left px-4 py-2.5 text-[10px] uppercase tracking-wider font-semibold text-ink-muted w-72">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $u)
                        <tr class="border-b border-app-border-soft hover:bg-app-bg transition-colors" wire:key="user-{{ $u->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs text-ink-muted">{{ $u->id }}</td>
                            <td class="px-4 py-2.5 font-medium text-ink-primary">{{ $u->name }}</td>
                            <td class="px-4 py-2.5 text-ink-secondary">{{ $u->email }}</td>
                            <td class="px-4 py-2.5">
                                @if ($u->is_super_admin)
                                    <span class="inline-flex items-center gap-1 bg-[#ede9fe] text-[#6d28d9] border border-[#c4b5fd] text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                        Super Admin
                                    </span>
                                @elseif ($u->is_admin)
                                    <span class="inline-flex items-center gap-1 bg-accent-soft-strong text-warn text-[11px] px-2 py-0.5 rounded font-mono font-medium">
                                        Admin
                                    </span>
                                @else
                                    <span class="text-ink-muted text-xs">Utilisateur</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5">
                                @if ($u->id === auth()->id())
                                    <span class="text-xs text-ink-muted italic">vous-même</span>
                                @elseif (!$currentIsSuperAdmin)
                                    <span class="text-xs text-ink-muted italic">—</span>
                                @else
                                    <div class="flex gap-1.5 flex-wrap">
                                        @if ($u->is_super_admin)
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Retirer le statut super admin de {{ $u->name }} ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                Retirer super admin
                                            </button>
                                        @else
                                            <button wire:click="toggleSuperAdmin({{ $u->id }})"
                                                    wire:confirm="Promouvoir {{ $u->name }} super admin ?"
                                                    class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-[#ede9fe] border border-[#c4b5fd] text-[#6d28d9] text-[11px] font-medium hover:bg-[#ddd6fe] transition">
                                                Promouvoir super admin
                                            </button>
                                        @endif

                                        @if (!$u->is_super_admin)
                                            @if ($u->is_admin)
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Retirer le statut admin de {{ $u->name }} ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-danger-soft border border-danger-border text-danger text-[11px] font-medium hover:bg-danger-border transition">
                                                    Retirer admin
                                                </button>
                                            @else
                                                <button wire:click="toggleAdmin({{ $u->id }})"
                                                        wire:confirm="Rendre {{ $u->name }} admin ?"
                                                        class="inline-flex items-center gap-1.5 px-3 py-1 rounded bg-ok-soft border border-ok-border text-ok text-[11px] font-medium hover:bg-ok-border transition">
                                                    Promouvoir admin
                                                </button>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-ink-muted text-sm">
                                Aucun utilisateur trouvé.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="px-4 py-3 border-t border-app-border-soft flex items-center justify-between text-xs text-ink-muted">
                <span>Affichage {{ $users->firstItem() }}–{{ $users->lastItem() }} de {{ $users->total() }}</span>
                <div class="flex gap-1.5">
                    @if ($users->onFirstPage())
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">← Préc.</span>
                    @else
                        <button wire:click="previousPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">← Préc.</button>
                    @endif
                    @if ($users->hasMorePages())
                        <button wire:click="nextPage"
                                class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">Suiv. →</button>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded border border-app-border text-ink-muted text-xs font-medium opacity-50 cursor-not-allowed">Suiv. →</span>
                    @endif
                </div>
            </div>
        @endif
    </x-card>
</div>
