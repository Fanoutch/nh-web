<?php

use App\Livewire\AssistantChat;
use App\Models\AssistantConversation;
use App\Models\Secteur;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(fn () => config(['assistant.jeton' => 'secret']));

// --- Bouton Arrêter côté AssistantChat::envoyer() ------------------------------------------

it('conserve le texte déjà reçu avec le statut interrompu quand l’utilisateur arrête, et efface le drapeau', function () {
    // Une seule ligne de texte, sans « fin » : simule un arrêt juste après ce fragment.
    Http::fake(['*/questions' => Http::response('{"type":"texte","texte":"Le vol est ordonné "}', 200, ['Content-Type' => 'application/x-ndjson'])]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    Cache::put("assistant.arret.{$conversation->id}", true, 600);

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'Qui lance un vol ?')->call('envoyer'));

    $reponse = $conversation->messages()->where('role', 'assistant')->latest('id')->first();
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

it('répond normalement (statut complet) quand aucun arrêt n’a été demandé', function () {
    fluxReponse('Réponse complète.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('question', 'Qui ?')->call('envoyer'));

    $conversation = AssistantConversation::sole();
    expect($conversation->messages()->where('role', 'assistant')->sole()->statut)->toBe('complet')
        ->and(Cache::has("assistant.arret.{$conversation->id}"))->toBeFalse();
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
