<?php

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentRappel;
use App\Models\User;
use App\Services\ApplicationProposition;
use App\Services\GestionFichiersAgent;
use App\Services\LancementImpossible;
use App\Services\LanceurAgent;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fluxAgent(array ...$evenements): void
{
    Http::fake(['*/agents/executions' => Http::response(
        implode("\n", array_map(fn ($e) => json_encode($e, JSON_UNESCAPED_UNICODE), $evenements))."\n", 200, ['Content-Type' => 'application/x-ndjson']
    ), '*/arret' => Http::response(['ok' => true])]);
}

beforeEach(function () {
    config(['assistant.jeton' => 'secret']);
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->agent = Agent::create(['user_id' => $this->user->id, 'nom' => 'Commande', 'consignes' => 'Soyez bref.']);
    $this->lanceur = app(LanceurAgent::class);
});

function etape(string $outil, string $resume = 'fait', array $extra = []): array
{
    return array_merge(['type' => 'etape', 'tour' => 1, 'outil' => $outil, 'arguments' => ['fichier' => 'stock.xlsx'], 'statut' => 'ok', 'resume' => $resume], $extra);
}

it('runs a chat turn, saves the steps and the answer', function () {
    fluxAgent(etape('excel_lire', '8 lignes'), ['type' => 'fin', 'statut' => 'termine', 'message' => 'Il y a 8 pièces.', 'fichiers' => []]);
    $vus = [];

    $e = $this->lanceur->lancer($this->agent, $this->user, 'Combien de pièces ?', false, function ($ev) use (&$vus) {
        $vus[] = $ev['type'];
    });

    expect($vus)->toBe(['etape', 'fin'])
        ->and($e->mode)->toBe(AgentExecution::CONCEPTION)->and($e->etat)->toBe(AgentExecution::TERMINE)
        ->and($e->message)->toBe('Il y a 8 pièces.')
        ->and($e->etapes)->toBe([['outil' => 'excel_lire', 'arguments' => ['fichier' => 'stock.xlsx'], 'statut' => 'ok', 'resume' => '8 lignes']])
        ->and($e->fin_at)->not->toBeNull();
    expect($this->agent->fresh()->dernier_lancement_at)->toBeNull();      // une conversation n'est pas un lancement
});

it('records produced files only when they exist on disk', function () {
    fluxAgent(['type' => 'fin', 'statut' => 'termine', 'message' => 'Créé.', 'fichiers' => ['commande.xlsx', 'fantome.xlsx']]);
    Http::fake(function (Request $r) {
        $id = AgentExecution::latest('id')->value('id');
        Storage::disk('local')->put("agents/{$this->agent->id}/executions/{$id}/sortie/commande.xlsx", 'x');

        return Http::response(json_encode(['type' => 'fin', 'statut' => 'termine', 'message' => 'Créé.', 'fichiers' => ['commande.xlsx', 'fantome.xlsx']])."\n");
    });

    $e = $this->lanceur->lancer($this->agent, $this->user, 'Crée la commande', false, fn () => null);

    expect($e->fichiers_produits)->toBe(['commande.xlsx']);
});

it('sends the consignes, the tools and the history of this agent only', function () {
    $this->agent->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'Bonjour', 'message' => 'Salut.']);
    $autre = Agent::create(['user_id' => $this->user->id, 'nom' => 'Autre']);
    $autre->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'Secret', 'message' => 'Autre agent.']);
    fluxAgent(['type' => 'fin', 'statut' => 'termine', 'message' => 'ok', 'fichiers' => []]);

    $this->lanceur->lancer($this->agent, $this->user, 'Et maintenant ?', false, fn () => null);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agents/executions')
        && $r['consignes'] === 'Soyez bref.'
        && $r['historique'] === [['role' => 'user', 'content' => 'Bonjour'], ['role' => 'assistant', 'content' => 'Salut.']]
        && in_array('agent_proposer', $r['outils']) && ! str_contains(json_encode($r->data()), 'Secret'));
});

it('keeps the proposal of the agent, waiting for the confirmation', function () {
    fluxAgent(etape('agent_proposer', 'Rappel proposé', ['proposition' => ['type' => 'rappel', 'periodicite' => 'mensuel']]),
        ['type' => 'fin', 'statut' => 'termine', 'message' => 'Confirmez-vous ?', 'fichiers' => []]);

    $e = $this->lanceur->lancer($this->agent, $this->user, 'Rappelle-moi chaque mois', false, fn () => null);

    expect($e->proposition)->toBe(['type' => 'rappel', 'periodicite' => 'mensuel'])->and($e->proposition_etat)->toBe('en_attente');
    expect(AgentRappel::count())->toBe(0);       // rien n'est enregistré avant le « Oui »
});

it('turns a service failure into a readable stopped run', function () {
    Http::fake(['*' => Http::response('boum', 500)]);

    $e = $this->lanceur->lancer($this->agent, $this->user, 'x', false, fn () => null);

    expect($e->etat)->toBe(AgentExecution::ARRET)->and($e->message)->toContain('indisponible');
});

it('stops when asked and tells the service', function () {
    fluxAgent(etape('excel_lire'), etape('excel_lire'), ['type' => 'fin', 'statut' => 'termine', 'message' => 'x', 'fichiers' => []]);
    $vus = 0;

    $e = $this->lanceur->lancer($this->agent, $this->user, 'x', false, function () use (&$vus) {
        $vus++;
    }, function () use (&$vus) {
        return $vus >= 1;
    });

    expect($e->etat)->toBe(AgentExecution::ARRET)->and($e->message)->toBe('Arrêt demandé.');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/arret'));
});

// --- bouton « Lancer l'agent » ---------------------------------------------------------------------
it('refuses to launch a draft agent or one without waiting documents', function () {
    $this->lanceur->lancer($this->agent, $this->user, '', true, fn () => null);
})->throws(LancementImpossible::class, 'pas encore de recette');

it('refuses to launch without documents', function () {
    $this->agent->update(['etat' => Agent::ACTIF, 'recette' => [['outil' => 'excel_creer', 'arguments' => []]]]);

    $this->lanceur->lancer($this->agent, $this->user, '', true, fn () => null);
})->throws(LancementImpossible::class, 'Déposez d\'abord les documents');

it('replays the recipe, counts as a launch and consumes the waiting documents', function () {
    $this->agent->update(['etat' => Agent::ACTIF, 'recette' => [
        ['outil' => 'excel_selectionner', 'arguments' => ['fichier' => 'stock.xlsx'], 'resume' => '4 lignes'],
        ['outil' => 'excel_creer', 'arguments' => ['nom' => 'commande'], 'resume' => 'créé'],
    ], 'veille_prevenue_at' => now()]);
    app(GestionFichiersAgent::class)->deposerApport($this->agent, UploadedFile::fake()->create('besoins-novembre.xlsx', 1));
    fluxAgent(['type' => 'fin', 'statut' => 'termine', 'message' => 'Terminé.', 'fichiers' => []]);

    $e = $this->lanceur->lancer($this->agent, $this->user, '', true, fn () => null);

    expect($e->mode)->toBe(AgentExecution::EXECUTION)->and($e->demande)->toBe(LanceurAgent::DEMANDE_RECETTE);
    $agent = $this->agent->fresh();
    expect($agent->dernier_lancement_at)->not->toBeNull()->and($agent->veille_prevenue_at)->toBeNull()
        ->and($agent->apports()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/agents/executions')
        && str_contains($r['consignes'], 'RECETTE VALIDÉE')
        && str_contains($r['consignes'], '1. excel_selectionner {"fichier":"stock.xlsx"} — 4 lignes')
        && $r['fichiers'] === ['besoins-novembre.xlsx']);
});

it('keeps the waiting documents when the run fails, so the user can retry', function () {
    $this->agent->update(['etat' => Agent::ACTIF, 'recette' => [['outil' => 'excel_creer', 'arguments' => []]]]);
    app(GestionFichiersAgent::class)->deposerApport($this->agent, UploadedFile::fake()->create('b.xlsx', 1));
    fluxAgent(['type' => 'fin', 'statut' => 'arret', 'message' => 'La colonne « Stock » est introuvable.', 'fichiers' => []]);

    $e = $this->lanceur->lancer($this->agent, $this->user, '', true, fn () => null);

    expect($e->etat)->toBe(AgentExecution::ARRET);
    expect($this->agent->fresh()->apports()->count())->toBe(1)->and($this->agent->fresh()->dernier_lancement_at)->toBeNull();
});

// --- confirmation des propositions -----------------------------------------------------------------
function executionAvecProposition(Agent $agent, User $user, array $proposition, array $extra = []): AgentExecution
{
    return $agent->executions()->create(array_merge([
        'user_id' => $user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'x',
        'proposition' => $proposition, 'proposition_etat' => 'en_attente',
    ], $extra));
}

it('registers the agent from the last run that produced a file, without discovery steps', function () {
    $this->agent->executions()->create(['user_id' => $this->user->id, 'mode' => 'conception', 'etat' => 'termine', 'demande' => 'a',
        'fichiers_produits' => ['commande.xlsx'], 'etapes' => [
            ['outil' => 'excel_decrire', 'arguments' => [], 'statut' => 'ok', 'resume' => 'd'],
            ['outil' => 'excel_selectionner', 'arguments' => ['fichier' => 'stock.xlsx'], 'statut' => 'ok', 'resume' => '4 lignes'],
            ['outil' => 'excel_lire', 'arguments' => ['fichier' => 'x'], 'statut' => 'erreur', 'resume' => 'raté'],
            ['outil' => 'excel_creer', 'arguments' => ['nom' => 'commande'], 'statut' => 'ok', 'resume' => 'créé'],
        ]]);
    $e = executionAvecProposition($this->agent, $this->user, ['type' => 'enregistrer', 'nom' => 'Commande fournisseur', 'description' => 'Prépare la commande', 'consignes' => 'Arrondir au supérieur.']);

    $phrase = app(ApplicationProposition::class)->appliquer($e);

    $agent = $this->agent->fresh();
    expect($agent->etat)->toBe(Agent::ACTIF)->and($agent->nom)->toBe('Commande fournisseur')
        ->and($agent->consignes)->toBe('Arrondir au supérieur.')
        ->and(array_column($agent->recette, 'outil'))->toBe(['excel_selectionner', 'excel_creer'])
        ->and($e->fresh()->proposition_etat)->toBe('acceptee')->and($phrase)->toContain('Commande fournisseur');
});

it('refuses to register an agent that never produced a file', function () {
    $e = executionAvecProposition($this->agent, $this->user, ['type' => 'enregistrer', 'nom' => 'X']);

    app(ApplicationProposition::class)->appliquer($e);
})->throws(LancementImpossible::class, 'aucun résultat à enregistrer');

it('changes the consignes only after the confirmation', function () {
    $e = executionAvecProposition($this->agent, $this->user, ['type' => 'consignes', 'consignes' => 'Toujours arrondir.']);
    expect($this->agent->fresh()->consignes)->toBe('Soyez bref.');

    app(ApplicationProposition::class)->appliquer($e);

    expect($this->agent->fresh()->consignes)->toBe('Toujours arrondir.');
});

it('refuses a proposal without changing anything, and only once', function () {
    $e = executionAvecProposition($this->agent, $this->user, ['type' => 'consignes', 'consignes' => 'Autre.']);
    $service = app(ApplicationProposition::class);

    $service->refuser($e);

    expect($e->fresh()->proposition_etat)->toBe('refusee')->and($this->agent->fresh()->consignes)->toBe('Soyez bref.');
    $service->appliquer($e->fresh());
})->throws(LancementImpossible::class, 'déjà reçu une réponse');

it('sets, updates and removes the reminder on a registered agent', function () {
    $this->agent->update(['etat' => Agent::ACTIF]);
    $service = app(ApplicationProposition::class);

    $service->appliquer(executionAvecProposition($this->agent, $this->user, ['type' => 'rappel', 'periodicite' => 'mensuel']));
    $r = $this->agent->fresh()->rappel;
    expect($r->periodicite)->toBe('mensuel')->and($r->prochaine_echeance->toDateString())->toBe(now()->addMonth()->toDateString());

    $service->appliquer(executionAvecProposition($this->agent, $this->user, ['type' => 'rappel', 'periodicite' => 'hebdomadaire']));
    expect(AgentRappel::count())->toBe(1)->and($this->agent->fresh()->rappel->periodicite)->toBe('hebdomadaire');

    $service->appliquer(executionAvecProposition($this->agent, $this->user, ['type' => 'rappel', 'periodicite' => 'aucun']));
    expect(AgentRappel::count())->toBe(0);
});

it('refuses a reminder on a draft agent', function () {
    $e = executionAvecProposition($this->agent, $this->user, ['type' => 'rappel', 'periodicite' => 'mensuel']);

    app(ApplicationProposition::class)->appliquer($e);
})->throws(LancementImpossible::class, 'Enregistrez d\'abord');
