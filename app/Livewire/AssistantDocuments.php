<?php

namespace App\Livewire;

use App\Jobs\IndexerDocumentAssistant;
use App\Models\AssistantDocument;
use App\Models\Secteur;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\AssistantSignalement;
use App\Services\GestionDocumentsAssistant;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Onglet « Documents » d'un secteur (chefs + admins) : dépôt, liste, suppression, signalement.
 * Les documents déposés forment la base de l'assistant IA du secteur.
 */
class AssistantDocuments extends Component
{
    use WithFileUploads;

    public Secteur $secteur;

    /** @var array<int, TemporaryUploadedFile> */
    public array $fichiers = [];

    /** Fichiers dont le nom existe déjà : [index => [nom, depose_par, depose_le, existant_id]] */
    public array $conflits = [];

    public ?string $notice = null;

    public ?string $erreur = null;

    public function mount(): void
    {
        $this->autoriser();
    }

    public function hydrate(): void
    {
        $this->autoriser();
    }

    private function autoriser(): void
    {
        abort_unless(auth()->user()?->can('gererDocuments', $this->secteur), 403);
    }

    public function updatedFichiers(): void
    {
        $this->notice = $this->erreur = null;
        $this->conflits = [];

        try {
            $formats = app(AssistantClient::class)->formats();
        } catch (AssistantIndisponible $e) {
            $this->fichiers = [];
            $this->erreur = $e->getMessage();

            return;
        }

        $tailleMax = (int) config('assistant.taille_max_mo');
        $this->validate([
            'fichiers.*' => ['file', 'max:'.($tailleMax * 1024), 'extensions:'.implode(',', $formats)],
        ], [
            'fichiers.*.max' => "Un fichier dépasse {$tailleMax} Mo.",
            'fichiers.*.extensions' => 'Format non accepté. Formats acceptés : '.implode(', ', $formats)
                .'. Un ancien .doc doit être enregistré en .docx.',
        ]);

        foreach ($this->fichiers as $index => $fichier) {
            $existant = $this->secteur->assistantDocuments()
                ->where('nom', $fichier->getClientOriginalName())->latest('id')->first();
            if ($existant !== null) {
                $this->conflits[$index] = [
                    'nom' => $existant->nom,
                    'depose_par' => $existant->user?->name ?? '—',
                    'depose_le' => $existant->created_at->format('d/m/Y'),
                    'existant_id' => $existant->id,
                ];

                continue;
            }
            $this->deposer($fichier);
            unset($this->fichiers[$index]);
        }
    }

    public function remplacer(int $index): void
    {
        if (isset($this->conflits[$index], $this->fichiers[$index])) {
            $this->deposer($this->fichiers[$index], $this->conflits[$index]['existant_id']);
        }
        $this->retirer($index);
    }

    public function ajouterQuandMeme(int $index): void
    {
        if (isset($this->conflits[$index], $this->fichiers[$index])) {
            $this->deposer($this->fichiers[$index]);
        }
        $this->retirer($index);
    }

    public function annuler(int $index): void
    {
        $this->retirer($index);
    }

    private function retirer(int $index): void
    {
        unset($this->conflits[$index], $this->fichiers[$index]);
    }

    private function deposer(TemporaryUploadedFile $fichier, ?int $remplaceId = null): void
    {
        $nom = $fichier->getClientOriginalName();
        $format = strtolower($fichier->getClientOriginalExtension());
        $document = $this->secteur->assistantDocuments()->create([
            'user_id' => auth()->id(),
            'nom' => $nom,
            'chemin' => '',
            'taille' => $fichier->getSize(),
            'format' => $format,
            'statut' => AssistantDocument::EN_ATTENTE,
        ]);
        $chemin = $fichier->storeAs(
            'assistant/documents/'.$this->secteur->slug,
            $document->id.'_'.Str::slug(pathinfo($nom, PATHINFO_FILENAME)).'.'.$format,
            'local',
        );
        $document->update(['chemin' => $chemin]);

        IndexerDocumentAssistant::dispatch($document->id, $remplaceId);
        $this->notice = "« {$nom} » ajouté.";
    }

    public function supprimer(int $documentId, GestionDocumentsAssistant $gestion): void
    {
        $this->notice = $this->erreur = null;
        $document = $this->secteur->assistantDocuments()->find($documentId);
        if ($document === null) {
            return;
        }
        if ($document->statut === AssistantDocument::EN_COURS) {
            $this->erreur = 'Ce document est en cours de préparation : réessayez dans un instant.';

            return;
        }

        try {
            $gestion->supprimer($document);
            $this->notice = "« {$document->nom} » supprimé.";
        } catch (AssistantIndisponible $e) {
            $this->erreur = $e->getMessage();
        }
    }

    public function signaler(int $documentId, AssistantSignalement $signalement): void
    {
        $document = $this->secteur->assistantDocuments()->find($documentId);
        if ($document !== null && $document->statut === AssistantDocument::ERREUR) {
            $signalement->signaler($document, auth()->user());
        }
    }

    public function render()
    {
        $documents = $this->secteur->assistantDocuments()->with('user')->latest('id')->get();

        return view('livewire.assistant-documents', [
            'documents' => $documents,
            'enPreparation' => $documents->contains(fn ($d) => in_array($d->statut, [AssistantDocument::EN_ATTENTE, AssistantDocument::EN_COURS], true)),
            'tailleMax' => (int) config('assistant.taille_max_mo'),
        ]);
    }
}
