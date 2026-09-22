<?php

use App\Jobs\IndexerPieceJointeAssistant;
use App\Livewire\AssistantChat;
use App\Models\AssistantConversation;
use App\Models\AssistantPieceJointe;
use App\Services\AssistantClient;
use App\Services\GestionConversationsAssistant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
});

function fauxServicePieces(): void
{
    Http::fake([
        '*/formats' => Http::response(['formats' => ['pdf', 'docx', 'txt']]),
        '*/pieces-jointes' => Http::response(['nb_morceaux' => 4]),
        '*/conversations/*' => Http::response(['ok' => true]),
    ]);
}

// --- Dépôt (AssistantChat::updatedPieces) ---------------------------------------------------

it('refuse une pièce jointe de plus de 20 Mo et au-delà de 5 par conversation', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    Http::fake(['*/formats' => Http::response(['formats' => ['pdf']])]);

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('gros.pdf', 21 * 1024)])
        ->assertHasErrors('pieces.*');
    expect(AssistantPieceJointe::count())->toBe(0);

    $c = conversation($utilisateur, secteurBmn());
    for ($i = 0; $i < 5; $i++) {
        AssistantPieceJointe::create([
            'conversation_id' => $c->id, 'nom' => "Fichier{$i}.pdf", 'chemin' => "assistant/pj/{$c->id}/f{$i}.pdf",
            'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
        ]);
    }

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('ouvrir', $c->id)
        ->set('pieces', [UploadedFile::fake()->create('six.pdf', 10)])
        ->assertSee('5 pièces jointes maximum par conversation');
    expect(AssistantPieceJointe::where('conversation_id', $c->id)->count())->toBe(5);
});

it('refuse un ancien .doc avec le même message que pour les documents du secteur', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    Http::fake(['*/formats' => Http::response(['formats' => ['pdf', 'docx', 'txt']])]);

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('ancien.doc', 10)])
        ->assertHasErrors('pieces.*')
        ->assertSee('Un ancien .doc doit être enregistré en .docx.');
});

it('dépose une pièce jointe, crée la conversation si besoin et indexe en tâche de fond', function () {
    fauxServicePieces();
    Queue::fake();
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('CR_panne.pdf', 100, 'application/pdf')])
        ->assertHasNoErrors()
        ->assertSee('CR_panne.pdf');

    $conversation = AssistantConversation::sole();
    expect($conversation->user_id)->toBe($utilisateur->id)
        ->and($conversation->titre)->toBe('Nouvelle conversation');

    $piece = AssistantPieceJointe::sole();
    expect($piece->conversation_id)->toBe($conversation->id)
        ->and($piece->nom)->toBe('CR_panne.pdf')
        ->and($piece->statut)->toBe('en_attente')
        ->and($piece->chemin)->toStartWith('assistant/pj/'.$conversation->id.'/')
        ->and(Storage::disk('local')->exists($piece->chemin))->toBeTrue();

    Queue::assertPushed(IndexerPieceJointeAssistant::class, fn ($job) => $job->pieceId === $piece->id);
});

it('remplace le titre provisoire par la première question envoyée', function () {
    fauxServicePieces();
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');

    $composant = Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('CR_panne.pdf', 10, 'application/pdf')]);
    $conversation = AssistantConversation::sole();
    $conversation->piecesJointes()->update(['statut' => AssistantPieceJointe::PRET]);

    fluxReponsePieces('Réponse.');
    capturerFlux(fn () => $composant->set('question', 'Faut-il un vol de contrôle ?')->call('envoyer'));

    expect($conversation->fresh()->titre)->toBe('Faut-il un vol de contrôle ?');
});

it('bloque l’envoi tant qu’une pièce jointe est en préparation puis rattache les pièces prêtes au message', function () {
    fauxServicePieces();
    // QUEUE_CONNECTION=sync en test : sans Queue::fake(), le job d'indexation s'exécuterait tout de
    // suite et la pièce serait déjà « pret » ci-dessous — le statut est donc figé manuellement.
    Queue::fake();
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $composant = Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('CR_panne.pdf', 10, 'application/pdf')]);
    $conversation = AssistantConversation::sole();
    $piece = AssistantPieceJointe::sole();
    expect($piece->statut)->toBe('en_attente');

    // Pièce encore en préparation : l'envoi n'enregistre rien et affiche le message d'attente.
    // Texte VOLONTAIREMENT différent de la question réussie ci-dessous : si le renommage du titre
    // provisoire s'exécutait avant ce contrôle (bug corrigé), le titre porterait ce texte-ci au lieu
    // de la première question réellement envoyée — une même question aux deux tentatives masquerait ce défaut.
    $composant->set('question', 'Tentative avortée ?')->call('envoyer')
        ->assertSee('Une pièce jointe est encore en préparation.');
    expect($conversation->messages()->count())->toBe(0);
    expect($conversation->fresh()->titre)->toBe('Nouvelle conversation');

    // Une fois la pièce prête, l'envoi l'attache au message utilisateur.
    $piece->update(['statut' => AssistantPieceJointe::PRET]);
    fluxReponsePieces('Réponse.');
    capturerFlux(fn () => $composant->set('question', 'Une question ?')->call('envoyer'));

    $message = $conversation->messages()->where('role', 'utilisateur')->sole();
    expect($message->pieces)->toBe([['id' => $piece->id, 'nom' => $piece->nom, 'format' => $piece->format]])
        ->and($conversation->fresh()->titre)->toBe('Une question ?');

    // Une deuxième question de la même conversation ne rattache plus la même pièce.
    // reorder() : la relation messages() impose déjà orderBy('id') croissant, que latest('id')
    // s'ajouterait sans le remplacer (les clauses ORDER BY s'accumulent, elles ne se remplacent pas).
    capturerFlux(fn () => $composant->set('question', 'Et ensuite ?')->call('envoyer'));
    $deuxieme = $conversation->messages()->where('role', 'utilisateur')->reorder()->latest('id')->first();
    expect($deuxieme->pieces)->toBeNull();
});

it('retire un fichier refusé du dépôt en cours sans toucher aux autres', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    Http::fake(['*/formats' => Http::response(['formats' => ['pdf']])]);

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->set('pieces', [UploadedFile::fake()->create('mauvais.exe', 10)])
        ->assertHasErrors('pieces.*')
        ->assertSet('pieces', fn ($pieces) => count($pieces) === 1)
        ->call('retirerPiece', 0)
        ->assertSet('pieces', []);
});

// --- Suppression (GestionConversationsAssistant) --------------------------------------------

it('supprime les fichiers et la collection avec la conversation', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    Http::fake();
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
    ]);
    Storage::disk('local')->put($piece->chemin, 'x');

    app(GestionConversationsAssistant::class)->supprimer($c);

    Storage::disk('local')->assertMissing($piece->chemin);
    Http::assertSent(fn ($r) => str_contains($r->url(), "/conversations/{$c->id}") && $r->method() === 'DELETE');
    expect(AssistantConversation::find($c->id))->toBeNull()
        ->and(AssistantPieceJointe::find($piece->id))->toBeNull();
});

it('nettoie au chargement de l’onglet les conversations vides de plus de 24 h, sans toucher aux autres', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    Http::fake();

    $vieilleVide = conversation($utilisateur, secteurBmn(), ['titre' => 'Nouvelle conversation']);
    $vieilleVide->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $vieilleVide->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$vieilleVide->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
    ]);
    Storage::disk('local')->put($piece->chemin, 'x');

    $recenteVide = conversation($utilisateur, secteurBmn(), ['titre' => 'Nouvelle conversation']);

    $vieilleAvecMessage = conversation($utilisateur, secteurBmn(), ['titre' => 'Ancienne']);
    $vieilleAvecMessage->messages()->create(['role' => 'utilisateur', 'contenu' => 'Q', 'statut' => 'complet']);
    $vieilleAvecMessage->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

    // File de jobs engorgée plus de 24 h : la pièce est encore en_attente. Ne doit pas disparaître
    // en cours d'indexation (fichier compris).
    $vieilleEnAttente = conversation($utilisateur, secteurBmn(), ['titre' => 'Nouvelle conversation']);
    $vieilleEnAttente->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $pieceEnAttente = AssistantPieceJointe::create([
        'conversation_id' => $vieilleEnAttente->id, 'nom' => 'CR2.pdf', 'chemin' => "assistant/pj/{$vieilleEnAttente->id}/2_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_ATTENTE,
    ]);
    Storage::disk('local')->put($pieceEnAttente->chemin, 'x');

    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()]);

    expect(AssistantConversation::find($vieilleVide->id))->toBeNull()
        ->and(AssistantPieceJointe::find($piece->id))->toBeNull()
        ->and(AssistantConversation::find($recenteVide->id))->not->toBeNull()
        ->and(AssistantConversation::find($vieilleAvecMessage->id))->not->toBeNull()
        ->and(AssistantConversation::find($vieilleEnAttente->id))->not->toBeNull()
        ->and(AssistantPieceJointe::find($pieceEnAttente->id))->not->toBeNull();
    Storage::disk('local')->assertMissing($piece->chemin);
    Storage::disk('local')->assertExists($pieceEnAttente->chemin);
});

it('nettoie une seule fois par visite de l’onglet (mount), pas à chaque render()', function () {
    // Important (ronde de correction 1) : appelé depuis render(), nettoyerVides() tournerait toutes
    // les 3 s pendant qu'une pièce s'indexe (wire:poll.3s). Un espion compte ses appels réels.
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = conversation($utilisateur, secteurBmn());
    $conversation->messages()->create(['role' => 'utilisateur', 'contenu' => 'Q', 'statut' => 'complet']);

    $espion = Mockery::spy(GestionConversationsAssistant::class);
    app()->instance(GestionConversationsAssistant::class, $espion);

    // mount() (chargement de la page) + trois actions qui redéclenchent chacune render().
    Livewire::actingAs($utilisateur)->test(AssistantChat::class, ['secteur' => secteurBmn()])
        ->call('commencerRenommage', $conversation->id)
        ->call('annulerRenommage')
        ->call('ouvrir', $conversation->id);

    $espion->shouldHaveReceived('nettoyerVides')->once();
});

// --- Consultation (AssistantPieceJointeController) ------------------------------------------

it('interdit la pièce jointe d’un autre utilisateur', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $autreUtilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($autreUtilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
    ]);

    $this->actingAs($utilisateur)->get(route('secteurs.assistant.piece', [secteurBmn(), $piece]))->assertForbidden();
});

it('sert une pièce jointe pdf en ligne, avec nosniff, à son propriétaire', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR panne.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
    ]);
    Storage::disk('local')->put($piece->chemin, '%PDF-1.4');

    $this->actingAs($utilisateur)->get(route('secteurs.assistant.piece', [secteurBmn(), $piece]))
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        // Nom avec espace : HeaderUtils::makeDisposition le met entre guillemets (RFC 6266),
        // contrairement à un nom sans caractère spécial (voir AssistantDocumentController).
        ->assertHeader('Content-Disposition', 'inline; filename="CR panne.pdf"');
});

// --- Job d'indexation (IndexerPieceJointeAssistant) -----------------------------------------

it('indexe une pièce jointe et la marque prête', function () {
    Http::fake(['*/pieces-jointes' => Http::response(['nb_morceaux' => 7])]);
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_ATTENTE,
    ]);

    (new IndexerPieceJointeAssistant($piece->id))->handle(app(AssistantClient::class));

    expect($piece->fresh()->statut)->toBe('pret')->and($piece->fresh()->nb_morceaux)->toBe(7);
});

it('marque la pièce jointe illisible en erreur avec le message du service', function () {
    Http::fake(['*/pieces-jointes' => Http::response(['detail' => ['code' => 'lecture',
        'message' => 'Aucun texte trouvé dans ce PDF (document scanné ?).', 'detail' => 'ValueError: vide']], 422)]);
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'Scan.pdf', 'chemin' => "assistant/pj/{$c->id}/1_scan.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_ATTENTE,
    ]);

    (new IndexerPieceJointeAssistant($piece->id))->handle(app(AssistantClient::class));

    expect($piece->fresh()->statut)->toBe('erreur')
        ->and($piece->fresh()->message)->toBe('Aucun texte trouvé dans ce PDF (document scanné ?).')
        ->and($piece->fresh()->detail)->toBe('ValueError: vide');
});

it('marque la pièce jointe en erreur quand le service est indisponible pendant l’indexation, et retire ses vecteurs SANS toucher aux autres pièces de la conversation', function () {
    // Critique (ronde de correction 1) : le job doit cibler uniquement CETTE pièce
    // (DELETE /pieces-jointes/{id}), jamais DELETE /conversations/{id} qui effacerait aussi
    // les vecteurs des autres pièces déjà prêtes de la même conversation.
    Http::fake(['*/pieces-jointes/*' => Http::response(['ok' => true]), '*/pieces-jointes' => Http::response('', 503)]);
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $autrePiece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'Autre.pdf', 'chemin' => "assistant/pj/{$c->id}/0_autre.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::PRET,
    ]);
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_ATTENTE,
    ]);

    (new IndexerPieceJointeAssistant($piece->id))->handle(app(AssistantClient::class));

    Http::assertSent(fn ($r) => $r->method() === 'DELETE'
        && str_contains($r->url(), "/pieces-jointes/{$piece->id}") && str_contains($r->url(), "conversation_id={$c->id}"));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/conversations/'));
    expect($piece->fresh()->statut)->toBe('erreur')
        ->and($piece->fresh()->message)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.")
        // La pièce jointe déjà prête de la même conversation n'est pas touchée par ce job.
        ->and($autrePiece->fresh()->statut)->toBe('pret');
});

it('marque une pièce jointe bloquée en erreur quand le job lui-même échoue', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_COURS,
    ]);

    (new IndexerPieceJointeAssistant($piece->id))->failed(new RuntimeException('boom'));

    expect($piece->fresh()->statut)->toBe('erreur')
        ->and($piece->fresh()->message)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.")
        ->and($piece->fresh()->detail)->toBe('RuntimeException: boom');
});

it('ignore une pièce jointe supprimée pendant son indexation', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');
    $c = conversation($utilisateur, secteurBmn());
    $piece = AssistantPieceJointe::create([
        'conversation_id' => $c->id, 'nom' => 'CR.pdf', 'chemin' => "assistant/pj/{$c->id}/1_cr.pdf",
        'taille' => 10, 'format' => 'pdf', 'statut' => AssistantPieceJointe::EN_ATTENTE,
    ]);
    Http::fake(function ($r) use ($piece) {
        AssistantPieceJointe::whereKey($piece->id)->delete();

        return Http::response(['nb_morceaux' => 3]);
    });

    (new IndexerPieceJointeAssistant($piece->id))->handle(app(AssistantClient::class));

    expect(AssistantPieceJointe::count())->toBe(0);
});

/** Réponse en flux minimale, faite pour /questions, utilisée par les tests d'envoi ci-dessus. */
function fluxReponsePieces(string ...$fragments): void
{
    $lignes = ['{"type":"sources","sources":[]}'];
    foreach ($fragments as $f) {
        $lignes[] = json_encode(['type' => 'texte', 'texte' => $f], JSON_UNESCAPED_UNICODE);
    }
    $lignes[] = '{"type":"fin"}';
    Http::fake(array_merge([
        '*/formats' => Http::response(['formats' => ['pdf', 'docx', 'txt']]),
        '*/pieces-jointes' => Http::response(['nb_morceaux' => 4]),
        '*/conversations/*' => Http::response(['ok' => true]),
        '*/questions' => Http::response(implode("\n", $lignes), 200, ['Content-Type' => 'application/x-ndjson']),
    ]));
}
