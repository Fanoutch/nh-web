<?php

use App\Models\AssistantConversation;
use App\Models\AssistantDocument;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\FichierEnv;
use Illuminate\Database\QueryException;

it('stores documents, conversations and messages of a secteur', function () {
    $bmn = secteurBmn();
    $chef = membreSecteur($bmn, 'chef');
    $doc = $bmn->assistantDocuments()->create([
        'user_id' => $chef->id, 'nom' => 'Manuel.pdf', 'chemin' => 'assistant/documents/bmn/1_manuel.pdf',
        'taille' => 1234, 'format' => 'pdf', 'statut' => AssistantDocument::EN_ATTENTE,
    ]);
    $conversation = $bmn->assistantConversations()->create(['user_id' => $chef->id, 'titre' => 'Vol de maintenance']);
    $message = $conversation->messages()->create([
        'role' => AssistantMessage::ASSISTANT, 'contenu' => 'Le chef.', 'statut' => AssistantMessage::COMPLET,
        'sources' => [['document_id' => $doc->id, 'nom' => 'Manuel.pdf', 'page' => 3]],
    ]);

    expect($doc->secteur->is($bmn))->toBeTrue()
        ->and($doc->user->is($chef))->toBeTrue()
        ->and($doc->cheminAbsolu())->toEndWith('assistant/documents/bmn/1_manuel.pdf')
        ->and($message->fresh()->sources)->toBe([['document_id' => $doc->id, 'nom' => 'Manuel.pdf', 'page' => 3]])
        ->and($conversation->messages()->count())->toBe(1);
});

it('deletes messages with their conversation and conversations with their user', function () {
    $user = membreSecteur(secteurBmn(), 'utilisateur');
    $conversation = AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T']);
    $conversation->messages()->create(['role' => 'utilisateur', 'contenu' => 'Q', 'statut' => 'complet']);

    $conversation->delete();
    expect(AssistantMessage::count())->toBe(0);

    AssistantConversation::create(['user_id' => $user->id, 'secteur_id' => secteurBmn()->id, 'titre' => 'T2']);
    $user->delete();
    expect(AssistantConversation::count())->toBe(0);
});

it('keeps a document when its uploader is deleted', function () {
    $chef = membreSecteur(secteurBmn(), 'chef');
    $doc = secteurBmn()->assistantDocuments()->create([
        'user_id' => $chef->id, 'nom' => 'M.pdf', 'chemin' => 'x', 'taille' => 1, 'format' => 'pdf', 'statut' => 'pret',
    ]);

    $chef->delete();

    expect($doc->fresh()->user_id)->toBeNull();
});

it('refuses to delete a secteur that still has documents', function () {
    $secteur = \App\Models\Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $secteur->assistantDocuments()->create(['nom' => 'M.pdf', 'chemin' => 'x', 'taille' => 1, 'format' => 'pdf', 'statut' => 'pret']);

    $secteur->delete();
})->throws(QueryException::class);

it('reads an env file like llm.env', function () {
    $chemin = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($chemin, "# commentaire\nASSISTANT_PORT=9000\nASSISTANT_JETON='abc' # fin\nVIDE=\n");

    expect(FichierEnv::lire($chemin))->toBe(['ASSISTANT_PORT' => '9000', 'ASSISTANT_JETON' => 'abc', 'VIDE' => ''])
        ->and(FichierEnv::lire('/chemin/absent'))->toBe([]);

    unlink($chemin);
});

it('exposes the assistant configuration with defaults', function () {
    expect(config('assistant.url'))->toBe('http://127.0.0.1:8765')
        ->and(config('assistant.taille_max_mo'))->toBe(50)
        ->and(config('assistant.historique_tours'))->toBe(3)
        ->and(config('assistant.timeout'))->toBe(180)
        ->and(config('assistant.timeout_indexation'))->toBe(600);
});
