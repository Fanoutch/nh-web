<?php

use App\Livewire\AssistantChat;
use App\Models\AssistantConversation;
use App\Models\Secteur;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

// --- Bouton Arrêter côté AssistantChat::envoyer() ------------------------------------------

it('conserve le texte déjà reçu avec le statut interrompu quand l’utilisateur arrête, et efface le drapeau', function () {
    // Une seule ligne de texte, sans « fin » : simule un arrêt juste après ce fragment.
    Http::fake(['*/questions' => Http::response('{"type":"texte","texte":"Le vol est ordonné "}', 200, ['Content-Type' => 'application/x-ndjson'])]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    Cache::put("assistant.arret.{$conversation->id}", true, 600);

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Qui lance un vol ?')->call('envoyer'));

    $reponse = $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first();
    expect($reponse)->not->toBeNull()
        ->and($reponse->statut)->toBe('interrompu')
        ->and($reponse->contenu)->toBe('Le vol est ordonné ')
        ->and(Cache::has("assistant.arret.{$conversation->id}"))->toBeFalse();
});

it('n’enregistre aucune réponse quand l’utilisateur arrête avant le moindre mot reçu', function () {
    // Un seul événement « sources », aucun texte : rien n'a encore été écrit quand l'arrêt agit.
    Http::fake(['*/questions' => Http::response('{"type":"sources","sources":[]}', 200, ['Content-Type' => 'application/x-ndjson'])]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    Cache::put("assistant.arret.{$conversation->id}", true, 600);

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Qui lance un vol ?')->call('envoyer'));

    expect($conversation->messages()->where('role', 'assistant')->count())->toBe(0)
        ->and($conversation->messages()->where('role', 'utilisateur')->count())->toBe(1)
        ->and(Cache::has("assistant.arret.{$conversation->id}"))->toBeFalse();
});

it('efface le drapeau d’arrêt même si l’appel au service échoue avant la lecture du flux, et laisse la question suivante intacte', function () {
    // Une seule fausse réponse HTTP, qui distingue les deux questions par leur contenu : Http::fake
    // ajoute les correspondances dans l'ordre d'enregistrement et retient la PREMIÈRE qui matche
    // l'URL, donc deux appels successifs à Http::fake(['*/questions' => ...]) ne se remplacent pas
    // l'un l'autre (le premier gagnerait toujours) — d'où ce fake unique, discriminé sur le corps.
    Http::fake(function ($requete) {
        if ($requete['question'] === 'Qui ?') {
            // 503 avant même d'entrer dans la boucle de lecture du flux : doitArreter() n'est jamais
            // appelé, donc Cache::pull() ne s'exécute pas — sans le correctif, le drapeau resterait posé.
            return Http::response('', 503);
        }

        return Http::response(
            '{"type":"sources","sources":[]}'."\n".'{"type":"texte","texte":"Réponse normale."}'."\n".'{"type":"fin"}',
            200,
            ['Content-Type' => 'application/x-ndjson'],
        );
    });
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    Cache::put("assistant.arret.{$conversation->id}", true, 600);

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Qui ?')->call('envoyer'));

    $reponse = $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first();
    expect($reponse->statut)->toBe('erreur')
        ->and(Cache::has("assistant.arret.{$conversation->id}"))->toBeFalse();

    // Le drapeau n'ayant plus cours, une seconde question dans la même conversation se déroule
    // normalement (elle ne doit pas être coupée d'emblée).
    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Et ensuite ?')->call('envoyer'));

    $deuxiemeReponse = $conversation->messages()->where('role', 'assistant')->reorder()->latest('id')->first();
    expect($deuxiemeReponse->statut)->toBe('complet')
        ->and($deuxiemeReponse->contenu)->toBe('Réponse normale.');
});

it('remonte la conversation en tête de l’historique même quand l’arrêt survient avant le moindre mot', function () {
    Http::fake(['*/questions' => Http::response('{"type":"sources","sources":[]}', 200, ['Content-Type' => 'application/x-ndjson'])]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    $conversation->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    Cache::put("assistant.arret.{$conversation->id}", true, 600);

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Qui ?')->call('envoyer'));

    expect($conversation->fresh()->updated_at->isToday())->toBeTrue();
});

it('répond normalement (statut complet) quand aucun arrêt n’a été demandé', function () {
    fluxReponse('Réponse complète.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('question', 'Qui ?')->call('envoyer'));

    $conversation = AssistantConversation::sole();
    expect($conversation->messages()->where('role', 'assistant')->sole()->statut)->toBe('complet')
        ->and(Cache::has("assistant.arret.{$conversation->id}"))->toBeFalse();
});

it('n’envoie rien quand on clique Arrêter avant que l’identifiant de la conversation ne soit connu', function () {
    // Conversation tout juste créée : conversation-id-en-cours n'a pas encore été diffusé, donc
    // $refs.conversationIdEnCours est vide. Le clic ne doit rien envoyer (pas d'identifiant vide,
    // pas de 422 avalé) et laisser le bouton dans son état normal, prêt à réessayer.
    Livewire::actingAs(membreSecteur(secteurBmn(), 'utilisateur'))->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->assertSeeHtml('const idConversation = $refs.conversationIdEnCours.textContent.trim();')
        ->assertSeeHtml("if (idConversation === '') return;");
});

// --- Route POST /secteurs/{secteur}/assistant/arreter ---------------------------------------

it('pose le drapeau d’arrêt pour ma propre conversation et répond sans contenu', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);

    $this->actingAs($user)
        ->post(route('secteurs.assistant.arreter', secteurBmn()), ['conversation_id' => $conversation->id])
        ->assertNoContent();

    expect(Cache::get("assistant.arret.{$conversation->id}"))->toBeTrue();
});

it('refuse d’arrêter la conversation d’un autre utilisateur', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $autre = membreSecteur(secteurBmn(), 'utilisateur');
    $conversationAutre = AssistantConversation::create(['user_id' => $autre->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);

    $this->actingAs($utilisateur)
        ->post(route('secteurs.assistant.arreter', secteurBmn()), ['conversation_id' => $conversationAutre->id])
        ->assertForbidden();

    expect(Cache::has("assistant.arret.{$conversationAutre->id}"))->toBeFalse();
});

it('refuse d’arrêter une conversation qui appartient à un autre secteur', function () {
    $autreSecteur = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $autreSecteur->users()->attach($user->id, ['role' => 'utilisateur']);
    $conversationAutreSecteur = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => $autreSecteur->id, 'titre' => 'T']);

    $this->actingAs($user)
        ->post(route('secteurs.assistant.arreter', secteurBmn()), ['conversation_id' => $conversationAutreSecteur->id])
        ->assertForbidden();
});

it('refuse d’arrêter à qui n’a pas le droit d’interroger l’assistant du secteur', function () {
    $intrus = User::factory()->create();
    $conversation = AssistantConversation::create(['user_id' => $intrus->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);

    $this->actingAs($intrus)
        ->post(route('secteurs.assistant.arreter', secteurBmn()), ['conversation_id' => $conversation->id])
        ->assertForbidden();
});

it('refuse d’arrêter une conversation inexistante', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    $this->actingAs($user)
        ->post(route('secteurs.assistant.arreter', secteurBmn()), ['conversation_id' => 999999])
        ->assertForbidden();
});
