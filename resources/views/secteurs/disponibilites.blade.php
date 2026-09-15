<x-app-layout>
    <x-secteur-tabs :secteur="$secteur" actif="disponibilites" />
    <livewire:excel-report-uploader :secteur="$secteur" />
</x-app-layout>
