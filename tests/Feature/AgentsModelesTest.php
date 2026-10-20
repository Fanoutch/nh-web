<?php

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\AgentRappel;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

function agentDe(User $user, array $attributs = []): Agent
{
    return Agent::create(array_merge(['user_id' => $user->id, 'nom' => 'Commande fournisseur'], $attributs));
}

it('creates the four agent tables', function () {
    foreach (['agents', 'agent_fichiers_reference', 'agent_executions', 'agent_rappels'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('starts an agent as a draft with every tool allowed', function () {
    $agent = agentDe(User::factory()->create())->fresh();

    expect($agent->etat)->toBe(Agent::BROUILLON)
        ->and($agent->outilsAutorises())->toBe(array_merge(Agent::OUTILS, Agent::GESTION))
        ->and($agent->dernier_lancement_at)->toBeNull();
});

it('limits the allowed tools to existing ones, in a stable order', function () {
    $agent = agentDe(User::factory()->create(), ['outils' => ['excel_lire', 'rm_rf', 'excel_decrire']]);

    expect($agent->fresh()->outilsAutorises())->toBe(['excel_decrire', 'excel_lire', 'agent_proposer', 'agent_historique']);
});

it('stores the recipe as JSON', function () {
    $recette = [['outil' => 'excel_selectionner', 'arguments' => ['fichier' => 'stock.xlsx']]];
    $agent = agentDe(User::factory()->create(), ['recette' => $recette]);

    expect($agent->fresh()->recette)->toBe($recette);
});

it('lists agents by owner and deletes them with the owner', function () {
    $user = User::factory()->create();
    $agent = agentDe($user);
    agentDe(User::factory()->create(), ['nom' => 'Autre']);

    expect($user->agents->pluck('id')->all())->toBe([$agent->id]);

    $user->delete();
    expect(Agent::count())->toBe(1);
});

it('cascades executions, references and reminder with the agent', function () {
    $user = User::factory()->create();
    $agent = agentDe($user);
    $agent->executions()->create(['user_id' => $user->id, 'mode' => AgentExecution::CONCEPTION, 'demande' => 'x']);
    $agent->fichiersReference()->create(['nom' => 'a.xlsx', 'chemin' => 'agents/1/references/a.xlsx', 'taille' => 1]);
    AgentRappel::create(['agent_id' => $agent->id, 'periodicite' => 'mensuel', 'prochaine_echeance' => now()->addMonth()]);

    $agent->delete();

    expect(AgentExecution::count())->toBe(0)
        ->and(\App\Models\AgentFichierReference::count())->toBe(0)
        ->and(AgentRappel::count())->toBe(0);
});

it('refuses two references with the same name on one agent', function () {
    $agent = agentDe(User::factory()->create());
    $ligne = ['nom' => 'a.xlsx', 'chemin' => 'x', 'taille' => 1];
    $agent->fichiersReference()->create($ligne);

    $agent->fichiersReference()->create($ligne);
})->throws(\Illuminate\Database\QueryException::class);

it('allows only one reminder per agent', function () {
    $agent = agentDe(User::factory()->create());
    $ligne = ['agent_id' => $agent->id, 'periodicite' => 'mensuel', 'prochaine_echeance' => now()->addMonth()];
    AgentRappel::create($ligne);

    AgentRappel::create($ligne);
})->throws(\Illuminate\Database\QueryException::class);

it('applies the personal-agent rules: only the owner, admins included', function (string $profil, bool $attendu) {
    $proprietaire = User::factory()->create();
    $agent = agentDe($proprietaire);
    $user = match ($profil) {
        'proprietaire' => $proprietaire,
        'autre' => User::factory()->create(),
        'admin' => User::factory()->create(['is_admin' => true]),
        'super admin' => User::factory()->create(['is_super_admin' => true]),
    };

    expect($user->can('view', $agent))->toBe($attendu)
        ->and($user->can('update', $agent))->toBe($attendu)
        ->and($user->can('delete', $agent))->toBe($attendu)
        ->and($user->can('lancer', $agent))->toBe($attendu);
})->with([
    'proprietaire' => ['proprietaire', true],
    'autre' => ['autre', false],
    'admin' => ['admin', false],
    'super admin' => ['super admin', false],
]);

it('refuses to launch a sleeping agent, but lets its owner see it', function () {
    $user = User::factory()->create();
    $agent = agentDe($user, ['etat' => Agent::EN_VEILLE]);

    expect($user->can('view', $agent))->toBeTrue()
        ->and($user->can('lancer', $agent))->toBeFalse();
});
