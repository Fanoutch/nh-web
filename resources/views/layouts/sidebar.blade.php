@php
    $active = request()->route()?->getName() ?? '';

    $links = [
        [
            'route' => 'machines.index',
            'label' => 'Machines',
            'prefixes' => ['machines.', 'flights.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1" y="3" width="5" height="9" rx="1" stroke="currentColor" stroke-width="1.3"/><rect x="9" y="1" width="5" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M6 8h3" stroke="currentColor" stroke-width="1.3"/></svg>',
        ],
        [
            'route' => 'upload.index',
            'label' => 'Upload',
            'prefixes' => ['upload.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><path d="M7.5 1.5V10M4.5 4.5l3-3 3 3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M2 11v2a.5.5 0 00.5.5h10a.5.5 0 00.5-.5v-2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
        [
            'route' => 'imports.index',
            'label' => 'Imports',
            'prefixes' => ['imports.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><circle cx="7.5" cy="7.5" r="6" stroke="currentColor" stroke-width="1.3"/><path d="M7.5 4v4l2.5 2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
        [
            'route' => 'dashboards.index',
            'label' => 'Dashboards',
            'prefixes' => ['dashboards.'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="1.5" y="8" width="3" height="5.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="6" y="5" width="3" height="8.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/><rect x="10.5" y="2" width="3" height="11.5" rx="0.5" stroke="currentColor" stroke-width="1.3"/></svg>',
        ],
    ];

    $processingCount = \App\Models\Import::where('status', 'processing')->count();
    $user = auth()->user();
    $initials = collect(explode(' ', $user?->name ?? 'U'))
        ->map(fn ($s) => mb_substr($s, 0, 1))
        ->take(2)->implode('');

    // Liens admin (visibles uniquement si admin ou super admin)
    $adminLinks = $user?->isAdmin() ? [
        [
            'route' => 'admin.users',
            'label' => 'Utilisateurs',
            'prefixes' => ['admin.users'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><circle cx="7.5" cy="5" r="2.5" stroke="currentColor" stroke-width="1.3"/><path d="M2.5 13c0-2.5 2.2-4.5 5-4.5s5 2 5 4.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
        [
            'route' => 'admin.audit-log',
            'label' => 'Audit log',
            'prefixes' => ['admin.audit-log'],
            'icon' => '<svg width="15" height="15" viewBox="0 0 15 15" fill="none"><rect x="2" y="2" width="11" height="11" rx="1" stroke="currentColor" stroke-width="1.3"/><path d="M5 6h5M5 8.5h5M5 11h3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></svg>',
        ],
    ] : [];
@endphp

<aside class="w-[200px] shrink-0 bg-sidebar-bg border-r border-sidebar-border flex flex-col">
    {{-- Logo --}}
    <div class="px-4 py-5 border-b border-sidebar-border flex items-center gap-2.5">
        <img src="{{ asset('images/logo-flottille-31f.png') }}" alt="Flottille 31F"
             class="h-9 w-auto shrink-0" />
        <div>
            <div class="font-semibold text-[13px] text-ink-on-dark leading-none">NH Project</div>
            <div class="text-[10px] text-ink-muted-on-dark mt-0.5">Fleet Maintenance</div>
        </div>
    </div>

    {{-- Nav --}}
    <nav class="flex-1 p-2 flex flex-col gap-0.5">
        @foreach ($links as $link)
            @php $isActive = collect($link['prefixes'])->contains(fn ($p) => str_starts_with($active, $p)); @endphp
            <a href="{{ route($link['route']) }}"
               @class([
                   'flex items-center gap-2.5 px-3.5 py-2.5 rounded-md text-[13px] font-medium transition',
                   'bg-sidebar-panel text-accent' => $isActive,
                   'text-ink-muted-on-dark hover:bg-sidebar-panel hover:text-ink-on-dark' => !$isActive,
               ])>
                {!! $link['icon'] !!}
                <span class="flex-1">{{ $link['label'] }}</span>
                @if ($link['route'] === 'imports.index' && $processingCount > 0)
                    <span class="font-mono text-[10px] font-semibold bg-accent-soft-strong text-accent px-1.5 py-0.5 rounded">
                        {{ $processingCount }}
                    </span>
                @endif
            </a>
        @endforeach

        @if (count($adminLinks) > 0)
            <div class="mt-4 mb-1 px-3.5 text-[10px] font-semibold uppercase tracking-wider text-ink-muted-on-dark">
                Admin
            </div>
            @foreach ($adminLinks as $link)
                @php $isActive = collect($link['prefixes'])->contains(fn ($p) => str_starts_with($active, $p)); @endphp
                <a href="{{ route($link['route']) }}"
                   @class([
                       'flex items-center gap-2.5 px-3.5 py-2.5 rounded-md text-[13px] font-medium transition',
                       'bg-sidebar-panel text-accent' => $isActive,
                       'text-ink-muted-on-dark hover:bg-sidebar-panel hover:text-ink-on-dark' => !$isActive,
                   ])>
                    {!! $link['icon'] !!}
                    <span class="flex-1">{{ $link['label'] }}</span>
                </a>
            @endforeach
        @endif
    </nav>

    {{-- User dropdown --}}
    <div class="border-t border-sidebar-border p-2 relative" x-data="{ open: false }" @click.outside="open = false">
        <button type="button" @click="open = !open"
                class="w-full flex items-center gap-2 px-2.5 py-2 rounded-md hover:bg-sidebar-panel transition text-left">
            <div class="w-[26px] h-[26px] bg-sidebar-panel-border rounded-full flex items-center justify-center text-[11px] font-semibold text-accent shrink-0">
                {{ strtoupper($initials) }}
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-[12px] font-medium text-ink-on-dark truncate">{{ $user?->name ?? '—' }}</div>
                <div class="text-[10px] text-ink-muted-on-dark truncate">{{ $user?->email }}</div>
            </div>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" class="text-ink-muted-on-dark">
                <path d="M3 4.5l3 3 3-3" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute bottom-[64px] left-2 right-2 bg-sidebar-panel border border-sidebar-panel-border rounded-md overflow-hidden shadow-xl z-50">
            <a href="{{ route('profile.edit') }}"
               class="block px-3 py-2 text-[12px] text-ink-on-dark hover:bg-sidebar-panel-border transition">
                Profil
            </a>
            <hr class="border-sidebar-panel-border">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full text-left px-3 py-2 text-[12px] text-danger hover:bg-sidebar-panel-border transition">
                    Déconnexion
                </button>
            </form>
        </div>
    </div>
</aside>
