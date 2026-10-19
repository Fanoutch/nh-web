<?php

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\AgentClient;
use App\Services\AssistantIndisponible;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function executionDeTest(): AgentExecution
{
    Storage::fake('local');
    $user = User::factory()->create();
    $agent = Agent::create([
        'user_id' => $user->id, 'nom' => 'Commande', 'consignes' => 'Soyez bref.', 'outils' => ['excel_lire', 'excel_decrire'],
    ]);
    $execution = $agent->executions()->create(['user_id' => $user->id, 'mode' => 'execution', 'demande' => 'Décrivez le stock.']);
    Storage::disk('local')->put($execution->dossierEntree().'/stock.xlsx', 'x');

    return $execution;
}

beforeEach(fn () => config(['assistant.jeton' => 'secret']));

it('sends the execution with the token, the files and the allowed tools only', function () {
    Http::fake(['*/agents/executions' => Http::response(
        json_encode(['type' => 'etape', 'outil' => 'excel_decrire'])."\n".json_encode(['type' => 'fin', 'statut' => 'termine'])."\n"
    )]);
    $execution = executionDeTest();
    $evenements = [];

    app(AgentClient::class)->executer($execution, function (array $e) use (&$evenements) {
        $evenements[] = $e['type'];
    });

    expect($evenements)->toBe(['etape', 'fin']);
    Http::assertSent(function (Request $r) use ($execution) {
        return $r->url() === 'http://127.0.0.1:8765/agents/executions'
            && $r->header('X-Assistant-Jeton') === ['secret']
            && $r['execution_id'] === $execution->id
            && $r['fichiers'] === ['stock.xlsx']
            && $r['outils'] === ['excel_decrire', 'excel_lire']
            && $r['consignes'] === 'Soyez bref.'
            && $r['dossier_execution'] === Storage::disk('local')->path($execution->dossierEntree())
            && $r['dossier_sortie'] === Storage::disk('local')->path($execution->dossierSortie());
    });
});

it('creates the output folder before the run', function () {
    Http::fake(['*' => Http::response(json_encode(['type' => 'fin'])."\n")]);
    $execution = executionDeTest();

    app(AgentClient::class)->executer($execution, fn () => null);

    Storage::disk('local')->assertExists($execution->dossierSortie());
});

it('reports the service as unavailable when the stream has no end event', function () {
    Http::fake(['*' => Http::response(json_encode(['type' => 'etape'])."\n")]);

    app(AgentClient::class)->executer(executionDeTest(), fn () => null);
})->throws(AssistantIndisponible::class);

it('reports the service as unavailable when it cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    app(AgentClient::class)->executer(executionDeTest(), fn () => null);
})->throws(AssistantIndisponible::class);

it('stops reading the stream when asked', function () {
    Http::fake(['*' => Http::response(
        json_encode(['type' => 'etape'])."\n".json_encode(['type' => 'etape'])."\n".json_encode(['type' => 'fin'])."\n"
    )]);
    $recus = 0;

    app(AgentClient::class)->executer(executionDeTest(), function () use (&$recus) {
        $recus++;
    }, fn () => $recus >= 1);

    expect($recus)->toBe(1);
});

it('asks the service to stop an execution', function () {
    Http::fake(['*/arret' => Http::response(['ok' => true])]);
    $execution = executionDeTest();

    app(AgentClient::class)->arreter($execution);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://127.0.0.1:8765/agents/executions/'.$execution->id.'/arret'
        && $r->header('X-Assistant-Jeton') === ['secret']);
});
