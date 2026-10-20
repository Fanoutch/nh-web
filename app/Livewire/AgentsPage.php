<?php

namespace App\Livewire;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentFichierReference;
use App\Services\ApplicationProposition;
use App\Services\AssistantIndisponible;
use App\Services\FichierAgentRefuse;
use App\Services\GestionFichiersAgent;
use App\Services\LancementImpossible;
use App\Services\LanceurAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Onglet « Agents » : liste personnelle à gauche, chat au milieu, documents à droite.
 * Tout passe par le chat ; l'agent PROPOSE, l'utilisateur confirme (cartes de confirmation).
 */
class AgentsPage extends Component
{
    use WithFileUploads;

    private const TAILLE_MAX_KO = 20 * 1024;

    /** Verrouillé : seul le serveur le change, jamais le navigateur. */
    #[Locked]
    public ?int $agentId = null;

    public string $message = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $depots = [];

    public function mount(?Agent $agent = null): void
    {
        if ($agent !== null && $agent->exists) {
            abort_unless(auth()->user()->can('view', $agent), 403);
            $this->agentId = $agent->id;
        }
    }

    public function hydrate(): void
    {
        abort_unless(auth()->check(), 403);
    }

    /** Un agent n'est accessible qu'à son propriétaire (même un admin reçoit 403). */
    private function agentAutorise(int $id): Agent
    {
        $agent = Agent::find($id);
        abort_unless($agent !== null && auth()->user()->can('view', $agent), 403);

        return $agent;
    }

    private function agentCourant(): ?Agent
    {
        return $this->agentId !== null ? $this->agentAutorise($this->agentId) : null;
    }

    public function selectionner(int $id): void
    {
        $this->agentId = $this->agentAutorise($id)->id;
        $this->message = '';
        $this->depots = [];
        $this->resetErrorBag();
    }

    /** Un brouillon encore vide est réutilisé : pas de pile de « Nouvel agent ». */
    public function nouvelAgent(): void
    {
        $vide = auth()->user()->agents()->where('etat', Agent::BROUILLON)->whereDoesntHave('executions')->first();
        $agent = $vide ?? Agent::create(['user_id' => auth()->id(), 'nom' => 'Nouvel agent', 'etat' => Agent::BROUILLON]);
        $this->selectionner($agent->id);
    }

    public function supprimerAgent(int $id): void
    {
        $agent = $this->agentAutorise($id);
        abort_unless(auth()->user()->can('delete', $agent), 403);
        Storage::disk('local')->deleteDirectory($agent->dossier());
        $agent->delete();
        if ($this->agentId === $id) {
            $this->agentId = null;
        }
    }

    public function reactiver(): void
    {
        $agent = $this->agentCourant();
        abort_unless($agent !== null && auth()->user()->can('update', $agent), 403);
        if ($agent->estEnVeille()) {
            $agent->update(['etat' => Agent::ACTIF, 'en_veille_at' => null, 'veille_prevenue_at' => null, 'dernier_lancement_at' => now()]);
        }
    }

    /** Documents glissés dans le chat ou le panneau : validés, déposés, puis lus (confirmation ✓ / ⚠). */
    public function updatedDepots(GestionFichiersAgent $fichiers, \App\Services\AgentClient $client): void
    {
        $agent = $this->agentCourant();
        if ($agent === null || $this->depots === []) {
            $this->depots = [];

            return;
        }
        abort_unless(auth()->user()->can('lancer', $agent), 403);
        $this->validate(['depots.*' => ['file', 'max:'.self::TAILLE_MAX_KO]], ['depots.*.max' => 'Un document dépasse 20 Mo.']);

        foreach ($this->depots as $fichier) {
            try {
                $fichiers->deposerApport($agent, $fichier);
            } catch (FichierAgentRefuse $e) {
                $this->addError('depots', $e->getMessage());
            }
        }
        $this->depots = [];

        try {
            $fichiers->lireApports($agent, $client);
        } catch (AssistantIndisponible) {
            $this->addError('depots', 'Les documents sont déposés, mais leur lecture n\'a pas pu être vérifiée pour le moment.');
        }
    }

    public function retirerApport(int $id, GestionFichiersAgent $fichiers): void
    {
        $agent = $this->agentCourant();
        abort_unless($agent !== null, 403);
        $apport = $agent->apports()->find($id);
        if ($apport !== null) {
            $fichiers->supprimerFichier($apport);
        }
    }

    public function envoyer(LanceurAgent $lanceur): void
    {
        $texte = trim($this->message);
        if ($texte === '') {
            return;
        }
        $this->validate(['message' => 'max:4000'], ['message.max' => 'Le message est trop long (4000 caractères maximum).']);
        $this->tour($lanceur, $texte, false);
    }

    /** Bouton « Lancer l'agent » : rejoue la recette sur les documents déposés. */
    public function lancerAgent(LanceurAgent $lanceur): void
    {
        $this->tour($lanceur, '', true);
    }

    private function tour(LanceurAgent $lanceur, string $texte, bool $rejouer): void
    {
        $agent = $this->agentCourant();
        abort_unless($agent !== null && auth()->user()->can('lancer', $agent), 403);
        // Le résultat doit être enregistré même si le navigateur se déconnecte pendant le flux.
        ignore_user_abort(true);
        set_time_limit((int) config('agents.timeout') + 30);
        $this->resetErrorBag();

        $this->stream(to: 'question-en-cours', content: e($texte !== '' ? $texte : 'Lancement de l\'agent'), replace: true);
        $this->message = '';

        $lignes = [];
        $surEvenement = function (array $ev) use (&$lignes) {
            if (($ev['type'] ?? null) === 'etape') {
                $lignes[] = '<li>'.e(self::phrase($ev['outil'] ?? '?')).' <span class="text-ink-muted">— '.e((string) ($ev['resume'] ?? '')).'</span></li>';
                $this->stream(to: 'etapes-en-cours', content: '<ul class="list-none space-y-0.5">'.implode('', $lignes).'</ul>', replace: true);
            }
        };
        $doitArreter = function () use ($agent) {
            return Cache::pull("agent.arret.{$agent->id}") === true;
        };

        try {
            $lanceur->lancer($agent, auth()->user(), $texte, $rejouer, $surEvenement, $doitArreter);
        } catch (LancementImpossible $e) {
            $this->addError('message', $e->getMessage());
        } finally {
            Cache::forget("agent.arret.{$agent->id}");
        }
    }

    public function accepter(int $executionId, ApplicationProposition $application): void
    {
        $execution = $this->executionAutorisee($executionId);
        try {
            $phrase = $application->appliquer($execution);
            $execution->update(['message' => trim($execution->message."\n\n".$phrase)]);
        } catch (LancementImpossible $e) {
            $this->addError('proposition-'.$executionId, $e->getMessage());
        }
    }

    public function refuser(int $executionId, ApplicationProposition $application): void
    {
        $execution = $this->executionAutorisee($executionId);
        try {
            $application->refuser($execution);
        } catch (LancementImpossible $e) {
            $this->addError('proposition-'.$executionId, $e->getMessage());
        }
    }

    private function executionAutorisee(int $id): AgentExecution
    {
        $execution = AgentExecution::find($id);
        abort_unless($execution !== null && $execution->agent_id === $this->agentId
            && auth()->user()->can('update', $execution->agent), 403);

        return $execution;
    }

    /** Nom d'outil en phrase lisible, sans jargon. */
    public static function phrase(string $outil): string
    {
        return match ($outil) {
            'excel_decrire' => 'Lecture de la structure des classeurs',
            'excel_lire' => 'Lecture de lignes',
            'excel_filtrer' => 'Calcul sur les lignes',
            'excel_selectionner' => 'Sélection de lignes',
            'excel_colonne' => 'Calcul d\'une colonne',
            'excel_rapprocher' => 'Rapprochement de deux fichiers',
            'excel_creer' => 'Création d\'un classeur',
            'excel_regenerer' => 'Création d\'une version modifiée',
            'word_decrire' => 'Lecture du plan du document',
            'word_lire' => 'Lecture du document',
            'word_chercher' => 'Recherche dans le document',
            'word_tableau' => 'Lecture d\'un tableau',
            'word_creer' => 'Création d\'un document Word',
            'agent_proposer' => 'Proposition de changement',
            'agent_historique' => 'Consultation de l\'historique',
            default => $outil,
        };
    }

    public function rendu(string $texte): string
    {
        return Str::markdown($texte, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public function render()
    {
        $utilisateur = auth()->user();
        $agents = $utilisateur->agents()->with('rappel')->orderByDesc('dernier_lancement_at')->orderByDesc('id')->get();
        $aFaire = $agents->filter(fn (Agent $a) => $a->aFaire());

        $agent = $this->agentId !== null ? $agents->firstWhere('id', $this->agentId) : null;
        if ($agent === null) {
            $this->agentId = null;
        }
        $executions = $agent !== null ? $agent->executions()->reorder()->orderBy('id')->get() : collect();
        $apports = $agent !== null ? $agent->apports()->orderBy('id')->get() : collect();
        $produits = $agent !== null
            ? $executions->flatMap(fn (AgentExecution $e) => collect($e->fichiers_produits ?? [])->map(fn ($nom) => ['execution' => $e, 'nom' => $nom]))->reverse()->take(8)->values()
            : collect();

        return view('livewire.agents-page', [
            'agents' => $agents,
            'aFaire' => $aFaire,
            'agent' => $agent,
            'executions' => $executions,
            'apports' => $apports,
            'references' => $agent?->fichiersReference()->get() ?? collect(),
            'produits' => $produits,
            'peutLancer' => $agent !== null && $agent->etat === Agent::ACTIF && $apports->isNotEmpty()
                && $apports->every(fn (AgentFichierReference $a) => ($a->lecture['statut'] ?? null) !== 'erreur'),
            'prenom' => Str::before($utilisateur->name, ' '),
        ]);
    }
}
