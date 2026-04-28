@php
    $active = request()->route()?->getName() ?? '';
    $links = [
        [
            'route' => 'machines.index', 'label' => 'Machines',
            'prefixes' => ['machines.', 'flights.'],
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />',
        ],
        [
            'route' => 'upload.index', 'label' => 'Upload XML',
            'prefixes' => ['upload.'],
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5m0 0L7.5 12M12 7.5v9" />',
        ],
        [
            'route' => 'imports.index', 'label' => 'Imports',
            'prefixes' => ['imports.'],
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />',
        ],
        [
            'route' => 'dashboards.index', 'label' => 'Dashboards',
            'prefixes' => ['dashboards.'],
            'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />',
        ],
    ];
@endphp

<aside class="w-60 bg-slate-900 text-slate-200 min-h-screen flex flex-col border-r border-slate-800">
    {{-- Logo / titre --}}
    <div class="p-5 border-b border-slate-800 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="white" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
            </svg>
        </div>
        <h2 class="text-base font-semibold text-white">NH Project</h2>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 p-3 space-y-1">
        @foreach ($links as $link)
            @php
                $isActive = collect($link['prefixes'])->contains(fn ($p) => str_starts_with($active, $p));
            @endphp
            <a href="{{ route($link['route']) }}"
               @class([
                   'group flex items-center justify-between px-3 py-2.5 rounded-lg text-sm transition',
                   'bg-blue-600 text-white shadow-md' => $isActive,
                   'text-slate-300 hover:bg-slate-800 hover:text-white' => !$isActive,
               ])>
                <span class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                        {!! $link['icon'] !!}
                    </svg>
                    <span class="font-medium">{{ $link['label'] }}</span>
                </span>
                @if ($isActive)
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                @endif
            </a>
        @endforeach
    </nav>

    {{-- User + logout --}}
    @auth
        <div class="border-t border-slate-800 p-3 space-y-1">
            <p class="text-xs text-slate-400 truncate px-3 py-1">{{ auth()->user()->email }}</p>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-slate-300 hover:bg-slate-800 hover:text-white transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
                    </svg>
                    Deconnexion
                </button>
            </form>
        </div>
    @endauth
</aside>
