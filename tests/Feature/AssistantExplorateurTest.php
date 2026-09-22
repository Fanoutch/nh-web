<?php

use App\Livewire\AssistantDocuments;
use App\Models\AssistantDocument;
use App\Models\AssistantDossier;
use App\Models\Secteur;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Onglet Documents comme explorateur de fichiers : arborescence, fil d'Ariane, dépôt dans le dossier
 * ouvert, déplacement par glisser-déposer, création/renommage/suppression de dossiers.
 * Les helpers document() et fauxService() viennent de AssistantDossiersTest.php / AssistantDocumentsTest.php
 * (chargés par Pest dans le même processus : toute fonction globale déclarée dans un fichier de test est
 * disponible dans les autres).
 */
beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
    $this->secteur = secteurBmn();
    $this->chef = membreSecteur($this->secteur, 'chef');
    $this->utilisateur = membreSecteur($this->secteur, 'utilisateur');
});

/** Crée un dossier de test dans le secteur (et éventuellement sous un parent) donné. */
function dossier(Secteur $secteur, string $nom, ?AssistantDossier $parent = null): AssistantDossier
{
    return AssistantDossier::create(['secteur_id' => $secteur->id, 'parent_id' => $parent?->id, 'nom' => $nom]);
}

it('affiche le contenu du dossier ouvert et son fil d’Ariane', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $p = dossier($secteur, 'Procédures');
    $v = dossier($secteur, 'Vols', $p);
    document($secteur, nom: '40.07.pdf', dossier: $v);
    document($secteur, nom: 'ailleurs.pdf');                   // racine : ne doit pas apparaître

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier', $v->id)
        ->assertSee('40.07.pdf')->assertDontSee('ailleurs.pdf')
        ->assertSee('Procédures');                              // fil d'Ariane
});

it('déplace un document dans un autre dossier', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $doc = document($secteur);
    $p = dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('deplacer', 'document', $doc->id, $p->id);

    expect($doc->fresh()->dossier_id)->toBe($p->id);
});

it('interdit l’onglet à un utilisateur simple', function () {
    $secteur = $this->secteur;
    $utilisateur = $this->utilisateur;
    Livewire::actingAs($utilisateur)->test(AssistantDocuments::class, ['secteur' => $secteur])->assertForbidden();
});

it('dépose le fichier dans le dossier ouvert', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    Http::fake(['*/formats' => Http::response(['formats' => ['pdf']])]);
    $p = dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier', $p->id)
        ->set('fichiers', [UploadedFile::fake()->create('note.pdf', 10)]);

    expect(AssistantDocument::first()->dossier_id)->toBe($p->id);
});

// --- Création / renommage / suppression de dossiers -------------------------

it('crée un dossier dans le dossier ouvert', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $parent = dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier', $parent->id)
        ->set('nouveauNom', 'Vols')
        ->call('creerDossier')
        ->assertSee('Vols')
        ->assertSee('créé');

    $enfant = AssistantDossier::where('nom', 'Vols')->sole();
    expect($enfant->parent_id)->toBe($parent->id);
});

it('refuse de créer un dossier en doublon et affiche l’erreur', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->set('nouveauNom', 'procedures')
        ->call('creerDossier')
        ->assertSee('Un dossier « procedures » existe déjà ici.');

    expect(AssistantDossier::count())->toBe(1);
});

it('renomme un dossier', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $d = dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('renommerDossier', $d->id, 'Procédures 2')
        ->assertSee('renommé');

    expect($d->fresh()->nom)->toBe('Procédures 2');
});

it('affiche un message de confirmation détaillé puis supprime le dossier avec tout son contenu', function () {
    Http::fake();
    $secteur = $this->secteur;
    $chef = $this->chef;
    $p = dossier($secteur, 'Procédures');
    $v = dossier($secteur, 'Vols', $p);
    document($secteur, dossier: $v, nom: 'A.pdf');
    document($secteur, dossier: $p, nom: 'B.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier') // racine : Procédures y est listé comme sous-dossier
        ->assertSee('Supprimer le dossier « Procédures » et tout son contenu (1 sous-dossier, 2 documents) ?')
        ->call('supprimerDossier', $p->id)
        ->assertSee('supprimé');

    expect(AssistantDossier::count())->toBe(0)
        ->and(AssistantDocument::count())->toBe(0);
});

it('déplace un dossier vers un autre dossier', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $a = dossier($secteur, 'A');
    $b = dossier($secteur, 'B');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('deplacer', 'dossier', $b->id, $a->id);

    expect($b->fresh()->parent_id)->toBe($a->id);
});

// --- Document en cours de préparation ---------------------------------------

it('interdit d’ouvrir ou de déplacer un document en cours de préparation', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $doc = document($secteur, nom: 'En cours.pdf');
    $doc->update(['statut' => AssistantDocument::EN_COURS]);
    $dest = dossier($secteur, 'Procédures');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->assertDontSee(route('secteurs.documents.consulter', [$secteur, $doc]), false)
        ->call('deplacer', 'document', $doc->id, $dest->id)
        ->assertSee('Ce document est en cours de préparation : réessayez dans un instant.');

    expect($doc->fresh()->dossier_id)->toBeNull();
});

// --- Conflit de nom scopé au dossier ouvert (et non plus à tout le secteur) ------

it('accepte deux documents de même nom dans des dossiers différents lors du dépôt', function () {
    fauxService();
    $secteur = $this->secteur;
    $chef = $this->chef;
    $ailleurs = dossier($secteur, 'Ailleurs');
    document($secteur, dossier: $ailleurs, nom: 'Manuel.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->assertDontSee('existe déjà');

    expect(AssistantDocument::where('nom', 'Manuel.pdf')->count())->toBe(2);
});

it('détecte un conflit de nom dans le dossier ouvert', function () {
    fauxService();
    $secteur = $this->secteur;
    $chef = $this->chef;
    $p = dossier($secteur, 'Procédures');
    document($secteur, dossier: $p, nom: 'Manuel.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier', $p->id)
        ->set('fichiers', [UploadedFile::fake()->create('Manuel.pdf', 10, 'application/pdf')])
        ->assertSee('existe déjà ici');

    expect(AssistantDocument::where('nom', 'Manuel.pdf')->count())->toBe(1);
});

// --- Sécurité : jamais d'action sur un dossier d'un autre secteur ---------------

it('n’ouvre pas un dossier d’un autre secteur', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $dossierAutre = dossier($autre, 'Ailleurs');
    document($autre, dossier: $dossierAutre, nom: 'Secret.pdf');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('ouvrirDossier', $dossierAutre->id)
        ->assertDontSee('Secret.pdf')
        ->assertDontSee('Ailleurs');
});

it('ignore les actions de dossier ciblant un dossier d’un autre secteur', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $dossierAutre = dossier($autre, 'Ailleurs');

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('renommerDossier', $dossierAutre->id, 'Pirate')
        ->call('deplacer', 'dossier', $dossierAutre->id, null)
        ->call('supprimerDossier', $dossierAutre->id);

    expect(AssistantDossier::find($dossierAutre->id))->not->toBeNull();
    expect($dossierAutre->fresh()->nom)->toBe('Ailleurs')
        ->and($dossierAutre->fresh()->parent_id)->toBeNull();
});

// --- Ronde de correction 1 ---------------------------------------------------

it('donne à chaque nœud de l’arbre une clé stable basée sur l’identifiant du dossier', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $a = dossier($secteur, 'Alpha');
    $b = dossier($secteur, 'Beta');
    $enfant = dossier($secteur, 'Enfant', $a);

    // Racine et enfant utilisent le même composant récursif : la clé doit être présente aux
    // deux niveaux, pour que renommer un dossier (donc changer son rang alphabétique entre
    // frères) ne fasse pas glisser l'état plié/déplié ou le glisser-déposer d'un nœud vers un
    // autre lors du re-rendu (Livewire réconcilierait sinon par position).
    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->assertSeeHtml("wire:key=\"dossier-noeud-{$a->id}\"")
        ->assertSeeHtml("wire:key=\"dossier-noeud-{$b->id}\"")
        ->assertSeeHtml("wire:key=\"dossier-noeud-{$enfant->id}\"");
});

it('ne prétend pas avoir réussi quand deplacer() reçoit un type inconnu', function () {
    $secteur = $this->secteur;
    $chef = $this->chef;
    $doc = document($secteur);

    Livewire::actingAs($chef)->test(AssistantDocuments::class, ['secteur' => $secteur])
        ->call('deplacer', 'autre-chose', $doc->id, null)
        ->assertNotDispatched('action-dossier-reussie')
        ->assertSet('notice', null)
        ->assertSet('erreur', null);

    expect($doc->fresh()->dossier_id)->toBeNull();
});
