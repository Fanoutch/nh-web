<x-app-layout>
    <div class="mb-6">
        <x-section-label class="mb-1">Disponibilité</x-section-label>
        <h1 class="text-[22px] font-semibold text-ink-primary">BMN — Génération de la dispo</h1>
        <p class="text-sm text-ink-muted mt-1">Glissez le CSV reçu par mail : la dispo du jour est générée dans le template Excel, formules préservées, puis téléchargeable ci-dessous.</p>
    </div>
    <livewire:excel-report-uploader />
</x-app-layout>
