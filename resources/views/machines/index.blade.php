<x-app-layout>
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-900">Machines</h1>
        <p class="text-sm text-slate-500 mt-1">{{ $machines->count() }} helicoptere(s)</p>
    </div>

    @if ($machines->isEmpty())
        <div class="bg-white rounded-xl border border-slate-200 p-12 text-center">
            <p class="text-slate-500 mb-4">Aucune machine en base.</p>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-2 bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 7.5m0 0L7.5 12M12 7.5v9" />
                </svg>
                Uploader un XML
            </a>
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden divide-y divide-slate-100">
            @foreach ($machines as $m)
                <div class="grid grid-cols-12 gap-4 px-6 py-4 items-start hover:bg-slate-50 transition">
                    {{-- HcId : col-span-2 --}}
                    <a href="{{ route('machines.show', $m->hc_id) }}"
                       class="col-span-2 flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-blue-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-base font-bold text-slate-900 group-hover:text-blue-600 transition">{{ $m->hc_id }}</p>
                            <p class="text-xs text-slate-500">{{ $m->vols_count + $m->non_vols_count + $m->erreurs_count }} enregistrement(s)</p>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-slate-300 group-hover:text-blue-500 transition ml-1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>

                    {{-- Compteurs : col-span-3 --}}
                    <div class="col-span-3 grid grid-cols-3 gap-2">
                        <div class="flex flex-col items-center">
                            <p class="text-xl font-bold text-slate-900 tabular-nums leading-none">{{ $m->vols_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Vols</p>
                        </div>
                        <div class="flex flex-col items-center">
                            <p class="text-xl font-bold text-slate-900 tabular-nums leading-none">{{ $m->non_vols_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Non-vols</p>
                        </div>
                        <div class="flex flex-col items-center">
                            <p @class([
                                'text-xl font-bold tabular-nums leading-none',
                                'text-red-600' => $m->erreurs_count > 0,
                                'text-slate-900' => $m->erreurs_count === 0,
                            ])>{{ $m->erreurs_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Erreurs</p>
                        </div>
                    </div>

                    {{-- Widgets : col-span-7 (placeholders ; remplis aux tasks 9-10) --}}
                    <div class="col-span-7 grid grid-cols-2 gap-3">
                        @include('machines.partials.widget-recurrent', ['machine' => $m])
                        @include('machines.partials.widget-last-flight', ['machine' => $m])
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</x-app-layout>
