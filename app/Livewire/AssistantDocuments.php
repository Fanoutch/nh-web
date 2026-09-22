<?php

namespace App\Livewire;

use App\Jobs\IndexerDocumentAssistant;
use App\Models\AssistantDocument;
use App\Models\AssistantDossier;
use App\Models\Secteur;
use App\Services\ActionDossierRefusee;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\AssistantSignalement;
use App\Services\GestionDocumentsAssistant;
use App\Services\GestionDossiersAssistant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Onglet « Documents » d'un secteur (chefs + admins) : explorateur de fichiers (arborescence de dossiers,
 * dépôt dans le dossier ouvert, déplacement, suppression, signalement).
 * Les documents déposés forment la base de l'assistant IA du secteur.
 */
class AssistantDocuments extends Component
{
    use WithFileUploads;

    public Secteur $secteur;

    /** Dossier actuellement ouvert (null = racine du secteur) ; revérifié à chaque action, jamais fait confiance au navigateur. */
    #[Locked]
    public ?int $dossierId = null;

    /** @var array<int, TemporaryUploadedFile> */
    public array $fichiers = [];

    /** Fichiers dont le nom existe déjà dans le dossier ouvert : [index => [nom, depose_par, depose_le, existant_id]] (verrouillé : calculé côté serveur) */
    #[Locked]
    public array $conflits = [];

    /** Nom saisi pour le nouveau dossier (fenêtre « Nouveau dossier »). */
    public string $nouveauNom = '';

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

    /** Le dossier donné, à condition qu'il appartienne bien au secteur courant (sinon null : jamais fait confiance à un identifiant venu du navigateur). */
    private function dossierDuSecteur(int $id): ?AssistantDossier
    {
        return $this->secteur->assistantDossiers()->find($id);
    }

    public function ouvrirDossier(?int $id = null): void
    {
        $this->dossierId = $id !== null ? $this->dossierDuSecteur($id)?->id : null;
        $this->notice = $this->erreur = null;
        $this->conflits = [];
    }

    public function creerDossier(GestionDossiersAssistant $gestion): void
    {
        $this->notice = $this->erreur = null;

        try {
            $dossier = $gestion->creer($this->secteur, $this->dossierId, trim($this->nouveauNom));
            $this->nouveauNom = '';
            $this->notice = "Dossier « {$dossier->nom} » créé.";
            $this->dispatch('action-dossier-reussie');
        } catch (ActionDossierRefusee $e) {
            $this->erreur = $e->getMessage();
        }
    }

    public function renommerDossier(int $id, string $nom, GestionDossiersAssistant $gestion): void
    {
        $this->notice = $this->erreur = null;
        $dossier = $this->dossierDuSecteur($id);
        if ($dossier === null) {
            return;
        }

        try {
            $gestion->renommer($dossier, trim($nom));
            $this->notice = "Dossier renommé « {$dossier->fresh()->nom} ».";
            $this->dispatch('action-dossier-reussie');
        } catch (ActionDossierRefusee $e) {
            $this->erreur = $e->getMessage();
        }
    }

    public function supprimerDossier(int $id, GestionDossiersAssistant $gestion): void
    {
        $this->notice = $this->erreur = null;
        $dossier = $this->dossierDuSecteur($id);
        if ($dossier === null) {
            return;
        }
        $nom = $dossier->nom;

        try {
            // Le contenu du sous-arbre n'existe plus une fois supprimer() passé : on le compte avant.
            $compte = $gestion->compter($dossier);
            $gestion->supprimer($dossier);
            $detail = $compte['dossiers'] || $compte['documents']
                ? sprintf(' (%d sous-dossier%s, %d document%s)',
                    $compte['dossiers'], $compte['dossiers'] > 1 ? 's' : '',
                    $compte['documents'], $compte['documents'] > 1 ? 's' : '')
                : '';
            $this->notice = "Dossier « {$nom} » supprimé{$detail}.";
            $this->dispatch('action-dossier-reussie');
        } catch (ActionDossierRefusee|AssistantIndisponible $e) {
            $this->erreur = $e->getMessage();
        }
    }

    /** Déplace un document ou un dossier vers un autre dossier du secteur (ou vers la racine si $destinationId est null). */
    public function deplacer(string $type, int $id, ?int $destinationId, GestionDossiersAssistant $gestion): void
    {
        $this->notice = $this->erreur = null;

        try {
            if ($type === 'document') {
                $document = $this->secteur->assistantDocuments()->find($id);
                if ($document === null) {
                    return;
                }
                if ($document->statut === AssistantDocument::EN_COURS) {
                    $this->erreur = 'Ce document est en cours de préparation : réessayez dans un instant.';

                    return;
                }
                $gestion->deplacerDocument($document, $destinationId);
            } elseif ($type === 'dossier') {
                $dossier = $this->dossierDuSecteur($id);
                if ($dossier === null) {
                    return;
                }
                $gestion->deplacerDossier($dossier, $destinationId);
            }
            $this->dispatch('action-dossier-reussie');
        } catch (ActionDossierRefusee $e) {
            $this->erreur = $e->getMessage();
        }
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
            // Décision : le conflit de nom se vérifie dans le dossier ouvert seulement — deux documents de
            // même nom dans deux dossiers différents sont légitimes.
            $existant = $this->secteur->assistantDocuments()
                ->where('dossier_id', $this->dossierId)
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
            'dossier_id' => $this->dossierId,
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
        $gestion = app(GestionDossiersAssistant::class);

        $tousDossiers = $this->secteur->assistantDossiers()->orderBy('nom')->get();

        // Sécurité / cohérence : si le dossier ouvert a été supprimé entre-temps (par un autre chef), on
        // revient silencieusement à la racine plutôt que d'afficher un dossier fantôme.
        if ($this->dossierId !== null && ! $tousDossiers->contains('id', $this->dossierId)) {
            $this->dossierId = null;
        }

        $dossierCourant = $this->dossierId !== null ? $tousDossiers->firstWhere('id', $this->dossierId) : null;

        $sousDossiers = $tousDossiers->where('parent_id', $this->dossierId)->sortBy('nom')->values();
        $documents = $this->secteur->assistantDocuments()->with('user')
            ->where('dossier_id', $this->dossierId)->latest('id')->get();

        $ariane = [];
        for ($d = $dossierCourant; $d !== null; $d = $d->parent_id !== null ? $tousDossiers->firstWhere('id', $d->parent_id) : null) {
            array_unshift($ariane, ['id' => $d->id, 'nom' => $d->nom]);
        }

        $enfantsParParent = $tousDossiers->groupBy('parent_id');
        $compteurs = $this->compteurs($tousDossiers, $enfantsParParent);

        // Chemin du dossier ouvert : ces dossiers restent dépliés par défaut dans l'arbre.
        $idsOuverts = collect($ariane)->pluck('id')->push($this->dossierId)->filter()->all();

        return view('livewire.assistant-documents', [
            'tousDossiers' => $tousDossiers,
            'enfantsParParent' => $enfantsParParent,
            'sousDossiers' => $sousDossiers,
            'documents' => $documents,
            'ariane' => $ariane,
            'compteurs' => $compteurs,
            'idsOuverts' => $idsOuverts,
            'totalDocuments' => $this->secteur->assistantDocuments()->count(),
            'enPreparation' => $documents->contains(fn ($d) => in_array($d->statut, [AssistantDocument::EN_ATTENTE, AssistantDocument::EN_COURS], true)),
            'tailleMax' => (int) config('assistant.taille_max_mo'),
        ]);
    }

    /**
     * Nombre de sous-dossiers et de documents du sous-arbre de chaque dossier du secteur (dossier lui-même
     * exclu), calculé en mémoire à partir de l'arborescence déjà chargée (pas une requête par dossier).
     *
     * @param  Collection<int, AssistantDossier>  $tousDossiers
     * @param  Collection<int, Collection<int, AssistantDossier>>  $enfantsParParent  indexé par parent_id
     * @return array<int, array{dossiers: int, documents: int}>
     */
    private function compteurs(Collection $tousDossiers, Collection $enfantsParParent): array
    {
        $docsParDossier = $this->secteur->assistantDocuments()
            ->selectRaw('dossier_id, count(*) as total')->whereNotNull('dossier_id')
            ->groupBy('dossier_id')->pluck('total', 'dossier_id');

        $resultat = [];
        $calculer = function (int $id) use (&$calculer, &$resultat, $enfantsParParent, $docsParDossier) {
            $nDossiers = 0;
            $nDocuments = (int) ($docsParDossier[$id] ?? 0);
            foreach ($enfantsParParent->get($id, collect()) as $enfant) {
                $sous = $calculer($enfant->id);
                $nDossiers += 1 + $sous['dossiers'];
                $nDocuments += $sous['documents'];
            }

            return $resultat[$id] = ['dossiers' => $nDossiers, 'documents' => $nDocuments];
        };
        foreach ($tousDossiers as $dossier) {
            if (! isset($resultat[$dossier->id])) {
                $calculer($dossier->id);
            }
        }

        return $resultat;
    }
}
