<?php

use App\Livewire\AdminUsersTable;
use App\Livewire\AgentsPage;
use App\Models\Agent;
use App\Models\User;
use App\Services\AssignationAgents;
use App\Services\AssignationImpossible;
use App\Services\CatalogueAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->dossier = sys_get_temp_dir().'/catalogue-test-'.bin2hex(random_bytes(4));
    mkdir($this->dossier.'/commande', 0777, true);
    config(['agents.catalogue_definitions' => $this->dossier]);
    $this->ecrire = function (array $definition) {
        file_put_contents($this->dossier.'/commande/agent.php', '<?php return '.var_export($definition, true).';');
    };
    ($this->ecrire)([
        'nom' => 'Commande fournisseur', 'description' => 'Prépare une commande.', 'consignes' => 'Version 1.',
        'outils' => ['excel_lire'], 'recette' => null, 'references' => ['modele.docx'],
    ]);
    Storage::disk('local')->put('catalogue-agents/commande/references/modele.docx', 'contenu du formulaire');
});

afterEach(function () {
    array_map('unlink', glob($this->dossier.'/*/*') ?: []);
    @rmdir($this->dossier.'/commande');
    @rmdir($this->dossier);
});

function superAdminAgents(): User
{
    return User::factory()->create(['is_super_admin' => true]);
}

it('lists the catalogue agents from the definition files', function () {
    expect(array_keys(app(CatalogueAgents::class)->tous()))->toBe(['commande']);
});

it('gives a person a private copy with the references', function () {
    $personne = User::factory()->create();

    $copie = app(AssignationAgents::class)->assigner('commande', $personne);

    expect($copie->user_id)->toBe($personne->id)
        ->and($copie->modele_slug)->toBe('commande')
        ->and($copie->etat)->toBe(Agent::ACTIF)
        ->and($copie->consignes)->toBe('Version 1.')
        ->and($copie->fichiersReference()->pluck('nom')->all())->toBe(['modele.docx'])
        ->and(Storage::disk('local')->get($copie->dossier().'/references/modele.docx'))->toBe('contenu du formulaire');
});

it('does not duplicate a copy when assigned twice', function () {
    $personne = User::factory()->create();
    app(AssignationAgents::class)->assigner('commande', $personne);
    app(AssignationAgents::class)->assigner('commande', $personne);

    expect(Agent::where('modele_slug', 'commande')->count())->toBe(1);
});

it('refuses to assign when a reference file is missing from the server, and says where to put it', function () {
    Storage::disk('local')->delete('catalogue-agents/commande/references/modele.docx');

    expect(fn () => app(AssignationAgents::class)->assigner('commande', User::factory()->create()))
        ->toThrow(AssignationImpossible::class, 'modele.docx');
    expect(Agent::count())->toBe(0);
});

it('assigns by e-mail, ignoring case, and reports unknown addresses without assigning them', function () {
    $a = User::factory()->create(['email' => 'anne@exemple.fr', 'name' => 'Anne']);

    $r = app(AssignationAgents::class)->assignerParEmails('commande', 'ANNE@exemple.fr, inconnu@exemple.fr');

    expect($r['assignes'])->toBe(['Anne'])->and($r['inconnus'])->toBe(['inconnu@exemple.fr'])
        ->and(Agent::where('user_id', $a->id)->count())->toBe(1);
});

it('keeps the history when an agent is withdrawn, and hides it from the person', function () {
    $personne = User::factory()->create();
    $copie = app(AssignationAgents::class)->assigner('commande', $personne);

    app(AssignationAgents::class)->retirer($copie);

    expect($personne->agents()->count())->toBe(0)
        ->and(Agent::find($copie->id))->not->toBeNull()
        ->and($personne->can('view', $copie->fresh()))->toBeFalse();
    app(AssignationAgents::class)->assigner('commande', $personne);      // réassigné : la même copie revient
    expect($personne->agents()->count())->toBe(1)->and(Agent::where('modele_slug', 'commande')->count())->toBe(1);
});

it('flags copies to update after the definition changes, and updates them without touching the person files', function () {
    $personne = User::factory()->create();
    $copie = app(AssignationAgents::class)->assigner('commande', $personne);
    $copie->tousFichiers()->create(['type' => 'apport', 'nom' => 'mes-donnees.xlsx', 'chemin' => 'x', 'taille' => 1]);
    expect(app(CatalogueAgents::class)->nombreAMettreAJour('commande'))->toBe(0);

    ($this->ecrire)(['nom' => 'Commande fournisseur', 'description' => '', 'consignes' => 'Version 2.', 'references' => ['modele.docx']]);
    expect(app(CatalogueAgents::class)->nombreAMettreAJour('commande'))->toBe(1);

    expect(app(AssignationAgents::class)->mettreAJourCopies('commande'))->toBe(1);
    expect($copie->fresh()->consignes)->toBe('Version 2.')
        ->and(app(CatalogueAgents::class)->nombreAMettreAJour('commande'))->toBe(0)
        ->and($copie->apports()->pluck('nom')->all())->toBe(['mes-donnees.xlsx']);
});

it('lets the person view and launch a copy but not edit or delete it', function () {
    $personne = User::factory()->create();
    $copie = app(AssignationAgents::class)->assigner('commande', $personne);

    expect($personne->can('view', $copie))->toBeTrue()
        ->and($personne->can('lancer', $copie))->toBeTrue()
        ->and($personne->can('update', $copie))->toBeFalse()
        ->and($personne->can('delete', $copie))->toBeFalse()
        ->and($copie->outilsAutorises())->not->toContain('agent_proposer');
});

it('keeps two people copies fully separate', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $copieA = app(AssignationAgents::class)->assigner('commande', $a);
    $copieB = app(AssignationAgents::class)->assigner('commande', $b);

    expect($copieA->dossier())->not->toBe($copieB->dossier())
        ->and($b->can('view', $copieA))->toBeFalse();
});

it('only lets a super admin assign agents from the users page', function () {
    $personne = User::factory()->create(['email' => 'p@exemple.fr']);
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)->test(AdminUsersTable::class)
        ->set('saisieMails', 'p@exemple.fr')->call('assignerParMail', 'commande');
    expect(Agent::count())->toBe(0);

    Livewire::actingAs(superAdminAgents())->test(AdminUsersTable::class)
        ->set('saisieMails', 'p@exemple.fr')->call('assignerParMail', 'commande');
    expect($personne->agents()->count())->toBe(1);
});

it('lets the super admin test an agent on their own account', function () {
    $moi = superAdminAgents();

    Livewire::actingAs($moi)->test(AdminUsersTable::class)->call('tester', 'commande');

    $copie = $moi->agents()->where('modele_slug', 'commande')->first();
    expect($copie)->not->toBeNull();
    $this->actingAs($moi)->get(route('agents.show', $copie))->assertOk()->assertSee('Fourni par l');
});

it('shows the catalogue tab to the super admin with the copies to update', function () {
    $moi = superAdminAgents();
    app(AssignationAgents::class)->assigner('commande', User::factory()->create());
    ($this->ecrire)(['nom' => 'Commande fournisseur', 'description' => '', 'consignes' => 'Version 2.', 'references' => ['modele.docx']]);

    Livewire::actingAs($moi)->test(AdminUsersTable::class)->call('ouvrirCatalogue')
        ->assertSee('Commande fournisseur')->assertSee('1 copie à mettre à jour');
});

it('does not let a copy be changed through the chat', function () {
    $personne = User::factory()->create();
    $copie = app(AssignationAgents::class)->assigner('commande', $personne);

    Livewire::actingAs($personne)->test(AgentsPage::class, ['agent' => $copie])
        ->set('message', 'Change tes consignes')->call('envoyer')
        ->assertHasErrors('message');
});

// --- Dépôt des fichiers de référence depuis le catalogue -------------------------------------------------------

function faux_docx(string $nom = 'modele.docx', string $contenu = "PK\x03\x04 faux document"): \Illuminate\Http\UploadedFile
{
    return \Illuminate\Http\UploadedFile::fake()->createWithContent($nom, $contenu);
}

it('lets the super admin drop a missing reference file under the exact expected name', function () {
    Storage::disk('local')->delete('catalogue-agents/commande/references/modele.docx');
    $admin = superAdminAgents();

    Livewire::actingAs($admin)->test(AdminUsersTable::class)
        ->call('ouvrirCatalogue')->call('basculerReferences', 'commande')
        ->set('depots.commande.0', faux_docx('mon-fichier-copie.docx'))
        ->assertSee('est déposé');

    expect(Storage::disk('local')->get('catalogue-agents/commande/references/modele.docx'))->toBe("PK\x03\x04 faux document")
        ->and(app(CatalogueAgents::class)->referencesManquantes('commande'))->toBe([]);
});

it('marks the copies to update after a replacement', function () {
    $personne = User::factory()->create();
    app(AssignationAgents::class)->assigner('commande', $personne);
    expect(app(CatalogueAgents::class)->nombreAMettreAJour('commande'))->toBe(0);

    Livewire::actingAs(superAdminAgents())->test(AdminUsersTable::class)
        ->call('ouvrirCatalogue')->call('basculerReferences', 'commande')
        ->set('depots.commande.0', faux_docx('x.docx', "PK\x03\x04 nouvelle version"))
        ->assertSee('mettre à jour les copies');

    expect(app(CatalogueAgents::class)->nombreAMettreAJour('commande'))->toBe(1);
});

it('refuses a deposit that is not a real docx, is the wrong type or is not super admin', function (\Closure $fichier, bool $superAdmin, string $message) {
    $avant = Storage::disk('local')->get('catalogue-agents/commande/references/modele.docx');
    $user = User::factory()->create(['is_super_admin' => $superAdmin, 'is_admin' => true]);

    Livewire::actingAs($user)->test(AdminUsersTable::class)
        ->call('ouvrirCatalogue')->call('basculerReferences', 'commande')
        ->set('depots.commande.0', $fichier())
        ->assertSee($message);

    expect(Storage::disk('local')->get('catalogue-agents/commande/references/modele.docx'))->toBe($avant);
})->with([
    'texte déguisé en docx' => [fn () => faux_docx('a.docx', 'juste du texte'), true, 'ne s\'ouvre pas comme un document Office'],
    'mauvaise extension' => [fn () => faux_docx('a.xlsx'), true, 'n\'en est pas un'],
    'admin simple' => [fn () => faux_docx('a.docx'), false, 'Seul un super admin'],
]);

it('refuses a file the agent does not expect', function () {
    expect(fn () => app(CatalogueAgents::class)->deposerReference('commande', 'autre.docx', faux_docx('autre.docx')))
        ->toThrow(AssignationImpossible::class, 'pas attendu');
});

it('lists the expected references with presence, size and date but never the content', function () {
    $etat = app(CatalogueAgents::class)->etatReferences('commande');

    expect($etat)->toHaveCount(1)
        ->and($etat[0])->toMatchArray(['nom' => 'modele.docx', 'present' => true, 'taille' => strlen('contenu du formulaire')])
        ->and($etat[0])->not->toHaveKey('contenu');
});
