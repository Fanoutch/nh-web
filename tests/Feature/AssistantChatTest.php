<?php

use App\Livewire\AssistantChat;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => config(['assistant.jeton' => 'secret']));

/** Exécute une action qui diffuse (wire:stream) et retourne ce qui a été diffusé. */
function capturerFlux(callable $action): string
{
    $sortie = '';
    ob_start(function (string $morceau) use (&$sortie) {
        $sortie .= $morceau;

        return '';
    });
    try {
        $action();
    } finally {
        ob_end_clean();
    }

    return $sortie;
}

function fluxReponse(string ...$fragments): void
{
    $lignes = ['{"type":"sources","sources":[{"document_id":1,"nom":"Manuel.pdf","page":3}]}'];
    foreach ($fragments as $f) {
        $lignes[] = json_encode(['type' => 'texte', 'texte' => $f], JSON_UNESCAPED_UNICODE);
    }
    $lignes[] = '{"type":"fin"}';
    Http::fake(['*/questions' => Http::response(implode("\n", $lignes), 200, ['Content-Type' => 'application/x-ndjson'])]);
}

it('opens on a welcome message for every member', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur', ['name' => 'Jean Dupont']);

    $this->actingAs($user)->get(route('secteurs.assistant', secteurBmn()))
        ->assertOk()
        ->assertSeeLivewire(AssistantChat::class)
        ->assertSee('Bonjour Jean')
        ->assertSee('Posez une question sur les documents du secteur BMN.')
        ->assertSee('Secteur : BMN');
});

it('forbids the chat to non members', function () {
    $this->actingAs(User::factory()->create())->get(route('secteurs.assistant', secteurBmn()))->assertForbidden();
});

it('creates a conversation, streams the answer and saves it with its sources', function () {
    fluxReponse('Le vol ', 'est ordonné par le chef.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $composant = Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()]);

    $diffuse = capturerFlux(fn () => $composant->set('question', 'Qui lance un vol de maintenance ?')->call('envoyer'));

    expect($diffuse)->toContain('reponse-en-cours')->toContain('par le chef.');
    $conversation = AssistantConversation::sole();
    expect($conversation->user_id)->toBe($user->id)
        ->and($conversation->titre)->toBe('Qui lance un vol de maintenance ?');
    $messages = $conversation->messages;
    expect($messages->pluck('role')->all())->toBe(['utilisateur', 'assistant'])
        ->and($messages[1]->contenu)->toBe('Le vol est ordonné par le chef.')
        ->and($messages[1]->statut)->toBe('complet')
        ->and($messages[1]->sources)->toBe([['document_id' => 1, 'nom' => 'Manuel.pdf', 'page' => 3]]);
    $composant->assertSet('conversationId', $conversation->id)
        ->assertSee('Le vol est ordonné par le chef.')
        ->assertSee('Qui lance un vol de maintenance ?');
});

it('sends the last exchanges of the conversation for a follow-up question', function () {
    config(['assistant.historique_tours' => 1]);
    fluxReponse('Réponse.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    foreach (['Q1', 'R1', 'Q2', 'R2'] as $i => $texte) {
        $conversation->messages()->create(['role' => $i % 2 ? 'assistant' : 'utilisateur', 'contenu' => $texte, 'statut' => 'complet']);
    }

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'et ensuite ?')->call('envoyer'));

    Http::assertSent(fn (Request $r) => $r['historique'] === [
        ['role' => 'user', 'content' => 'Q2'], ['role' => 'assistant', 'content' => 'R2'],
    ] && $r['question'] === 'et ensuite ?');
});

it('lists only my own conversations, newest first', function () {
    $moi = membreSecteur(secteurBmn(), 'utilisateur');
    $autre = membreSecteur(secteurBmn(), 'chef');
    AssistantConversation::create(['user_id' => $autre->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Conversation du chef']);
    $ancienne = AssistantConversation::create(['user_id' => $moi->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Ancienne']);
    $ancienne->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    AssistantConversation::create(['user_id' => $moi->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Récente']);

    Livewire::actingAs($moi)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->assertSeeInOrder(['Récente', 'Ancienne'])
        ->assertDontSee('Conversation du chef');
});

it('forbids opening or deleting the conversation of someone else, even for an admin', function () {
    $proprietaire = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $proprietaire->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Privée']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->assertForbidden();
    Livewire::actingAs($admin)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('supprimerConversation', $conversation->id)->assertForbidden();

    expect(AssistantConversation::find($conversation->id))->not->toBeNull();
});

it('deletes my conversation and starts a new one', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'À supprimer']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)
        ->call('supprimerConversation', $conversation->id)
        ->assertSet('conversationId', null)
        ->assertSee('Bonjour');

    expect(AssistantConversation::count())->toBe(0);
});

it('shows a clear message when the service is down, keeps the conversation usable and lets me report it', function () {
    Http::fake(['*/questions' => Http::response('', 503)]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $composant = Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()]);

    capturerFlux(fn () => $composant->set('question', 'Qui ?')->call('envoyer'));

    $reponse = AssistantMessage::where('role', 'assistant')->sole();
    expect($reponse->statut)->toBe('erreur')
        ->and($reponse->contenu)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.")
        ->and($reponse->detail)->toContain('HTTP 503');
    $composant->assertSee("L'assistant est indisponible pour le moment, réessayez plus tard.")->assertSee('Signaler');

    $composant->call('signaler', $reponse->id)->assertSee('Signalé');
    $activite = Activity::where('log_name', 'assistant')->where('event', 'signalement')->sole();
    expect($activite->properties['question'])->toBe('Qui ?')
        ->and($activite->causer_id)->toBe($user->id);
});

it('keeps a partial answer as interrupted when the stream breaks', function () {
    Http::fake(['*/questions' => Http::response(
        '{"type":"sources","sources":[]}'."\n".'{"type":"texte","texte":"Début "}'."\n"
        .'{"type":"erreur","message":"L\'assistant est indisponible pour le moment, réessayez plus tard.","detail":"délai dépassé"}',
    )]);
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('question', 'Qui ?')->call('envoyer'));

    $reponse = AssistantMessage::where('role', 'assistant')->sole();
    expect($reponse->statut)->toBe('interrompu')
        ->and($reponse->contenu)->toBe('Début ')
        ->and($reponse->detail)->toBe('délai dépassé');
});

it('links sources to the document and marks deleted ones', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $doc = secteurBmn()->assistantDocuments()->create(['nom' => 'Manuel.pdf', 'chemin' => 'x', 'taille' => 1, 'format' => 'pdf', 'statut' => 'pret']);
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    $conversation->messages()->create(['role' => 'utilisateur', 'contenu' => 'Q', 'statut' => 'complet']);
    $conversation->messages()->create(['role' => 'assistant', 'contenu' => 'R', 'statut' => 'complet', 'sources' => [
        ['document_id' => $doc->id, 'nom' => 'Manuel.pdf', 'page' => 3],
        ['document_id' => 999, 'nom' => 'Ancien.pdf', 'page' => null],
    ]]);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)
        ->assertSeeHtml(route('secteurs.documents.consulter', [secteurBmn(), $doc]).'#page=3')
        ->assertSee('Manuel.pdf p.3')
        ->assertSee('Ancien.pdf (document supprimé)');
});

it('ignores an empty question', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('question', '   ')->call('envoyer');

    expect(AssistantConversation::count())->toBe(0);
});
