<?php

use App\Livewire\AgentsPage;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\EntretienAgents;
use App\Services\GestionFichiersAgent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

function agentActif(User $user, array $attributs = []): Agent
{
    $creation = $attributs['created_at'] ?? null;
    unset($attributs['created_at']);
    $agent = Agent::create(array_merge(['user_id' => $user->id, 'nom' => 'A', 'etat' => 'actif', 'dernier_lancement_at' => now()->subDays(10)], $attributs));
    if ($creation !== null) {
        $agent->forceFill(['created_at' => $creation])->save();      // created_at n'est pas modifiable en masse
    }

    return $agent;
}

// --- À faire ---------------------------------------------------------------------------------------
it('flags a due reminder', function () {
    $a = agentActif($this->user);
    $a->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->toDateString()]);

    expect($a->fresh()->motifAFaire())->toBe('Rappel');
});

it('does not flag an agent whose reminder is in the future', function () {
    $a = agentActif($this->user);
    $a->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->addDays(3)->toDateString()]);

    expect($a->fresh()->motifAFaire())->toBeNull()->and($a->fresh()->aFaire())->toBeFalse();
});

it('warns one week before the sleep: at 2 months and 3 weeks, not before', function () {
    $avant = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(3)->addDays(10)]);   // veille dans 10 jours
    $dans = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(3)->addDays(5)]);    // veille dans 5 jours

    expect($avant->motifAFaire())->toBeNull()
        ->and($dans->motifAFaire())->toStartWith('Veille dans');
});

it('counts only a launch as use: chatting or editing does not postpone the sleep', function () {
    $a = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(3)->subDay()]);
    $a->update(['consignes' => 'Modifiées aujourd\'hui']);
    $a->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x']);

    expect($a->fresh()->veilleLe()->isPast())->toBeTrue()->and($a->fresh()->motifAFaire())->toBe('Veille imminente');
});

it('never flags a draft or a sleeping agent', function () {
    expect(agentActif($this->user, ['etat' => 'brouillon', 'dernier_lancement_at' => now()->subYear()])->motifAFaire())->toBeNull()
        ->and(agentActif($this->user, ['etat' => 'en_veille', 'dernier_lancement_at' => now()->subYear()])->motifAFaire())->toBeNull();
});

it('shows the count in the menu and the reason in the list', function () {
    $a = agentActif($this->user, ['nom' => 'Commande', 'dernier_lancement_at' => now()->subMonths(3)->addDays(5)]);

    $this->actingAs($this->user)->get(route('agents.index'))->assertSee('Veille dans')->assertSee('title="Agents à lancer"', false);
    Livewire::actingAs($this->user)->test(AgentsPage::class)->assertSee('Veille dans');
});

// --- mise en veille --------------------------------------------------------------------------------
it('puts to sleep an agent not launched for 3 months, keeping recipe, consignes, references and history', function () {
    $gestion = app(GestionFichiersAgent::class);
    $a = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(3)->subDay(), 'consignes' => 'Gardées.', 'recette' => [['outil' => 'excel_creer', 'arguments' => []]]]);
    $a->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->toDateString()]);
    $gestion->deposerReference($a, UploadedFile::fake()->create('ref.xlsx', 1));
    $gestion->deposerApport($a, UploadedFile::fake()->create('attente.xlsx', 1));
    $e = $gestion->preparerExecution($a, $this->user, 'execution', 'x');

    $n = app(EntretienAgents::class)->mettreEnVeille();

    $a = $a->fresh();
    expect($n)->toBe(1)->and($a->etat)->toBe('en_veille')->and($a->en_veille_at)->not->toBeNull()
        ->and($a->consignes)->toBe('Gardées.')->and($a->recette)->not->toBeEmpty()
        ->and($a->rappel)->toBeNull()                                   // rappels coupés
        ->and($a->apports()->count())->toBe(0)                          // fichiers de travail supprimés
        ->and($a->fichiersReference()->count())->toBe(1)                // références conservées
        ->and(AgentExecution::count())->toBe(1);                        // historique conservé
    Storage::disk('local')->assertMissing($e->dossierEntree().'/ref.xlsx');
    Storage::disk('local')->assertExists($a->dossier().'/references/ref.xlsx');
});

it('keeps awake an agent launched less than 3 months ago, and never touches drafts or files of active agents', function () {
    $gestion = app(GestionFichiersAgent::class);
    $actif = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(2)]);
    $e = $gestion->preparerExecution($actif, $this->user, 'execution', 'x');
    $e->update(['created_at' => now()->subDays(90)]);          // plus de 30 jours : rien ne part tant que l'agent est actif
    $brouillon = agentActif($this->user, ['etat' => 'brouillon', 'created_at' => now()->subYear()]);
    $brouillon->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x']);

    expect(app(EntretienAgents::class)->mettreEnVeille())->toBe(0);

    expect($actif->fresh()->etat)->toBe('actif')->and($brouillon->fresh()->etat)->toBe('brouillon');
    Storage::disk('local')->assertExists($e->dossierEntree());
});

it('puts to sleep a never-launched agent three months after its creation', function () {
    $a = agentActif($this->user, ['dernier_lancement_at' => null, 'created_at' => now()->subMonths(3)->subDay()]);

    expect(app(EntretienAgents::class)->mettreEnVeille())->toBe(1)->and($a->fresh()->etat)->toBe('en_veille');
});

it('removes empty drafts after a week only', function () {
    $vieux = agentActif($this->user, ['etat' => 'brouillon', 'created_at' => now()->subDays(8)]);
    $recent = agentActif($this->user, ['etat' => 'brouillon', 'created_at' => now()->subDays(2)]);
    $utilise = agentActif($this->user, ['etat' => 'brouillon', 'created_at' => now()->subDays(30)]);
    $utilise->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x']);

    expect(app(EntretienAgents::class)->nettoyerBrouillons())->toBe(1);

    expect(Agent::find($vieux->id))->toBeNull()->and(Agent::find($recent->id))->not->toBeNull()->and(Agent::find($utilise->id))->not->toBeNull();
});

it('is scheduled every day and runs from the command line', function () {
    $a = agentActif($this->user, ['dernier_lancement_at' => now()->subMonths(4)]);

    $this->artisan('agents:entretien')->expectsOutputToContain('1 agent(s) mis en veille')->assertSuccessful();

    expect($a->fresh()->etat)->toBe('en_veille');
    $evenements = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map->command;
    expect($evenements->contains(fn ($c) => str_contains($c, 'agents:entretien')))->toBeTrue();
});

// --- rappel honoré par un lancement ----------------------------------------------------------------
it('moves the reminder forward once the agent is launched, so it leaves the to-do list', function () {
    config(['assistant.jeton' => 'secret']);
    \Illuminate\Support\Facades\Http::fake(['*/agents/executions' => \Illuminate\Support\Facades\Http::response(json_encode(['type' => 'fin', 'statut' => 'termine', 'message' => 'ok', 'fichiers' => []])."\n")]);
    $a = agentActif($this->user, ['recette' => [['outil' => 'excel_creer', 'arguments' => []]]]);
    $a->rappel()->create(['periodicite' => 'mensuel', 'prochaine_echeance' => now()->subDays(2)->toDateString()]);
    app(GestionFichiersAgent::class)->deposerApport($a, UploadedFile::fake()->create('b.xlsx', 1));
    expect($a->fresh()->aFaire())->toBeTrue();

    app(\App\Services\LanceurAgent::class)->lancer($a, $this->user, '', true, fn () => null);

    $a = $a->fresh();
    expect($a->rappel->prochaine_echeance->toDateString())->toBe(now()->addMonth()->toDateString())->and($a->aFaire())->toBeFalse();
});

// --- alerte 500 Mo ---------------------------------------------------------------------------------
it('shows the 500 MB banner without deleting anything', function () {
    $a = agentActif($this->user);
    app(GestionFichiersAgent::class)->deposerReference($a, UploadedFile::fake()->createWithContent('gros.xlsx', 'contenu'));
    $c = Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $a->id);
    $c->assertDontSee('occupe plus de');

    config(['agents.alerte_mo' => 0]);
    Livewire::actingAs($this->user)->test(AgentsPage::class)->call('selectionner', $a->id)
        ->assertSee('Cet agent occupe plus de 0 Mo')->assertSee('rien n\'est supprimé automatiquement', false);

    Storage::disk('local')->assertExists($a->dossier().'/references/gros.xlsx');
});
