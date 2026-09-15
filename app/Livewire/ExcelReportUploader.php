<?php

namespace App\Livewire;

use App\Jobs\GenerateExcelReportJob;
use App\Models\ExcelReport;
use App\Models\Secteur;
use App\Services\ExcelReportPurger;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Onglet « Disponibilités » d'un secteur : dépôt du CSV du jour, génération de la dispo (Excel)
 * + historique du secteur. Déposer / supprimer : ability gererDispos (chef ou admin).
 */
class ExcelReportUploader extends Component
{
    use WithFileUploads;

    public Secteur $secteur;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $csvFile = null;

    public ?string $notice = null;

    protected function rules(): array
    {
        return [
            'csvFile' => 'required|file|extensions:csv,txt|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel|max:20480',
        ];
    }

    protected function messages(): array
    {
        return [
            'csvFile.required' => 'Sélectionnez un fichier CSV.',
            'csvFile.extensions' => 'Le fichier doit être un CSV.',
            'csvFile.mimetypes' => 'Le fichier doit être un CSV.',
            'csvFile.max' => 'Le fichier dépasse 20 Mo.',
        ];
    }

    public function peutGerer(): bool
    {
        return auth()->user()?->can('gererDispos', $this->secteur) ?? false;
    }

    public function updatedCsvFile(): void
    {
        $this->notice = null;
        $this->validate();
    }

    public function removeFile(): void
    {
        $this->csvFile = null;
        $this->resetValidation();
    }

    public function submit(): void
    {
        abort_unless($this->peutGerer(), 403);
        $this->validate();

        $originalName = $this->csvFile->getClientOriginalName();
        $path = $this->csvFile->storeAs(
            'staging',
            uniqid('', true) . '_' . $originalName,
            'local',
        );
        $absolute = Storage::disk('local')->path($path);

        $report = $this->secteur->excelReports()->create([
            'user_id' => auth()->id(),
            'filename' => $originalName,
            'status' => 'pending',
        ]);
        GenerateExcelReportJob::dispatch($report->id, $absolute);

        $this->csvFile = null;
        $this->notice = "« {$originalName} » envoyé : génération de la dispo en cours.";
    }

    public function canDelete(ExcelReport $report): bool
    {
        return $report->secteur_id === $this->secteur->id && $this->peutGerer();
    }

    public function delete(int $reportId, ExcelReportPurger $purger): void
    {
        $report = $this->secteur->excelReports()->find($reportId);
        if ($report === null) {
            return;
        }
        abort_unless($this->canDelete($report), 403);
        if (in_array($report->status, ['pending', 'processing'], true)) {
            $this->addError('delete', 'Impossible de supprimer une génération en cours.');
            return;
        }
        $purger->deleteReport($report);
        $this->notice = "« {$report->filename} » supprimé de l'historique.";
    }

    public function render()
    {
        return view('livewire.excel-report-uploader', [
            'reports' => $this->secteur->excelReports()->with('user')->orderByDesc('id')->limit(100)->get(),
        ]);
    }
}
