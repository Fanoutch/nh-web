<?php

use App\Livewire\AssistantChat;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

it('sends only complete question/answer pairs as history, newest pairs, oldest first', function () {
    config(['assistant.historique_tours' => 2]);
    fluxReponse('Réponse.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    foreach ([
        ['utilisateur', 'Q1', 'complet'], ['assistant', 'R1', 'complet'],
        ['utilisateur', 'Q2', 'complet'], ['assistant', 'R2', 'complet'],
        ['utilisateur', 'Q3', 'complet'], ['assistant', 'Indisponible', 'erreur'],
        ['utilisateur', 'Q4', 'complet'], ['assistant', 'Début', 'interrompu'],
        ['utilisateur', 'Q5', 'complet'], ['assistant', 'R5', 'complet'],
    ] as [$role, $texte, $statut]) {
        $conversation->messages()->create(['role' => $role, 'contenu' => $texte, 'statut' => $statut]);
    }

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->set('question', 'et ensuite ?')->call('envoyer'));

    Http::assertSent(fn (Request $r) => $r['historique'] === [
        ['role' => 'user', 'content' => 'Q2'], ['role' => 'assistant', 'content' => 'R2'],
        ['role' => 'user', 'content' => 'Q5'], ['role' => 'assistant', 'content' => 'R5'],
    ]);
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

it('refuses a conversation id pushed by the client (locked property)', function () {
    $proprietaire = membreSecteur(secteurBmn(), 'chef');
    $conversation = AssistantConversation::create(['user_id' => $proprietaire->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Privée']);
    $conversation->messages()->create(['role' => 'utilisateur', 'contenu' => 'Question confidentielle du chef', 'statut' => 'complet']);
    $intrus = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($intrus)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('conversationId', $conversation->id);
})->throws(CannotUpdateLockedPropertyException::class, 'Cannot update locked property: [conversationId]');

it('never renders the messages of a conversation I do not own', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $autre = membreSecteur(secteurBmn(), 'chef');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    $conversation->messages()->create(['role' => 'utilisateur', 'contenu' => 'Texte secret', 'statut' => 'complet']);

    $composant = Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $conversation->id)->assertSee('Texte secret');

    $conversation->forceFill(['user_id' => $autre->id])->saveQuietly();

    $composant->call('$refresh')->assertDontSee('Texte secret')->assertSet('conversationId', null);
});

it('keeps saving the answer even if the browser disconnects during the stream', function () {
    fluxReponse('Réponse.');
    $avant = ignore_user_abort(false);
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    try {
        capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
            ->set('question', 'Qui ?')->call('envoyer'));

        expect(ignore_user_abort())->toBe(1);
    } finally {
        ignore_user_abort((bool) $avant);
    }
});

it('sends on Enter but lets Shift+Enter insert a new line', function () {
    Livewire::actingAs(membreSecteur(secteurBmn(), 'utilisateur'))->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->assertSeeHtml('x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"')
        ->assertDontSeeHtml('keydown.enter.prevent');
});

it('vide la zone de saisie après envoi', function () {
    fluxReponse('Réponse.');
    $user = membreSecteur(secteurBmn(), 'utilisateur');

    capturerFlux(fn () => Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('question', 'Qu’est-ce qu’un vol de contrôle ?')
        ->call('envoyer')
        ->assertSet('question', ''));
});

it('rend le Markdown de la réponse sans laisser passer de HTML', function () {
    fluxReponse("- point **important**\n<script>alert(1)</script>");
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $composant = Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()]);

    capturerFlux(fn () => $composant->set('question', 'q')->call('envoyer'));

    $composant->assertSee('<li>', false)->assertSee('<strong>', false)
        ->assertDontSee('<script>', false);
});

it('renomme une conversation et refuse un titre trop long', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Ancien titre']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('renommer', $conversation->id, 'Vols de maintenance')
        ->call('renommer', $conversation->id, str_repeat('a', 101));

    expect($conversation->fresh()->titre)->toBe('Vols de maintenance');
});

it('ignores a blank title when renaming, without error', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Titre initial']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('renommer', $conversation->id, '   ');

    expect($conversation->fresh()->titre)->toBe('Titre initial');
});

it('forbids renaming the conversation of someone else', function () {
    $proprietaire = membreSecteur(secteurBmn(), 'chef');
    $conversation = AssistantConversation::create(['user_id' => $proprietaire->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Privée']);
    $intrus = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($intrus)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('renommer', $conversation->id, 'Piratée')->assertForbidden();

    expect($conversation->fresh()->titre)->toBe('Privée');
});

it('groups conversations by date under the exact expected labels', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $aujourdhui = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Du jour']);
    $hier = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'D’hier']);
    $hier->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $semaine = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'De la semaine']);
    $semaine->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    $ancienne = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Très ancienne']);
    $ancienne->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->assertSeeInOrder(['Aujourd’hui', 'Du jour', 'Hier', 'D’hier', '7 derniers jours', 'De la semaine', 'Plus ancien', 'Très ancienne']);
});

it('opens the inline rename field on my own conversation, pre-filled with its title', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Ancien titre']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('commencerRenommage', $conversation->id)
        ->assertSet('renommageId', $conversation->id)
        ->assertSet('titreRenomme', 'Ancien titre');
});

it('forbids opening the inline rename field on someone else’s conversation', function () {
    $proprietaire = membreSecteur(secteurBmn(), 'chef');
    $conversation = AssistantConversation::create(['user_id' => $proprietaire->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Privée']);
    $intrus = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($intrus)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('commencerRenommage', $conversation->id)->assertForbidden();
});

it('closes the inline rename field without saving anything', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'Titre initial']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('commencerRenommage', $conversation->id)
        ->set('titreRenomme', 'Brouillon jamais validé')
        ->call('annulerRenommage')
        ->assertSet('renommageId', null);

    expect($conversation->fresh()->titre)->toBe('Titre initial');
});

it('escapes an apostrophe in a welcome suggestion so it cannot break the JavaScript', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    secteurBmn()->assistantDocuments()->create(['nom' => 'Manuel.pdf', 'chemin' => 'x', 'taille' => 1, 'format' => 'pdf', 'statut' => 'pret']);

    Livewire::actingAs($user)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->assertSee('Quelle est la procédure d’entretien la plus récente ?')
        // Le titre @js()-encodé n'expose plus l'apostrophe brute dans l'attribut JS.
        ->assertDontSeeHtml("\$set('question', 'Quelle est la procédure d’entretien");
});
