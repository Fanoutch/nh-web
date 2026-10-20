<?php

use App\Livewire\AgentsPage;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function capturerFluxAgent(callable $action): string
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

function fluxService(array ...$evenements): void
{
    Http::fake(['*/agents/executions' => Http::response(
        implode("\n", array_map(fn ($e) => json_encode($e, JSON_UNESCAPED_UNICODE), $evenements))."\n", 200, ['Content-Type' => 'application/x-ndjson']
    ), '*/arret' => Http::response(['ok' => true]), '*/agents/fichiers/lire' => Http::response(['fichiers' => [
        ['nom' => 'besoins.xlsx', 'type' => 'classeur', 'statut' => 'ok', 'feuilles' => [['nom' => 'Besoins', 'lignes' => 412, 'colonnes' => ['Réf', 'Désignation', 'Besoin'], 'colonnes_tronquees' => false]], 'autres_feuilles' => 0, 'message' => ''],
        ['nom' => 'casse.xlsx', 'type' => 'inconnu', 'statut' => 'erreur', 'message' => 'Le fichier « casse.xlsx » est illisible.'],
    ]])]);
}

beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
    $this->user = User::factory()->create(['name' => 'Jean Dupont']);
    $this->agent = Agent::create(['user_id' => $this->user->id, 'nom' => 'Commande fournisseur', 'etat' => 'actif',
        'recette' => [['outil' => 'excel_creer', 'arguments' => ['nom' => 'c'], 'resume' => 'créé']]]);
});

// --- accès -----------------------------------------------------------------------------------------
it('shows the Agents entry in the menu and opens on a welcome message', function () {
    $this->actingAs($this->user)->get(route('agents.index'))
        ->assertOk()->assertSeeLivewire(AgentsPage::class)
        ->assertSee('Agents')->assertSee('Bonjour Jean')->assertSee('Commande fournisseur');
});

it('shows only the agents of the user', function () {
    Agent::create(['user_id' => User::factory()->create()->id, 'nom' => 'Agent de quelqu\'un d\'autre']);

    $this->actingAs($this->user)->get(route('agents.index'))->assertDontSee('Agent de quelqu\'un d\'autre');
});

it('forbids the page, the stop button and the downloads of an agent to anyone but its owner, admins included', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $e = $this->agent->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x', 'fichiers_produits' => ['a.xlsx']]);
    Storage::disk('local')->put($e->dossierSortie().'/a.xlsx', 'contenu');

    foreach ([$admin, User::factory()->create()] as $autre) {
        $this->actingAs($autre)->get(route('agents.show', $this->agent))->assertForbidden();
        $this->actingAs($autre)->post(route('agents.arreter', $this->agent))->assertForbidden();
        $this->actingAs($autre)->get(route('agents.fichier', [$this->agent, $e, 'a.xlsx']))->assertForbidden();
        Livewire::actingAs($autre)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->assertForbidden();
    }
});

it('requires a login', function () {
    $this->get(route('agents.index'))->assertRedirect(route('login'));
});

// --- création et sélection -------------------------------------------------------------------------
it('creates a draft agent and reuses an empty draft instead of piling them up', function () {
    $composant = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('nouvelAgent');
    $brouillon = Agent::where('etat', 'brouillon')->sole();
    $composant->assertSet('agentId', $brouillon->id)->assertSee('Glissez vos classeurs Excel ou documents Word');

    $composant->call('selectionner', $this->agent->id)->call('nouvelAgent');

    expect(Agent::where('etat', 'brouillon')->count())->toBe(1);
});

it('deletes an agent with its files, after owner check', function () {
    Storage::disk('local')->put($this->agent->dossier().'/references/a.xlsx', 'x');

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('supprimerAgent', $this->agent->id);

    expect(Agent::count())->toBe(0);
    Storage::disk('local')->assertMissing($this->agent->dossier().'/references/a.xlsx');
    Livewire::actingAs(User::factory()->create())->test(AgentsPage::class)->call('supprimerAgent', Agent::create(['user_id' => $this->user->id, 'nom' => 'B'])->id)->assertForbidden();
});

it('cannot be pointed at another user\'s agent by tampering with the locked id', function () {
    $autre = Agent::create(['user_id' => User::factory()->create()->id, 'nom' => 'Autre']);

    expect(fn () => Livewire::actingAs($this->user)->test(AgentsPage::class)->set('agentId', $autre->id))
        ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
});

// --- dépôt de documents ----------------------------------------------------------------------------
it('deposits documents and shows the reading confirmation', function () {
    fluxService();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('besoins.xlsx', 5)])
        ->assertSee('✓')->assertSee('Feuille « Besoins » : 412 lignes')->assertSee('Réf, Désignation, Besoin');

    Storage::disk('local')->assertExists($this->agent->dossier().'/apports/besoins.xlsx');
});

it('flags an unreadable document and blocks the launch button', function () {
    fluxService();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('casse.xlsx', 5)])
        ->assertSee('✕')->assertSee('illisible')->assertSeeHtml('disabled')
        ->assertSee('Un document est illisible');
});

it('refuses a wrong format with a readable message and deposits nothing', function () {
    fluxService();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('note.pdf', 5)])
        ->assertSee('n\'est pas accepté');

    expect($this->agent->apports()->count())->toBe(0);
});

it('refuses a document over 20 Mo', function () {
    fluxService();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('gros.xlsx', 21 * 1024)])->assertSee('dépasse 20 Mo');

    expect($this->agent->apports()->count())->toBe(0);
});

it('keeps the deposit when the service cannot read it, with a clear message', function () {
    Http::fake(['*' => Http::response('boum', 500)]);

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('besoins.xlsx', 5)])
        ->assertSee('lecture n\'a pas pu être vérifiée')->assertSee('Lecture non vérifiée');

    expect($this->agent->apports()->count())->toBe(1);
});

it('removes a waiting document', function () {
    fluxService();
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('besoins.xlsx', 5)]);

    $c->call('retirerApport', $this->agent->apports()->first()->id);

    expect($this->agent->apports()->count())->toBe(0);
});

it('does not accept deposits on a sleeping agent', function () {
    $this->agent->update(['etat' => 'en_veille']);
    fluxService();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->assertSee('Réactivez l\'agent', false)
        ->set('depots', [UploadedFile::fake()->create('besoins.xlsx', 5)])->assertForbidden();
});

it('reactivates a sleeping agent and counts it as used', function () {
    $this->agent->update(['etat' => 'en_veille', 'en_veille_at' => now()]);

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->call('reactiver');

    $a = $this->agent->fresh();
    expect($a->etat)->toBe('actif')->and($a->en_veille_at)->toBeNull()->and($a->dernier_lancement_at)->not->toBeNull();
});

// --- le chat ---------------------------------------------------------------------------------------
it('streams the steps, saves the turn and shows the answer and the download', function () {
    $execId = AgentExecution::max('id') + 1;
    Http::fake(function () {
        $id = AgentExecution::latest('id')->value('id');
        Storage::disk('local')->put("agents/{$this->agent->id}/executions/{$id}/sortie/commande.xlsx", 'x');

        return Http::response(implode("\n", [
            json_encode(['type' => 'etape', 'tour' => 1, 'outil' => 'excel_selectionner', 'arguments' => [], 'statut' => 'ok', 'resume' => '4 lignes']),
            json_encode(['type' => 'etape', 'tour' => 2, 'outil' => 'excel_creer', 'arguments' => [], 'statut' => 'ok', 'resume' => 'créé']),
            json_encode(['type' => 'fin', 'statut' => 'termine', 'message' => '**4 pièces** à commander.', 'fichiers' => ['commande.xlsx']]),
        ])."\n");
    });
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id);

    $diffuse = capturerFluxAgent(fn () => $c->set('message', 'Fais la commande')->call('envoyer'));

    expect($diffuse)->toContain('etapes-en-cours')->toContain('4 lignes')->toContain('S\u00e9lection de lignes')->toContain('Cr\u00e9ation d&#039;un classeur');
    $c->assertSee('4 pièces')->assertSee('L\'agent a travaillé (2 étapes)', false)->assertSee('Télécharger commande.xlsx')
        ->assertSet('message', '');
    $e = AgentExecution::sole();
    expect($e->etat)->toBe('termine')->and($e->fichiers_produits)->toBe(['commande.xlsx']);
});

it('ignores an empty message', function () {
    Http::fake();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->set('message', '   ')->call('envoyer');

    Http::assertNothingSent();
});

it('rejects a message over 4000 characters', function () {
    Http::fake();

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('message', str_repeat('a', 4001))->call('envoyer')->assertSee('trop long');

    Http::assertNothingSent();
});

it('shows a stopped turn in red with the readable reason', function () {
    fluxService(['type' => 'fin', 'statut' => 'arret', 'message' => 'La colonne « Stock » est introuvable.', 'fichiers' => []]);
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id);

    capturerFluxAgent(fn () => $c->set('message', 'x')->call('envoyer'));

    $c->assertSee('La colonne « Stock » est introuvable.');
    expect(AgentExecution::sole()->etat)->toBe('arret');
});

it('escapes what the agent says: no HTML from a document reaches the page', function () {
    fluxService(['type' => 'fin', 'statut' => 'termine', 'message' => 'Voici <script>alert(1)</script> fait.', 'fichiers' => []]);
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id);

    capturerFluxAgent(fn () => $c->set('message', 'x')->call('envoyer'));

    $c->assertDontSeeHtml('<script>alert(1)</script>');
});

it('launches the agent on the waiting documents with the button', function () {
    fluxService(['type' => 'fin', 'statut' => 'termine', 'message' => 'Terminé.', 'fichiers' => []]);
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)
        ->set('depots', [UploadedFile::fake()->create('besoins.xlsx', 5)]);

    capturerFluxAgent(fn () => $c->call('lancerAgent'));

    $e = AgentExecution::sole();
    expect($e->mode)->toBe('execution')->and($e->fichiers_apportes)->toBe(['besoins.xlsx']);
    expect($this->agent->fresh()->dernier_lancement_at)->not->toBeNull()->and($this->agent->fresh()->apports()->count())->toBe(0);
    $c->assertSee('Lancement de l\'agent')->assertSee('Terminé.');
});

it('explains why the launch is impossible instead of failing', function () {
    Http::fake();
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id);

    capturerFluxAgent(fn () => $c->call('lancerAgent'));

    $c->assertSee('Déposez d\'abord les documents');
    Http::assertNothingSent();
});

// --- confirmation des propositions -----------------------------------------------------------------
function tourAvecProposition(Agent $agent, array $proposition): AgentExecution
{
    return $agent->executions()->create(['user_id' => $agent->user_id, 'mode' => 'conception', 'etat' => 'termine',
        'demande' => 'Rappelle-moi', 'message' => 'Je vous propose un rappel.', 'proposition' => $proposition, 'proposition_etat' => 'en_attente']);
}

it('shows a confirmation card and applies the change only on Yes', function () {
    $e = tourAvecProposition($this->agent, ['type' => 'rappel', 'periodicite' => 'mensuel']);
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id);

    $c->assertSee('Régler un rappel mensuel')->assertSee('Rien ne change tant que vous n\'avez pas répondu', false)->assertSee('Oui, enregistrer');
    expect($this->agent->fresh()->rappel)->toBeNull();

    $c->call('accepter', $e->id);

    expect($this->agent->fresh()->rappel->periodicite)->toBe('mensuel');
    $c->assertSee('✓ Enregistré')->assertDontSee('Oui, enregistrer');
});

it('changes nothing on No', function () {
    $e = tourAvecProposition($this->agent, ['type' => 'consignes', 'consignes' => 'Nouvelles consignes.']);

    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->call('refuser', $e->id);

    expect($this->agent->fresh()->consignes)->toBeNull();
    $c->assertSee('Refusé : rien n\'a changé', false);
});

it('shows why a proposal cannot be applied', function () {
    $this->agent->update(['etat' => 'brouillon']);
    $e = tourAvecProposition($this->agent, ['type' => 'rappel', 'periodicite' => 'mensuel']);

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->call('accepter', $e->id)
        ->assertSee('Enregistrez d\'abord l\'agent');
});

it('refuses to answer the proposal of another user\'s agent', function () {
    $autre = Agent::create(['user_id' => User::factory()->create()->id, 'nom' => 'Autre']);
    $e = tourAvecProposition($autre, ['type' => 'consignes', 'consignes' => 'Piratage.']);

    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $this->agent->id)->call('accepter', $e->id)->assertForbidden();

    expect($autre->fresh()->consignes)->toBeNull();
});

// --- « À faire », arrêt, téléchargement ------------------------------------------------------------
it('lists in "À faire" the agents whose reminder is due', function () {
    $this->agent->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->toDateString()]);
    $pasEncore = Agent::create(['user_id' => $this->user->id, 'nom' => 'Pas encore', 'etat' => 'actif']);
    $pasEncore->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->addDays(10)->toDateString()]);

    $c = Livewire::actingAs($this->user)->test(AgentsPage::class);

    $c->assertSee('À faire')->assertSee('Rappel');
    expect($c->viewData('aFaire')->pluck('nom')->all())->toBe(['Commande fournisseur']);
});

it('sets the stop flag for the owner', function () {
    $this->actingAs($this->user)->post(route('agents.arreter', $this->agent))->assertNoContent();

    expect(Cache::get("agent.arret.{$this->agent->id}"))->toBeTrue();
});

it('downloads a produced file for the owner only, and nothing else on the disk', function () {
    $e = $this->agent->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x', 'fichiers_produits' => ['commande.xlsx']]);
    Storage::disk('local')->put($e->dossierSortie().'/commande.xlsx', 'contenu');
    Storage::disk('local')->put($e->dossierEntree().'/secret.xlsx', 'secret');

    $this->actingAs($this->user)->get(route('agents.fichier', [$this->agent, $e, 'commande.xlsx']))->assertOk()
        ->assertDownload('commande.xlsx');
    $this->actingAs($this->user)->get(route('agents.fichier', [$this->agent, $e, 'secret.xlsx']))->assertNotFound();
    $this->actingAs($this->user)->get('/agents/'.$this->agent->id.'/executions/'.$e->id.'/fichiers/..%2F..%2Fcommande.xlsx')->assertNotFound();
});

it('does not download a file through another agent\'s execution', function () {
    $autre = Agent::create(['user_id' => $this->user->id, 'nom' => 'Autre']);
    $e = $autre->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x', 'fichiers_produits' => ['a.xlsx']]);
    Storage::disk('local')->put($e->dossierSortie().'/a.xlsx', 'x');

    $this->actingAs($this->user)->get(route('agents.fichier', [$this->agent, $e, 'a.xlsx']))->assertNotFound();
});
