<?php

use App\Models\AssistantDocument;
use App\Models\AssistantDossier;
use App\Models\Secteur;
use App\Services\ActionDossierRefusee;
use App\Services\AssistantIndisponible;
use App\Services\GestionDossiersAssistant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

it('construit le chemin affichable et la profondeur', function () {
    $secteur = secteurBmn();
    $procedures = AssistantDossier::create(['secteur_id' => $secteur->id, 'parent_id' => null, 'nom' => 'Procédures']);
    $vols = AssistantDossier::create(['secteur_id' => $secteur->id, 'parent_id' => $procedures->id, 'nom' => 'Vols']);

    expect($procedures->chemin())->toBe('Procédures')
        ->and($vols->chemin())->toBe('Procédures › Vols')
        ->and($vols->profondeur())->toBe(2)
        ->and($procedures->descendants()->pluck('id')->all())->toBe([$vols->id]);
});

// --- GestionDossiersAssistant ----------------------------------------------

beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
    $this->secteur = secteurBmn();
    $this->gestion = app(GestionDossiersAssistant::class);
});

/** Crée un document de test dans le secteur (et éventuellement le dossier) donné — helper local à ce fichier. */
function document(Secteur $secteur, ?AssistantDossier $dossier = null, string $nom = 'Doc.pdf'): AssistantDocument
{
    $chemin = "assistant/documents/{$secteur->slug}/x_{$nom}";
    Storage::disk('local')->put($chemin, 'contenu');

    return AssistantDocument::create([
        'secteur_id' => $secteur->id,
        'dossier_id' => $dossier?->id,
        'nom' => $nom,
        'chemin' => $chemin,
        'taille' => 7,
        'format' => 'pdf',
        'statut' => AssistantDocument::PRET,
    ]);
}

// --- creer -------------------------------------------------------------

it('creates a folder at the root of a sector', function () {
    $dossier = $this->gestion->creer($this->secteur, null, 'Procédures');

    expect($dossier->secteur_id)->toBe($this->secteur->id)
        ->and($dossier->parent_id)->toBeNull()
        ->and($dossier->nom)->toBe('Procédures')
        ->and($dossier->chemin())->toBe('Procédures');
});

it('creates a nested folder', function () {
    $parent = $this->gestion->creer($this->secteur, null, 'Procédures');
    $enfant = $this->gestion->creer($this->secteur, $parent->id, 'Vols');

    expect($enfant->parent_id)->toBe($parent->id)
        ->and($enfant->chemin())->toBe('Procédures › Vols');
});

it('refuse un nom de dossier vide ou trop long', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;

    expect(fn () => $gestion->creer($secteur, null, ''))
        ->toThrow(ActionDossierRefusee::class, 'Le nom du dossier doit faire entre 1 et 100 caractères.');

    expect(fn () => $gestion->creer($secteur, null, str_repeat('a', 101)))
        ->toThrow(ActionDossierRefusee::class, 'Le nom du dossier doit faire entre 1 et 100 caractères.');
});

it('refuse le caractère « › » dans un nom de dossier, à la création comme au renommage', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;

    expect(fn () => $gestion->creer($secteur, null, 'Procédures › Vols'))
        ->toThrow(ActionDossierRefusee::class, 'Le nom du dossier ne peut pas contenir le caractère « › ».');

    $dossier = $gestion->creer($secteur, null, 'Procédures');
    expect(fn () => $gestion->renommer($dossier, 'Autre › Nom'))
        ->toThrow(ActionDossierRefusee::class, 'Le nom du dossier ne peut pas contenir le caractère « › ».');
});

it('refuse un doublon de nom dans le même dossier', function () {
    $secteur = $this->secteur;
    $gestion = $this->gestion;
    $gestion->creer($secteur, null, 'Procédures');

    expect(fn () => $gestion->creer($secteur, null, 'procedures'))
        ->toThrow(ActionDossierRefusee::class, 'Un dossier « procedures » existe déjà ici.');
});

it('accepte deux dossiers de même nom dans des parents différents', function () {
    $secteur = $this->secteur;
    $gestion = $this->gestion;
    $a = $gestion->creer($secteur, null, 'A');
    $b = $gestion->creer($secteur, null, 'B');

    $sousA = $gestion->creer($secteur, $a->id, 'Vols');
    $sousB = $gestion->creer($secteur, $b->id, 'Vols');

    expect($sousA->id)->not->toBe($sousB->id);
});

it('refuse de créer un dossier trop profond (plus de 10 niveaux)', function () {
    $secteur = $this->secteur;
    $gestion = $this->gestion;

    $parentId = null;
    for ($i = 1; $i <= 10; $i++) {
        $dossier = $gestion->creer($secteur, $parentId, "Niveau {$i}");
        $parentId = $dossier->id;
    }

    expect(fn () => $gestion->creer($secteur, $parentId, 'Niveau 11'))
        ->toThrow(ActionDossierRefusee::class, 'Arborescence trop profonde (10 niveaux maximum).');
});

it("refuse de créer un dossier dans un dossier introuvable ou d'un autre secteur", function () {
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $dossierAutre = $this->gestion->creer($autre, null, 'Ailleurs');

    expect(fn () => $this->gestion->creer($this->secteur, $dossierAutre->id, 'X'))
        ->toThrow(ActionDossierRefusee::class, 'Dossier de destination introuvable.');

    expect(fn () => $this->gestion->creer($this->secteur, 999999, 'X'))
        ->toThrow(ActionDossierRefusee::class, 'Dossier de destination introuvable.');
});

// --- renommer ------------------------------------------------------------

it('renomme un dossier', function () {
    $dossier = $this->gestion->creer($this->secteur, null, 'Procédures');

    $this->gestion->renommer($dossier, 'Procédures 2');

    expect($dossier->fresh()->nom)->toBe('Procédures 2');
});

it('refuse de renommer un dossier avec un nom qui existe déjà à côté', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $gestion->creer($secteur, null, 'Procédures');
    $vols = $gestion->creer($secteur, null, 'Vols');

    expect(fn () => $gestion->renommer($vols, 'procedures'))
        ->toThrow(ActionDossierRefusee::class, 'Un dossier « procedures » existe déjà ici.');
});

it('autorise à renommer un dossier avec son propre nom (inchangé)', function () {
    $dossier = $this->gestion->creer($this->secteur, null, 'Procédures');

    $this->gestion->renommer($dossier, 'Procédures');

    expect($dossier->fresh()->nom)->toBe('Procédures');
});

// --- deplacerDossier -------------------------------------------------------

it('déplace un dossier vers un autre dossier', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $a = $gestion->creer($secteur, null, 'A');
    $b = $gestion->creer($secteur, null, 'B');

    $gestion->deplacerDossier($b, $a->id);

    expect($b->fresh()->parent_id)->toBe($a->id);
});

it('déplace un dossier vers la racine', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $a = $gestion->creer($secteur, null, 'A');
    $b = $gestion->creer($secteur, $a->id, 'B');

    $gestion->deplacerDossier($b, null);

    expect($b->fresh()->parent_id)->toBeNull();
});

it('refuse de déplacer un dossier dans lui-même', function () {
    $p = $this->gestion->creer($this->secteur, null, 'Procédures');

    expect(fn () => $this->gestion->deplacerDossier($p, $p->id))
        ->toThrow(ActionDossierRefusee::class, 'Un dossier ne peut pas être déplacé dans lui-même.');
});

it('refuse de déplacer un dossier dans son propre sous-dossier', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $p = $gestion->creer($secteur, null, 'Procédures');
    $v = $gestion->creer($secteur, $p->id, 'Vols');

    expect(fn () => $gestion->deplacerDossier($p, $v->id))
        ->toThrow(ActionDossierRefusee::class, 'Un dossier ne peut pas être déplacé dans lui-même.');
});

it('refuse de déplacer un dossier si cela dépasse 10 niveaux', function () {
    $secteur = $this->secteur;
    $gestion = $this->gestion;

    // Une branche de 10 niveaux.
    $parentId = null;
    for ($i = 1; $i <= 10; $i++) {
        $dossier = $gestion->creer($secteur, $parentId, "Niveau {$i}");
        $parentId = $dossier->id;
    }

    // Un dossier à déplacer, avec un sous-dossier (2 niveaux relatifs).
    $aDeplacer = $gestion->creer($secteur, null, 'À déplacer');
    $gestion->creer($secteur, $aDeplacer->id, 'Sous-dossier');

    expect(fn () => $gestion->deplacerDossier($aDeplacer, $dossier->id))
        ->toThrow(ActionDossierRefusee::class, 'Arborescence trop profonde (10 niveaux maximum).');
});

it("refuse de déplacer un dossier vers un dossier introuvable ou d'un autre secteur", function () {
    $dossier = $this->gestion->creer($this->secteur, null, 'Procédures');
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $dossierAutre = $this->gestion->creer($autre, null, 'Ailleurs');

    expect(fn () => $this->gestion->deplacerDossier($dossier, $dossierAutre->id))
        ->toThrow(ActionDossierRefusee::class, 'Dossier de destination introuvable.');
});

// --- deplacerDocument -----------------------------------------------------

it('déplace un document vers un autre dossier', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $dossier = $gestion->creer($secteur, null, 'Procédures');
    $doc = document($secteur);

    $gestion->deplacerDocument($doc, $dossier->id);

    expect($doc->fresh()->dossier_id)->toBe($dossier->id);
});

it('refuse de déplacer un document vers un dossier qui contient déjà ce nom', function () {
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $dossier = $gestion->creer($secteur, null, 'Procédures');
    document($secteur, dossier: $dossier, nom: 'Manuel.pdf');
    $doc = document($secteur, nom: 'manuel.pdf');

    expect(fn () => $gestion->deplacerDocument($doc, $dossier->id))
        ->toThrow(ActionDossierRefusee::class, 'Un document « manuel.pdf » existe déjà dans ce dossier.');
});

it("refuse de déplacer un document vers un dossier introuvable ou d'un autre secteur", function () {
    $secteur = $this->secteur;
    $doc = document($secteur);
    $autre = Secteur::create(['slug' => 'test', 'nom' => 'Test']);
    $dossierAutre = $this->gestion->creer($autre, null, 'Ailleurs');

    expect(fn () => $this->gestion->deplacerDocument($doc, $dossierAutre->id))
        ->toThrow(ActionDossierRefusee::class, 'Dossier de destination introuvable.');
});

// --- compter / supprimer --------------------------------------------------

it('compte les sous-dossiers et documents du sous-arbre', function () {
    Http::fake();
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $p = $gestion->creer($secteur, null, 'Procédures');
    $v = $gestion->creer($secteur, $p->id, 'Vols');
    document($secteur, dossier: $v);
    document($secteur, dossier: $p);

    expect($gestion->compter($p))->toBe(['dossiers' => 1, 'documents' => 2]);
});

it('supprime un dossier avec tout son contenu', function () {
    Http::fake();
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $p = $gestion->creer($secteur, null, 'Procédures');
    $v = $gestion->creer($secteur, $p->id, 'Vols');
    $doc = document($secteur, dossier: $v);

    expect($gestion->compter($p))->toBe(['dossiers' => 1, 'documents' => 1]);
    $resultat = $gestion->supprimer($p);

    expect($resultat)->toBe(['dossiers' => 1, 'documents' => 1])
        ->and(AssistantDossier::count())->toBe(0)
        ->and(AssistantDocument::find($doc->id))->toBeNull();

    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), "/documents/{$doc->id}"));

    $activite = Activity::where('log_name', 'assistant')->where('event', 'suppression_dossier')->first();
    expect($activite)->not->toBeNull()
        ->and($activite->description)->toBe('Dossier Procédures supprimé (1 sous-dossiers, 1 documents)')
        ->and($activite->properties['secteur'])->toBe('bmn')
        ->and($activite->properties['dossier'])->toBe('Procédures')
        ->and($activite->properties['sous_dossiers'])->toBe(1)
        ->and($activite->properties['documents'])->toBe(1);
});

it('supprime un dossier vide', function () {
    Http::fake();
    $p = $this->gestion->creer($this->secteur, null, 'Vide');

    $resultat = $this->gestion->supprimer($p);

    expect($resultat)->toBe(['dossiers' => 0, 'documents' => 0])
        ->and(AssistantDossier::count())->toBe(0);
});

it('ne supprime rien si le service est indisponible', function () {
    Http::fake(fn () => throw new ConnectionException('service arrêté'));
    $gestion = $this->gestion;
    $secteur = $this->secteur;
    $p = $gestion->creer($secteur, null, 'Procédures');
    document($secteur, dossier: $p);

    expect(fn () => $gestion->supprimer($p))->toThrow(AssistantIndisponible::class);
    expect(AssistantDossier::count())->toBe(1);
});

it('interrompt la suppression avant tout dossier supprimé si le service tombe en cours de route', function () {
    $secteur = $this->secteur;
    $gestion = $this->gestion;
    $p = $gestion->creer($secteur, null, 'Procédures');
    $premier = document($secteur, dossier: $p, nom: 'Premier.pdf');
    $second = document($secteur, dossier: $p, nom: 'Second.pdf');

    Http::fake([
        '*/documents/'.$premier->id.'*' => Http::response(['ok' => true]),
        '*/documents/'.$second->id.'*' => Http::response('', 500),
    ]);

    expect(fn () => $gestion->supprimer($p))->toThrow(AssistantIndisponible::class);

    // Aucun dossier n'a été supprimé en base, même si un document a déjà pu l'être : un secteur ne doit
    // jamais se retrouver avec des vecteurs orphelins, mais l'ordre documents-puis-dossiers reste respecté.
    expect(AssistantDossier::count())->toBe(1)
        ->and(AssistantDocument::count())->toBe(1);
});
