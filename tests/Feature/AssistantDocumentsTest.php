<?php

use App\Jobs\IndexerDocumentAssistant;
use App\Livewire\AssistantDocuments;
use App\Models\AssistantDocument;
use App\Models\Secteur;
use App\Models\User;
use App\Services\AssistantSignalement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
});

function fauxService(int $nbMorceaux = 5): void
{
    Http::fake([
        '*/formats' => Http::response(['formats' => ['docx', 'pdf', 'txt']]),
        '*/documents/*' => Http::response(['ok' => true]),
        '*/documents' => Http::response(['nb_morceaux' => $nbMorceaux]),
    ]);
}

function docExistant(string $nom = 'Manuel.pdf', string $statut = 'pret', ?User $par = null): AssistantDocument
{
    Storage::disk('local')->put("assistant/documents/bmn/x_{$nom}", 'contenu');

    return secteurBmn()->assistantDocuments()->create([
        'user_id' => $par?->id, 'nom' => $nom, 'chemin' => "assistant/documents/bmn/x_{$nom}",
        'taille' => 7, 'format' => 'pdf', 'statut' => $statut,
    ]);
}

it('shows the Documents tab only to chefs and admins', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $utilisateur = membreSecteur($bmn, 'utilisateur');

    $this->actingAs($chef)->get(route('secteurs.disponibilites', $bmn))->assertSee(route('secteurs.documents', $bmn));
    $this->actingAs($utilisateur)->get(route('secteurs.disponibilites', $bmn))->assertDontSee(route('secteurs.documents', $bmn));
    $this->actingAs($utilisateur)->get(route('secteurs.documents', $bmn))->assertForbidden();
    $this->actingAs($chef)->get(route('secteurs.documents', $bmn))->assertOk()
        ->assertSeeLivewire(AssistantDocuments::class)->assertSee('Documents du secteur BMN')
        ->assertSee('⤒ Déposer des fichiers');
});

it('uploads a document and indexes it', function () {
    fauxService(12);
    $chef = membreSecteur(secteurBmn(), 'chef');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Procédure vol.pdf', 100, 'application/pdf')])
        ->assertHasNoErrors()
        ->assertSee('Procédure vol.pdf')
        ->assertSee('Prêt');

    $doc = AssistantDocument::first();
    expect($doc->nom)->toBe('Procédure vol.pdf')
        ->and($doc->user_id)->toBe($chef->id)
        ->and($doc->statut)->toBe('pret')
        ->and($doc->nb_morceaux)->toBe(12)
        ->and($doc->chemin)->toStartWith('assistant/documents/bmn/')
        ->and(Storage::disk('local')->exists($doc->chemin))->toBeTrue();
});

it('refuses formats the service does not read and files over 50 MB', function () {
    fauxService();
    $chef = membreSecteur(secteurBmn(), 'chef');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('vieux.doc', 10)])
        ->assertHasErrors(['fichiers.0'])
        ->assertSee('Formats acceptés : docx, pdf, txt');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('gros.pdf', 51 * 1024, 'application/pdf')])
        ->assertHasErrors(['fichiers.0']);

    expect(AssistantDocument::count())->toBe(0);
});

it('shows the readable error of an unreadable document and lets the chef report it', function () {
    Http::fake([
        '*/formats' => Http::response(['formats' => ['pdf']]),
        '*/documents' => Http::response(['detail' => ['code' => 'lecture',
            'message' => 'Aucun texte trouvé dans ce PDF (document scanné ?).', 'detail' => 'ValueError: vide']], 422),
    ]);
    $chef = membreSecteur(secteurBmn(), 'chef');

    $composant = Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Scan.pdf', 10, 'application/pdf')])
        ->assertSee('Aucun texte trouvé dans ce PDF (document scanné ?).')
        ->assertSee('Signaler');

    $doc = AssistantDocument::first();
    expect($doc->statut)->toBe('erreur')->and($doc->detail)->toBe('ValueError: vide');

    $composant->call('signaler', $doc->id)->assertSee('Signalé');

    $activite = Activity::where('log_name', 'assistant')->where('event', 'signalement')->first();
    expect($activite)->not->toBeNull()
        ->and($activite->causer_id)->toBe($chef->id)
        ->and($activite->properties['detail'])->toBe('ValueError: vide')
        ->and($activite->properties['secteur'])->toBe('bmn')
        ->and($doc->fresh()->signale_at)->not->toBeNull();
});

it('asks what to do when the name already exists', function () {
    fauxService();
    $chef = membreSecteur(secteurBmn(), 'chef', ['name' => 'Jean Chef']);
    docExistant('Manuel.pdf', 'pret', $chef);

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->assertSee('existe déjà ici')
        ->assertSee('Remplacer')->assertSee('Ajouter quand même')->assertSee('Annuler')
        ->call('annuler', 0)
        ->assertDontSee('existe déjà');

    expect(AssistantDocument::count())->toBe(1);
});

it('adds a second document with the same name on request', function () {
    fauxService();
    $chef = membreSecteur(secteurBmn(), 'chef');
    docExistant('Manuel.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->call('ajouterQuandMeme', 0);

    expect(AssistantDocument::where('nom', 'Manuel.pdf')->count())->toBe(2);
});

it('replaces the old document only once the new one is ready', function () {
    fauxService();
    $chef = membreSecteur(secteurBmn(), 'chef');
    $ancien = docExistant('Manuel.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->call('remplacer', 0);

    expect(AssistantDocument::find($ancien->id))->toBeNull()
        ->and(Storage::disk('local')->exists($ancien->chemin))->toBeFalse()
        ->and(AssistantDocument::where('nom', 'Manuel.pdf')->sole()->statut)->toBe('pret');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), "/documents/{$ancien->id}"));
});

it('keeps the old document when the replacement fails', function () {
    Http::fake([
        '*/formats' => Http::response(['formats' => ['pdf']]),
        '*/documents' => Http::response(['detail' => ['code' => 'lecture', 'message' => 'Illisible', 'detail' => 'x']], 422),
    ]);
    $chef = membreSecteur(secteurBmn(), 'chef');
    $ancien = docExistant('Manuel.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->call('remplacer', 0);

    expect(AssistantDocument::find($ancien->id))->not->toBeNull();
});

it('deletes a document with its vectors and file', function () {
    fauxService();
    $chef = membreSecteur(secteurBmn(), 'chef');
    $doc = docExistant();

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->call('supprimer', $doc->id)
        ->assertSee('supprimé');

    expect(AssistantDocument::find($doc->id))->toBeNull()
        ->and(Storage::disk('local')->exists($doc->chemin))->toBeFalse();
});

it('keeps the document when the service cannot delete its vectors', function () {
    Http::fake(['*/documents/*' => Http::response('', 500)]);
    $chef = membreSecteur(secteurBmn(), 'chef');
    $doc = docExistant();

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->call('supprimer', $doc->id)
        ->assertSee("L'assistant est indisponible pour le moment, réessayez plus tard.");

    expect(AssistantDocument::find($doc->id))->not->toBeNull();
});

it('forbids a simple utilisateur to use the component', function () {
    $utilisateur = membreSecteur(secteurBmn(), 'utilisateur');

    Livewire::actingAs($utilisateur)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->assertForbidden();
});

it('ignores a document of another secteur', function () {
    fauxService();
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $doc = $autre->assistantDocuments()->create(['nom' => 'A.pdf', 'chemin' => 'a', 'taille' => 1, 'format' => 'pdf', 'statut' => 'pret']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)->test(AssistantDocuments::class, ['secteur' => secteurBmn()])
        ->call('supprimer', $doc->id);

    expect(AssistantDocument::find($doc->id))->not->toBeNull();
});

it('opens a cited document for members only, inside its secteur', function () {
    $bmn = secteurBmn();
    $doc = docExistant('Manuel.pdf');
    $membre = membreSecteur($bmn, 'utilisateur');

    $this->actingAs($membre)->get(route('secteurs.documents.consulter', [$bmn, $doc]))->assertOk();
    $this->actingAs(User::factory()->create())->get(route('secteurs.documents.consulter', [$bmn, $doc]))->assertForbidden();

    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('secteurs.documents.consulter', [$autre, $doc]))->assertNotFound();
});

it('serves a .txt document as plain text, never as HTML (no stored XSS)', function () {
    $bmn = secteurBmn();
    Storage::disk('local')->put('assistant/documents/bmn/x_piege.txt', '<html><script>alert(1)</script></html>');
    $doc = $bmn->assistantDocuments()->create([
        'nom' => 'Piège.txt', 'chemin' => 'assistant/documents/bmn/x_piege.txt', 'taille' => 38, 'format' => 'txt', 'statut' => 'pret',
    ]);

    $reponse = $this->actingAs(membreSecteur($bmn, 'utilisateur'))->get(route('secteurs.documents.consulter', [$bmn, $doc]));

    $reponse->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($reponse->headers->get('Content-Disposition'))->toBe("inline; filename=Piege.txt; filename*=utf-8''Pi%C3%A8ge.txt");
});

it('serves a pdf inline and a docx as a download', function () {
    $bmn = secteurBmn();
    $membre = membreSecteur($bmn, 'utilisateur');
    $pdf = docExistant('Manuel.pdf');
    Storage::disk('local')->put('assistant/documents/bmn/x_note.docx', 'PK');
    $docx = $bmn->assistantDocuments()->create([
        'nom' => 'Note.docx', 'chemin' => 'assistant/documents/bmn/x_note.docx', 'taille' => 2, 'format' => 'docx', 'statut' => 'pret',
    ]);

    $this->actingAs($membre)->get(route('secteurs.documents.consulter', [$bmn, $pdf]))
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=Manuel.pdf');
    $this->actingAs($membre)->get(route('secteurs.documents.consulter', [$bmn, $docx]))
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'attachment; filename=Note.docx');
});

it('marks the document as failed when the service is down during indexing', function () {
    Http::fake(['*/documents' => Http::response('', 502)]);
    $doc = docExistant('Manuel.pdf', 'en_attente');

    (new IndexerDocumentAssistant($doc->id))->handle(app(\App\Services\AssistantClient::class), app(\App\Services\GestionDocumentsAssistant::class));

    expect($doc->fresh()->statut)->toBe('erreur')
        ->and($doc->fresh()->message)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.");
});

it('marks a document stuck in progress as failed when the job itself fails (timeout, crash)', function () {
    $doc = docExistant('Manuel.pdf', 'en_cours');

    (new IndexerDocumentAssistant($doc->id))->failed(new RuntimeException('boom'));

    expect($doc->fresh()->statut)->toBe('erreur')
        ->and($doc->fresh()->message)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.")
        ->and($doc->fresh()->detail)->toBe('RuntimeException: boom');
    // Le délai HTTP d'indexation expire avant que le worker ne tue le job.
    expect(config('assistant.timeout_indexation'))->toBe(570)
        ->and((new IndexerDocumentAssistant($doc->id))->timeout)->toBe(600);
});

it('ignores a job failure when the document no longer exists', function () {
    $doc = docExistant('Manuel.pdf', 'en_cours');
    $doc->delete();

    (new IndexerDocumentAssistant($doc->id))->failed(new RuntimeException('boom'));

    expect(AssistantDocument::count())->toBe(0);
});

it('tries to remove partial vectors when indexing fails', function () {
    Http::fake([
        '*/documents/*' => Http::response(['ok' => true]),
        '*/documents' => Http::response('', 503),
    ]);
    $doc = docExistant('Manuel.pdf', 'en_attente');

    (new IndexerDocumentAssistant($doc->id))->handle(app(\App\Services\AssistantClient::class), app(\App\Services\GestionDocumentsAssistant::class));

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/documents/'.$doc->id.'?secteur=bmn'));
    expect($doc->fresh()->statut)->toBe('erreur')
        ->and($doc->fresh()->message)->toBe("L'assistant est indisponible pour le moment, réessayez plus tard.");
});

it('still marks the document as failed when removing partial vectors fails too', function () {
    Http::fake(['*' => Http::response('', 503)]);
    $doc = docExistant('Manuel.pdf', 'en_attente');

    (new IndexerDocumentAssistant($doc->id))->handle(app(\App\Services\AssistantClient::class), app(\App\Services\GestionDocumentsAssistant::class));

    expect($doc->fresh()->statut)->toBe('erreur');
});

it('removes the vectors of a document deleted while it was being indexed', function () {
    $doc = docExistant('Manuel.pdf', 'en_attente');
    Http::fake(function ($r) use ($doc) {
        if ($r->method() === 'POST') {
            AssistantDocument::whereKey($doc->id)->delete();

            return Http::response(['nb_morceaux' => 4]);
        }

        return Http::response(['ok' => true]);
    });

    (new IndexerDocumentAssistant($doc->id))->handle(app(\App\Services\AssistantClient::class), app(\App\Services\GestionDocumentsAssistant::class));

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/documents/'.$doc->id.'?secteur=bmn'));
    expect(AssistantDocument::find($doc->id))->toBeNull();
});
