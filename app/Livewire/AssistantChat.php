<?php

namespace App\Livewire;

use App\Models\AssistantConversation;
use App\Models\AssistantDocument;
use App\Models\AssistantMessage;
use App\Models\Secteur;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\AssistantSignalement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Onglet « Assistant IA » : chat, historique PERSONNEL des conversations, réponses en flux.
 */
class AssistantChat extends Component
{
    public Secteur $secteur;

    /** Verrouillée : seul le serveur la modifie (ouvrir / nouvelle / envoyer), jamais le navigateur. */
    #[Locked]
    public ?int $conversationId = null;

    public string $question = '';

    /** Conversation en cours de renommage (champ en ligne dans l'historique), ou aucune. */
    public ?int $renommageId = null;

    /** Titre en cours de saisie pour le renommage — propriété locale, distincte du titre enregistré. */
    public string $titreRenomme = '';

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
        abort_unless(auth()->user()?->can('interrogerAssistant', $this->secteur), 403);
    }

    /** Une conversation n'est accessible qu'à son propriétaire (même un admin reçoit 403). */
    private function conversationAutorisee(int $id): AssistantConversation
    {
        $conversation = AssistantConversation::find($id);
        abort_unless(
            $conversation !== null
                && $conversation->user_id === auth()->id()
                && $conversation->secteur_id === $this->secteur->id,
            403,
        );

        return $conversation;
    }

    public function nouvelle(): void
    {
        $this->conversationId = null;
        $this->question = '';
    }

    public function ouvrir(int $id): void
    {
        $this->conversationId = $this->conversationAutorisee($id)->id;
    }

    public function supprimerConversation(int $id): void
    {
        $this->conversationAutorisee($id)->delete();
        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }
        if ($this->renommageId === $id) {
            $this->renommageId = null;
        }
    }

    /** Ouvre le champ de renommage en ligne, pré-rempli avec le titre actuel. */
    public function commencerRenommage(int $id): void
    {
        $conversation = $this->conversationAutorisee($id);
        $this->renommageId = $id;
        $this->titreRenomme = $conversation->titre;
    }

    public function annulerRenommage(): void
    {
        $this->renommageId = null;
    }

    /** Renomme une conversation ; un titre vide ou de plus de 100 caractères est ignoré sans erreur. */
    public function renommer(int $id, string $titre): void
    {
        $conversation = $this->conversationAutorisee($id);
        $titre = trim($titre);
        if ($titre === '' || mb_strlen($titre) > 100) {
            return;
        }
        $conversation->update(['titre' => $titre]);
        if ($this->renommageId === $id) {
            $this->renommageId = null;
        }
    }

    public function envoyer(AssistantClient $client): void
    {
        $question = trim($this->question);
        if ($question === '') {
            return;
        }
        $this->validate(['question' => 'max:2000'], ['question.max' => 'La question est trop longue (2000 caractères maximum).']);
        // La réponse doit être enregistrée même si le navigateur se déconnecte pendant le flux.
        ignore_user_abort(true);

        $conversation = $this->conversationId !== null
            ? $this->conversationAutorisee($this->conversationId)
            : AssistantConversation::create([
                'user_id' => auth()->id(),
                'secteur_id' => $this->secteur->id,
                'titre' => Str::limit($question, 60),
            ]);
        $this->conversationId = $conversation->id;

        $historique = $this->historique($conversation);

        $conversation->messages()->create([
            'role' => AssistantMessage::UTILISATEUR, 'contenu' => $question, 'statut' => AssistantMessage::COMPLET,
        ]);
        $this->question = '';
        $this->stream(to: 'question-en-cours', content: e($question), replace: true);

        $reponse = '';
        $sources = [];
        $erreur = null;
        $detail = null;

        try {
            $client->poserQuestion($this->secteur, $question, $historique,
                function (array $evenement) use (&$reponse, &$sources, &$erreur, &$detail) {
                    $type = $evenement['type'] ?? null;
                    if ($type === 'sources') {
                        $sources = $evenement['sources'] ?? [];
                    } elseif ($type === 'texte') {
                        $reponse .= (string) ($evenement['texte'] ?? '');
                        $this->stream(to: 'reponse-en-cours', content: nl2br(e($reponse)), replace: true);
                    } elseif ($type === 'erreur') {
                        $erreur = (string) ($evenement['message'] ?? AssistantClient::INDISPONIBLE);
                        $detail = (string) ($evenement['detail'] ?? '');
                    }
                });
        } catch (AssistantIndisponible $e) {
            $erreur = $e->getMessage();
            $detail = $e->detail;
        }

        if ($erreur !== null) {
            $statut = $reponse === '' ? AssistantMessage::ERREUR : AssistantMessage::INTERROMPU;
            $contenu = $reponse === '' ? $erreur : $reponse;
        } else {
            $statut = AssistantMessage::COMPLET;
            $contenu = $reponse;
        }

        $conversation->messages()->create([
            'role' => AssistantMessage::ASSISTANT,
            'contenu' => $contenu,
            'sources' => $sources,
            'statut' => $statut,
            'detail' => $detail,
        ]);
        $conversation->touch();
    }

    /**
     * Derniers échanges COMPLETS (une question suivie immédiatement d'une réponse complète),
     * les N plus récents, du plus ancien au plus récent.
     *
     * @return list<array{role: string, content: string}>
     */
    private function historique(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()->reorder()->orderBy('id')->get()->values();
        $paires = [];
        foreach ($messages as $i => $m) {
            $suivant = $messages[$i + 1] ?? null;
            if ($m->role === AssistantMessage::UTILISATEUR && $suivant !== null
                && $suivant->role === AssistantMessage::ASSISTANT && $suivant->statut === AssistantMessage::COMPLET) {
                $paires[] = [
                    ['role' => 'user', 'content' => $m->contenu],
                    ['role' => 'assistant', 'content' => $suivant->contenu],
                ];
            }
        }

        $tours = max(0, (int) config('assistant.historique_tours'));

        return array_merge(...array_slice($paires, count($paires) - min($tours, count($paires))));
    }

    /**
     * Convertit le Markdown d'une réponse enregistrée en HTML sûr (échappement du HTML source,
     * liens dangereux désactivés) : utilisé uniquement pour les messages déjà enregistrés, pas
     * pendant le flux où le texte reste échappé au fur et à mesure de sa réception.
     */
    private function rendu(string $texte): string
    {
        return Str::markdown($texte, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * Groupe les conversations (déjà triées, plus récentes d'abord) par date de dernière activité.
     * Les groupes vides sont omis ; l'ordre des clés restantes est toujours le même.
     *
     * @param  Collection<int, AssistantConversation>  $conversations
     * @return array<string, Collection<int, AssistantConversation>>
     */
    private function conversationsGroupees(Collection $conversations): array
    {
        $aujourdhui = Carbon::today();
        $hier = $aujourdhui->copy()->subDay();
        $semaine = $aujourdhui->copy()->subDays(7);

        $groupes = [
            'Aujourd’hui' => collect(),
            'Hier' => collect(),
            '7 derniers jours' => collect(),
            'Plus ancien' => collect(),
        ];

        foreach ($conversations as $conversation) {
            $maj = $conversation->updated_at;
            $cle = match (true) {
                $maj->greaterThanOrEqualTo($aujourdhui) => 'Aujourd’hui',
                $maj->greaterThanOrEqualTo($hier) => 'Hier',
                $maj->greaterThanOrEqualTo($semaine) => '7 derniers jours',
                default => 'Plus ancien',
            };
            $groupes[$cle]->push($conversation);
        }

        return array_filter($groupes, fn (Collection $g) => $g->isNotEmpty());
    }

    public function signaler(int $messageId, AssistantSignalement $signalement): void
    {
        $message = AssistantMessage::find($messageId);
        if ($message === null) {
            return;
        }
        $this->conversationAutorisee($message->conversation_id);
        if ($message->role === AssistantMessage::ASSISTANT && $message->statut !== AssistantMessage::COMPLET) {
            $signalement->signaler($message, auth()->user());
        }
    }

    public function render()
    {
        $conversations = AssistantConversation::where('user_id', auth()->id())
            ->where('secteur_id', $this->secteur->id)
            ->latest('updated_at')->latest('id')->get();

        // Défense en profondeur : on n'affiche que les messages d'une conversation qui m'appartient dans ce secteur.
        $conversation = $this->conversationId !== null ? $conversations->firstWhere('id', $this->conversationId) : null;
        if ($conversation === null) {
            $this->conversationId = null;
        }
        $messages = $conversation !== null
            ? AssistantMessage::where('conversation_id', $conversation->id)->orderBy('id')->get()
            : collect();

        $idsCites = $messages->flatMap(fn ($m) => array_column($m->sources ?? [], 'document_id'))->unique()->values();
        $documentsExistants = AssistantDocument::whereIn('id', $idsCites)
            ->where('secteur_id', $this->secteur->id)->pluck('id')->all();

        return view('livewire.assistant-chat', [
            'conversationsGroupees' => $this->conversationsGroupees($conversations),
            'messages' => $messages,
            'documentsExistants' => $documentsExistants,
            'prenom' => Str::before(auth()->user()->name, ' '),
            'aucunDocumentPret' => ! $this->secteur->assistantDocuments()->where('statut', AssistantDocument::PRET)->exists(),
        ]);
    }
}
