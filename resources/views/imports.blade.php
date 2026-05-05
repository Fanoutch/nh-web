<x-app-layout>
    <div class="flex items-end justify-between mb-6">
        <div>
            <x-section-label class="mb-1">Suivi</x-section-label>
            <h1 class="text-[22px] font-semibold text-ink-primary">Imports</h1>
        </div>
        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5 text-[11px] text-ink-muted">
                <div class="w-1.5 h-1.5 bg-accent rounded-full animate-pulse-amber"></div>
                Polling 2s
            </div>
            <a href="{{ route('upload.index') }}"
               class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded border border-app-border text-ink-secondary bg-transparent text-xs font-medium hover:bg-app-card hover:text-ink-primary hover:border-neutral-border transition">
                + Upload
            </a>
        </div>
    </div>
    <livewire:imports-tracker />
</x-app-layout>
