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
 * Onglet « Disponibilités » d'un secteur : dépôt de l'extraction du jour (CSV ou JSON), génération de la dispo (Excel)
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
            'csvFile' => 'required|file|extensions:csv,txt,json|mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/json|max:20480',
        ];
    }

    protected function messages(): array
    {
        return [
            'csvFile.required' => 'Sélectionnez un fichier CSV ou JSON.',
            'csvFile.extensions' => 'Le fichier doit être un CSV ou un JSON.',
            'csvFile.mimetypes' => 'Le fichier doit être un CSV ou un JSON.',
            'csvFile.max' => 'Le fichier dépasse 20 Mo.',
        ];
    }

    public function peutGerer(): bool
    {
        return auth()->user()?->can('gererDispos', $this->secteur) ?? false;
    }

    /** Re-vérifie l'accès à chaque hydratation (poll, action...) : un rôle retiré ferme l'onglet sans attendre un reload. */
    public function hydrate(): void
    {
        abort_unless(auth()->user()?->can('consulterDispos', $this->secteur), 403);
    }

    /** Empêche de déposer un fichier via le cycle d'upload même si la drop zone est masquée côté vue. */
    public function updatingCsvFile(): void
    {
        abort_unless(auth()->user()?->can('gererDispos', $this->secteur), 403);
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

    /** Utilisé uniquement côté serveur par delete() : la vue reçoit $peutGerer, calculé une seule fois par render(). */
    public function canDelete(ExcelReport $report): bool
    {
        return $report->secteur()->is($this->secteur) && $this->peutGerer();
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
            'peutGerer' => $this->peutGerer(),
        ]);
    }
}
