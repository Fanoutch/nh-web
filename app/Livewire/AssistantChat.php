<?php

namespace App\Livewire;

use App\Models\AssistantConversation;
use App\Models\AssistantDocument;
use App\Models\AssistantMessage;
use App\Models\Secteur;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\AssistantSignalement;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Onglet « Assistant IA » : chat, historique PERSONNEL des conversations, réponses en flux.
 */
class AssistantChat extends Component
{
    public Secteur $secteur;

    public ?int $conversationId = null;

    public string $question = '';

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
    }

    public function envoyer(AssistantClient $client): void
    {
        $question = trim($this->question);
        if ($question === '') {
            return;
        }
        $this->validate(['question' => 'max:2000'], ['question.max' => 'La question est trop longue (2000 caractères maximum).']);

        $conversation = $this->conversationId !== null
            ? $this->conversationAutorisee($this->conversationId)
            : AssistantConversation::create([
                'user_id' => auth()->id(),
                'secteur_id' => $this->secteur->id,
                'titre' => Str::limit($question, 60),
            ]);
        $this->conversationId = $conversation->id;

        $historique = $conversation->messages()->reorder()
            ->where('statut', AssistantMessage::COMPLET)
            ->latest('id')->take(2 * (int) config('assistant.historique_tours'))->get()
            ->reverse()->values()
            ->map(fn (AssistantMessage $m) => [
                'role' => $m->role === AssistantMessage::UTILISATEUR ? 'user' : 'assistant',
                'content' => $m->contenu,
            ])->all();

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

        $messages = $this->conversationId !== null
            ? AssistantMessage::where('conversation_id', $this->conversationId)->orderBy('id')->get()
            : collect();

        $idsCites = $messages->flatMap(fn ($m) => array_column($m->sources ?? [], 'document_id'))->unique()->values();
        $documentsExistants = AssistantDocument::whereIn('id', $idsCites)
            ->where('secteur_id', $this->secteur->id)->pluck('id')->all();

        return view('livewire.assistant-chat', [
            'conversations' => $conversations,
            'messages' => $messages,
            'documentsExistants' => $documentsExistants,
            'prenom' => Str::before(auth()->user()->name, ' '),
            'aucunDocumentPret' => ! $this->secteur->assistantDocuments()->where('statut', AssistantDocument::PRET)->exists(),
        ]);
    }
}
