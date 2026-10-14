<?php

namespace App\Livewire;

use App\Jobs\IndexerPieceJointeAssistant;
use App\Models\AssistantConversation;
use App\Models\AssistantDocument;
use App\Models\AssistantMessage;
use App\Models\AssistantPieceJointe;
use App\Models\Secteur;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\AssistantSignalement;
use App\Services\DomaineAssistant;
use App\Services\GestionConversationsAssistant;
use App\Services\SourcesAssistant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Onglet « Assistant IA » : chat, historique PERSONNEL des conversations, réponses en flux,
 * pièces jointes privées à chaque conversation.
 */
class AssistantChat extends Component
{
    use WithFileUploads;

    /** Nombre maximum de pièces jointes par conversation (fixe, indépendant des documents du secteur). */
    private const MAX_PIECES = 5;

    /** Taille maximum d'une pièce jointe, en Ko (fixe, 20 Mo — indépendant de la config des documents du secteur). */
    private const TAILLE_MAX_KO = 20 * 1024;

    /** Début de la phrase de refus du service (rag/assistant.py, REPONSE_INTROUVABLE et règle 2 du prompt). */
    private const DEBUT_REFUS = 'Je ne trouve pas cette information';

    public Secteur $secteur;

    /** Verrouillée : seul le serveur la modifie (ouvrir / nouvelle / envoyer), jamais le navigateur. */
    #[Locked]
    public ?int $conversationId = null;

    public string $question = '';

    /** Conversation en cours de renommage (champ en ligne dans l'historique), ou aucune. */
    public ?int $renommageId = null;

    /** Titre en cours de saisie pour le renommage — propriété locale, distincte du titre enregistré. */
    public string $titreRenomme = '';

    /**
     * Fichiers temporaires Livewire déposés (glisser-déposer ou bouton 📎) : traités et convertis en
     * pièces jointes dans updatedPieces(), qui vide ensuite ce tableau. Une erreur de validation le
     * laisse en l'état (fichier(s) toujours présent(s), avec leur message d'erreur), pour permettre de
     * retirer seulement le fichier fautif (retirerPiece).
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public array $pieces = [];

    public function mount(): void
    {
        $this->autoriser();
        // mount() : une fois par visite de l'onglet (chargement complet de la page), pas à chaque
        // render() — sinon le nettoyage tournerait toutes les 3 s pendant qu'une pièce s'indexe
        // (wire:poll.3s, voir render()), pour un résultat qui ne change quasiment jamais.
        app(GestionConversationsAssistant::class)->nettoyerVides(auth()->id(), $this->secteur->id);
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
        $this->pieces = [];
    }

    public function ouvrir(int $id): void
    {
        $this->conversationId = $this->conversationAutorisee($id)->id;
    }

    public function supprimerConversation(int $id, GestionConversationsAssistant $gestion): void
    {
        try {
            $gestion->supprimer($this->conversationAutorisee($id));
        } catch (AssistantIndisponible $e) {
            // Comme les autres actions de l'onglet : pas d'erreur 500, message standard, rien n'est
            // supprimé (voir le docblock de GestionConversationsAssistant::supprimer).
            $this->addError('suppression', $e->getMessage());

            return;
        }
        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }
        if ($this->renommageId === $id) {
            $this->renommageId = null;
        }
    }

    /**
     * Valide et dépose les fichiers glissés dans la conversation (bouton 📎 ou glisser-déposer) : un
     * fichier trop lourd ou d'un format non accepté échoue sans rien enregistrer (voir retirerPiece
     * pour le retirer et réessayer avec le reste). La conversation est créée si besoin (une pièce
     * jointe déposée avant le premier message crée une conversation « Nouvelle conversation »).
     */
    public function updatedPieces(): void
    {
        if ($this->pieces === []) {
            return;
        }

        try {
            $formats = app(AssistantClient::class)->formats();
        } catch (AssistantIndisponible $e) {
            $this->pieces = [];
            $this->addError('pieces.0', $e->getMessage());

            return;
        }

        $this->validate([
            'pieces.*' => ['file', 'max:'.self::TAILLE_MAX_KO, 'extensions:'.implode(',', $formats)],
        ], [
            'pieces.*.max' => 'Une pièce jointe dépasse 20 Mo.',
            'pieces.*.extensions' => 'Format non accepté. Formats acceptés : '.implode(', ', $formats)
                .'. Un ancien .doc doit être enregistré en .docx.',
        ]);

        $conversation = $this->conversationId !== null
            ? $this->conversationAutorisee($this->conversationId)
            : AssistantConversation::create([
                'user_id' => auth()->id(),
                'secteur_id' => $this->secteur->id,
                'titre' => 'Nouvelle conversation',
            ]);
        $this->conversationId = $conversation->id;

        $restantes = self::MAX_PIECES - $conversation->piecesJointes()->count();
        foreach ($this->pieces as $index => $fichier) {
            if ($restantes <= 0) {
                $this->addError('pieces.'.$index, self::MAX_PIECES.' pièces jointes maximum par conversation.');

                continue;
            }
            $this->deposerPiece($conversation, $fichier);
            $restantes--;
        }

        $this->pieces = [];
    }

    private function deposerPiece(AssistantConversation $conversation, TemporaryUploadedFile $fichier): void
    {
        $nom = $fichier->getClientOriginalName();
        $format = strtolower($fichier->getClientOriginalExtension());
        $piece = AssistantPieceJointe::create([
            'conversation_id' => $conversation->id,
            'nom' => $nom,
            'chemin' => '',
            'taille' => $fichier->getSize(),
            'format' => $format,
            'statut' => AssistantPieceJointe::EN_ATTENTE,
        ]);
        $chemin = $fichier->storeAs(
            'assistant/pj/'.$conversation->id,
            $piece->id.'_'.Str::slug(pathinfo($nom, PATHINFO_FILENAME)).'.'.$format,
            'local',
        );
        $piece->update(['chemin' => $chemin]);

        IndexerPieceJointeAssistant::dispatch($piece->id);
    }

    /** Retire un fichier encore invalidé (n'a pas passé updatedPieces) du dépôt en cours, sans toucher aux autres. */
    public function retirerPiece(int $index): void
    {
        unset($this->pieces[$index]);
        $this->pieces = array_values($this->pieces);
    }

    /**
     * Retire une pièce jointe déjà déposée (fichier + ligne), en retirant AU MIEUX ses vecteurs du
     * service Python au passage (route DELETE /pieces-jointes/{id}, voir
     * AssistantClient::supprimerPieceJointe) : un service indisponible ne bloque pas le retrait du
     * fichier ni de la ligne, comme pour IndexerPieceJointeAssistant, mais ses vecteurs restent alors
     * jusqu'à la suppression de la conversation entière. Réservée au propriétaire de sa conversation.
     */
    public function supprimerPieceJointe(int $id, AssistantClient $client): void
    {
        $piece = AssistantPieceJointe::find($id);
        if ($piece === null) {
            return;
        }
        $conversation = $piece->conversation;
        abort_unless(
            $conversation !== null
                && $conversation->user_id === auth()->id()
                && $conversation->secteur_id === $this->secteur->id,
            403,
        );

        try {
            $client->supprimerPieceJointe($piece);
        } catch (AssistantIndisponible) {
            // Tant pis : rien de plus à faire ici, voir le docblock ci-dessus.
        }

        Storage::disk('local')->delete($piece->chemin);
        $piece->delete();
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

    public function envoyer(AssistantClient $client, DomaineAssistant $domaine, SourcesAssistant $sourcesAssistant): void
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

        // Le bouton Envoyer reste normalement indisponible tant qu'une pièce jointe est en
        // préparation (voir render()) ; ce contrôle serveur reste la protection qui compte. Vérifié
        // AVANT de renommer la conversation ci-dessous : une tentative avortée ici ne doit pas
        // remplacer définitivement le titre provisoire.
        if ($conversation->piecesJointes()->whereIn('statut', [AssistantPieceJointe::EN_ATTENTE, AssistantPieceJointe::EN_COURS])->exists()) {
            $this->addError('question', 'Une pièce jointe est encore en préparation.');

            return;
        }

        // Une pièce jointe déposée avant toute question a créé la conversation avec le titre
        // provisoire « Nouvelle conversation » (voir updatedPieces) : la première question envoyée
        // le remplace, comme pour une conversation créée normalement.
        if ($conversation->titre === 'Nouvelle conversation' && ! $conversation->messages()->exists()) {
            $conversation->update(['titre' => Str::limit($question, 60)]);
        }

        // Propriété Locked non synchronisée avant la fin du flux : l'identifiant est aussi
        // diffusé ici pour que le bouton Arrêter connaisse la conversation dès la première
        // question (conversation tout juste créée), sans attendre la fin de la requête.
        $this->stream(to: 'conversation-id-en-cours', content: (string) $conversation->id, replace: true);

        $historique = $this->historique($conversation);

        // Pièces prêtes de la conversation pas encore rattachées à un message envoyé (une pièce
        // jointe déposée en cours de conversation ne doit être « annoncée » qu'une seule fois).
        $idsDejaJoints = $conversation->messages()->whereNotNull('pieces')->get()
            ->flatMap(fn ($m) => array_column($m->pieces ?? [], 'id'))->all();
        $piecesAJoindre = $conversation->piecesJointes()
            ->where('statut', AssistantPieceJointe::PRET)
            ->whereNotIn('id', $idsDejaJoints)
            ->orderBy('id')->get(['id', 'nom', 'format'])
            ->map(fn (AssistantPieceJointe $p) => ['id' => $p->id, 'nom' => $p->nom, 'format' => $p->format])
            ->all();

        $conversation->messages()->create([
            'role' => AssistantMessage::UTILISATEUR, 'contenu' => $question, 'statut' => AssistantMessage::COMPLET,
            'pieces' => $piecesAJoindre !== [] ? $piecesAJoindre : null,
        ]);
        $this->question = '';
        $this->stream(to: 'question-en-cours', content: e($question), replace: true);

        // Dossiers reconnus dans la question (ex. « procédures d'entretien ») : leurs documents sont
        // seulement PRIORISÉS auprès du service, jamais un filtre — la recherche porte toujours sur
        // tout le secteur, pour qu'un document mal rangé reste trouvable (principe validé).
        $documentsPrioritaires = $domaine->documentsPrioritaires($this->secteur, $question);

        $reponse = '';
        $sources = [];
        $erreur = null;
        $detail = null;
        $arretDemande = false;

        // Interroge le cache à CHAQUE appel (pas une fn() qui capturerait une valeur figée) :
        // Cache::pull efface le drapeau dès qu'il est lu, pour qu'un arrêt ne déborde jamais
        // sur la question suivante.
        $doitArreter = function () use ($conversation, &$arretDemande) {
            if (Cache::pull("assistant.arret.{$conversation->id}") === true) {
                $arretDemande = true;

                return true;
            }

            return false;
        };

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
                },
                documentsPrioritaires: $documentsPrioritaires,
                conversationId: $conversation->id,
                doitArreter: $doitArreter,
            );
        } catch (AssistantIndisponible $e) {
            $erreur = $e->getMessage();
            $detail = $e->detail;
        } finally {
            // $doitArreter n'est appelé que depuis la boucle de lecture du flux : si l'appel HTTP
            // échoue avant (service arrêté, statut non-2xx), Cache::pull() n'a jamais eu lieu et le
            // drapeau resterait posé jusqu'à 10 minutes, coupant la question suivante d'emblée. On
            // l'efface donc systématiquement ici, quel que soit le chemin de sortie.
            Cache::forget("assistant.arret.{$conversation->id}");
        }

        // Arrêt demandé sans aucun texte reçu : la conversation reste propre, comme si la
        // question n'avait pas eu de réponse (pas de message d'erreur enregistré), mais elle
        // remonte quand même en tête de l'historique (un message utilisateur y a été créé).
        if ($arretDemande && $reponse === '') {
            $conversation->touch();

            return;
        }

        if ($arretDemande) {
            $statut = AssistantMessage::INTERROMPU;
            $contenu = $reponse;
        } elseif ($erreur !== null) {
            $statut = $reponse === '' ? AssistantMessage::ERREUR : AssistantMessage::INTERROMPU;
            $contenu = $reponse === '' ? $erreur : $reponse;
        } else {
            $statut = AssistantMessage::COMPLET;
            $contenu = $reponse;
        }

        // Un refus (« Je ne trouve pas cette information… ») n'a pas de source : le service a pu en
        // envoyer (passages jugés insuffisants par le LLM), mais les afficher sous un refus égare.
        if (str_starts_with(trim($reponse), self::DEBUT_REFUS)) {
            $sources = [];
        }

        // Le chemin de dossier d'une source est FIGÉ ici, au moment d'enregistrer le message :
        // renommer ou déplacer un dossier plus tard ne réécrit pas les anciennes réponses.
        $conversation->messages()->create([
            'role' => AssistantMessage::ASSISTANT,
            'contenu' => $contenu,
            'sources' => $sourcesAssistant->enrichir($this->secteur, $sources),
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
     * Extrait d'une source, échappé et surligné (balises <mark>) sur les mots de la question qui a
     * amené cette réponse — voir SourcesAssistant::surligner.
     */
    private function surligner(string $extrait, string $question): string
    {
        return app(SourcesAssistant::class)->surligner($extrait, $question);
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
        // Une conversation sans message (pièce jointe déposée puis jamais utilisée, avant que
        // nettoyerVides() ne l'efface après 24 h) n'apparaît pas dans l'historique.
        $conversations = AssistantConversation::where('user_id', auth()->id())
            ->where('secteur_id', $this->secteur->id)
            ->whereHas('messages')
            ->latest('updated_at')->latest('id')->get();

        // Défense en profondeur : on n'affiche que les messages d'une conversation qui m'appartient
        // dans ce secteur. Vérifiée séparément de $conversations (qui exclut les conversations sans
        // message) : la conversation ouverte peut très bien être encore vide (pièce jointe déposée
        // avant la première question), elle doit rester consultable tant qu'elle m'appartient.
        $conversation = $this->conversationId !== null
            ? AssistantConversation::where('id', $this->conversationId)
                ->where('user_id', auth()->id())->where('secteur_id', $this->secteur->id)->first()
            : null;
        if ($conversation === null) {
            $this->conversationId = null;
        }
        $messages = $conversation !== null
            ? AssistantMessage::where('conversation_id', $conversation->id)->orderBy('id')->get()
            : collect();

        $idsCites = $messages->flatMap(fn ($m) => array_column($m->sources ?? [], 'document_id'))->unique()->values();
        $documentsExistants = AssistantDocument::whereIn('id', $idsCites)
            ->where('secteur_id', $this->secteur->id)->pluck('id')->all();

        // Pièces jointes de la conversation ouverte pas encore rattachées à un message envoyé
        // (« en cours de dépôt ») : cartes affichées au-dessus de la zone de saisie, avec ✕.
        $idsDejaJoints = $messages->flatMap(fn ($m) => array_column($m->pieces ?? [], 'id'))->all();
        $piecesEnAttente = $conversation !== null
            ? $conversation->piecesJointes()->orderBy('id')->get()->reject(fn ($p) => in_array($p->id, $idsDejaJoints, true))->values()
            : collect();
        $piecesEnPreparation = $piecesEnAttente
            ->contains(fn ($p) => in_array($p->statut, [AssistantPieceJointe::EN_ATTENTE, AssistantPieceJointe::EN_COURS], true));

        // Question ayant amené chaque réponse (message qui la précède immédiatement), pour surligner
        // ses mots dans l'extrait affiché en source (voir SourcesAssistant::surligner).
        $questionsPrecedentes = [];
        $precedente = null;
        foreach ($messages as $message) {
            if ($message->role === AssistantMessage::ASSISTANT) {
                $questionsPrecedentes[$message->id] = $precedente?->contenu ?? '';
            } else {
                $precedente = $message;
            }
        }

        // Pièces jointes de la conversation ouverte (nom => id) : une source « pj » ne porte que le
        // nom du fichier (le service Python ne renvoie pas l'identifiant), on la relie donc ici à sa
        // pièce jointe pour ouvrir la bonne route de consultation (jamais celle des documents).
        // Triées par identifiant croissant : en cas d'homonymes (rare), pluck() garde la DERNIÈRE
        // valeur pour une même clé, donc la plus récente — sans ce tri, l'ordre ne serait pas garanti.
        $piecesParNom = $conversation !== null
            ? $conversation->piecesJointes()->orderBy('id')->pluck('id', 'nom')
            : collect();

        return view('livewire.assistant-chat', [
            'conversationsGroupees' => $this->conversationsGroupees($conversations),
            'messages' => $messages,
            'documentsExistants' => $documentsExistants,
            'prenom' => Str::before(auth()->user()->name, ' '),
            'aucunDocumentPret' => ! $this->secteur->assistantDocuments()->where('statut', AssistantDocument::PRET)->exists(),
            'piecesEnAttente' => $piecesEnAttente,
            'piecesEnPreparation' => $piecesEnPreparation,
            'questionsPrecedentes' => $questionsPrecedentes,
            'piecesParNom' => $piecesParNom,
        ]);
    }
}
