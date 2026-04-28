<x-app-layout>
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Imports</h1>
            <p class="text-sm text-slate-500 mt-1">Suivi en temps reel des fichiers soumis</p>
        </div>
        <button onclick="window.location.reload()"
                class="flex items-center gap-2 px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm hover:bg-slate-50 transition">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
            </svg>
            Actualiser
        </button>
    </div>
    <livewire:imports-tracker />
</x-app-layout>
