<?php

use App\Models\AssistantDocument;
use App\Services\AssistantClient;
use App\Services\AssistantIndisponible;
use App\Services\DocumentIllisible;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function documentDeTest(): AssistantDocument
{
    return secteurBmn()->assistantDocuments()->create([
        'nom' => 'Manuel.pdf', 'chemin' => 'assistant/documents/bmn/7_manuel.pdf', 'taille' => 10, 'format' => 'pdf', 'statut' => 'en_attente',
    ]);
}

beforeEach(fn () => config(['assistant.jeton' => 'secret']));

it('gets the accepted formats with the token and caches them', function () {
    Http::fake(['*/formats' => Http::response(['formats' => ['docx', 'pdf', 'txt']])]);

    expect(app(AssistantClient::class)->formats())->toBe(['docx', 'pdf', 'txt'])
        ->and(app(AssistantClient::class)->formats())->toBe(['docx', 'pdf', 'txt']);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8765/formats'
        && $r->header('X-Assistant-Jeton') === ['secret']);
});

it('reports the service as unavailable when it cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    app(AssistantClient::class)->formats();
})->throws(AssistantIndisponible::class, "L'assistant est indisponible pour le moment, réessayez plus tard.");

it('does not cache a failed formats call', function () {
    Http::fake(['*/formats' => Http::response([], 500)]);
    try {
        app(AssistantClient::class)->formats();
    } catch (AssistantIndisponible) {
    }

    expect(Cache::has('assistant.formats'))->toBeFalse();
});

it('indexes a document and returns the chunk count', function () {
    Http::fake(['*/documents' => Http::response(['nb_morceaux' => 12])]);
    $doc = documentDeTest();

    expect(app(AssistantClient::class)->indexer($doc))->toBe(12);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['secteur'] === 'bmn' && $r['document_id'] === $doc->id
        && $r['nom'] === 'Manuel.pdf' && str_ends_with($r['chemin'], 'assistant/documents/bmn/7_manuel.pdf'));
});

it('turns a 422 into a readable DocumentIllisible', function () {
    Http::fake(['*/documents' => Http::response(['detail' => [
        'code' => 'lecture', 'message' => 'Aucun texte trouvé dans ce PDF (document scanné ?).', 'detail' => 'ValueError: vide',
    ]], 422)]);

    try {
        app(AssistantClient::class)->indexer(documentDeTest());
        $this->fail('DocumentIllisible attendue');
    } catch (DocumentIllisible $e) {
        expect($e->getMessage())->toBe('Aucun texte trouvé dans ce PDF (document scanné ?).')
            ->and($e->detail)->toBe('ValueError: vide');
    }
});

it('turns a 503 into AssistantIndisponible with the technical detail', function () {
    Http::fake(['*/documents' => Http::response(['detail' => [
        'code' => 'modele', 'message' => "L'assistant est indisponible pour le moment, réessayez plus tard.", 'detail' => 'HF 503',
    ]], 503)]);

    try {
        app(AssistantClient::class)->indexer(documentDeTest());
        $this->fail('AssistantIndisponible attendue');
    } catch (AssistantIndisponible $e) {
        expect($e->detail)->toContain('HF 503');
    }
});

it('deletes the vectors of a document', function () {
    Http::fake(['*/documents/*' => Http::response(['ok' => true])]);
    $doc = documentDeTest();

    app(AssistantClient::class)->supprimer($doc);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
        && $r->url() === "http://127.0.0.1:8765/documents/{$doc->id}?secteur=bmn");
});

it('streams the answer events line by line', function () {
    Http::fake(['*/questions' => Http::response(
        '{"type":"sources","sources":[{"document_id":1,"nom":"M.pdf","page":2}]}'."\n"
        .'{"type":"texte","texte":"Le vol "}'."\n"
        .'{"type":"texte","texte":"est ordonné."}'."\n"
        .'{"type":"fin"}',
        200, ['Content-Type' => 'application/x-ndjson'],
    )]);
    $evenements = [];

    app(AssistantClient::class)->poserQuestion(secteurBmn(), 'Qui ?', [['role' => 'user', 'content' => 'Avant']],
        function (array $e) use (&$evenements) { $evenements[] = $e; });

    expect(array_column($evenements, 'type'))->toBe(['sources', 'texte', 'texte', 'fin'])
        ->and($evenements[2]['texte'])->toBe('est ordonné.');
    Http::assertSent(fn (Request $r) => $r['secteur'] === 'bmn' && $r['nom_secteur'] === 'BMN'
        && $r['question'] === 'Qui ?' && $r['historique'] === [['role' => 'user', 'content' => 'Avant']]);
});

it('reports an unavailable service for a question', function () {
    Http::fake(['*/questions' => Http::response('', 500)]);

    app(AssistantClient::class)->poserQuestion(secteurBmn(), 'Qui ?', [], fn () => null);
})->throws(AssistantIndisponible::class);

it('reports a stream that ends without its final event', function () {
    Http::fake(['*/questions' => Http::response(
        '{"type":"sources","sources":[]}'."\n"
        .'{"type":"texte","texte":"Début "}'."\n"
        .'{"type":"tex',
        200,
    )]);
    $evenements = [];

    try {
        app(AssistantClient::class)->poserQuestion(secteurBmn(), 'Qui ?', [],
            function (array $e) use (&$evenements) { $evenements[] = $e; });
        $this->fail('AssistantIndisponible attendue');
    } catch (AssistantIndisponible $e) {
        expect($e->detail)->toContain('fin de réponse manquante');
    }

    expect(array_column($evenements, 'type'))->toBe(['sources', 'texte']);
});
