<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Pipeline</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">Rapport Excel</h1>
        <p class="text-sm text-ink-muted mt-1">Déposez le CSV reçu par mail : l'Excel du jour est généré à partir du template, formules préservées.</p>
    </div>
    <livewire:excel-report-uploader />
</x-app-layout>
