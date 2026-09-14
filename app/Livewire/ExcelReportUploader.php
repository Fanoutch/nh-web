<?php

namespace App\Livewire;

use App\Jobs\GenerateExcelReportJob;
use App\Models\ExcelReport;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Onglet « BMN » : dépôt du CSV du jour, génération de la dispo (Excel) + historique (tous utilisateurs).
 */
class ExcelReportUploader extends Component
{
    use WithFileUploads;

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
        $this->validate();

        $originalName = $this->csvFile->getClientOriginalName();
        $path = $this->csvFile->storeAs(
            'staging',
            uniqid('', true) . '_' . $originalName,
            'local',
        );
        $absolute = Storage::disk('local')->path($path);

        $report = ExcelReport::create([
            'user_id' => auth()->id(),
            'filename' => $originalName,
            'status' => 'pending',
        ]);
        GenerateExcelReportJob::dispatch($report->id, $absolute);

        $this->csvFile = null;
        $this->notice = "« {$originalName} » envoyé : génération de la dispo en cours.";
    }

    public function render()
    {
        return view('livewire.excel-report-uploader', [
            'reports' => ExcelReport::with('user')->orderByDesc('id')->limit(100)->get(),
        ]);
    }
}
