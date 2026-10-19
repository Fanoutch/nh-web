<?php

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\User;
use App\Services\FichierAgentRefuse;
use App\Services\GestionFichiersAgent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    $this->user = User::factory()->create();
    $this->agent = Agent::create(['user_id' => $this->user->id, 'nom' => 'Commande']);
    $this->gestion = app(GestionFichiersAgent::class);
});

it('stores a reference file in the private folder of the agent', function () {
    $reference = $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 10), 'stock');

    expect($reference->nom)->toBe('stock.xlsx')->and($reference->role)->toBe('stock');
    Storage::disk('local')->assertExists('agents/'.$this->agent->id.'/references/stock.xlsx');
});

it('refuses a wrong extension with a message saying what to do', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('note.pdf', 10));
})->throws(FichierAgentRefuse::class, 'Déposez un classeur .xlsx, .xlsm, .csv');

it('refuses a file over 20 Mo', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('gros.xlsx', 20 * 1024 + 1));
})->throws(FichierAgentRefuse::class, 'dépasse 20 Mo');

it('accepts a file of exactly 20 Mo', function () {
    $reference = $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('limite.xlsx', 20 * 1024));

    expect($reference->taille)->toBe(20 * 1024 * 1024);
});

it('keeps only the base name of a path-like upload name', function () {
    $reference = $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('../../evil.xlsx', 1));

    expect($reference->nom)->toBe('evil.xlsx');
    Storage::disk('local')->assertExists('agents/'.$this->agent->id.'/references/evil.xlsx');
});

it('refuses a hidden file name', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('.xlsx', 1));
})->throws(FichierAgentRefuse::class);

it('refuses a second reference with the same name', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 1));
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 1));
})->throws(FichierAgentRefuse::class, 'existe déjà');

it('removes a reference file and its row', function () {
    $reference = $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 1));

    $this->gestion->supprimerReference($reference);

    Storage::disk('local')->assertMissing($reference->chemin);
    expect($this->agent->fichiersReference()->count())->toBe(0);
});

it('prepares an execution folder with copies of references and brought files', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 1));

    $execution = $this->gestion->preparerExecution(
        $this->agent, $this->user, AgentExecution::EXECUTION, 'Faites la commande.',
        [UploadedFile::fake()->create('besoins.xlsx', 1)],
    );

    expect($execution->etat)->toBe(AgentExecution::EN_COURS)
        ->and($execution->fichiers_apportes)->toBe(['besoins.xlsx'])
        ->and($execution->debut_at)->not->toBeNull();
    Storage::disk('local')->assertExists($execution->dossierEntree().'/stock.xlsx');
    Storage::disk('local')->assertExists($execution->dossierEntree().'/besoins.xlsx');
    // l'original de référence n'a pas bougé
    Storage::disk('local')->assertExists('agents/'.$this->agent->id.'/references/stock.xlsx');
});

it('refuses a brought file named like a reference, without creating an execution', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->create('stock.xlsx', 1));

    try {
        $this->gestion->preparerExecution($this->agent, $this->user, AgentExecution::EXECUTION, 'x',
            [UploadedFile::fake()->create('STOCK.xlsx', 1)]);
    } catch (FichierAgentRefuse $e) {
        expect($e->getMessage())->toContain('déjà pris');
    }

    expect(AgentExecution::count())->toBe(0);
});

it('refuses two brought files with the same name', function () {
    $this->gestion->preparerExecution($this->agent, $this->user, AgentExecution::EXECUTION, 'x', [
        UploadedFile::fake()->create('a.xlsx', 1), UploadedFile::fake()->create('a.xlsx', 1),
    ]);
})->throws(FichierAgentRefuse::class);

it('measures the size of an agent and warns from 500 Mo only', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->createWithContent('a.xlsx', str_repeat('x', 100 * 1024)));
    expect($this->gestion->tailleOctets($this->agent))->toBe(100 * 1024)
        ->and($this->gestion->alerteTaille($this->agent))->toBeFalse();

    config(['agents.alerte_mo' => 0]);
    expect($this->gestion->alerteTaille($this->agent))->toBeTrue();
});

it('does not count the files of another agent', function () {
    $autre = Agent::create(['user_id' => $this->user->id, 'nom' => 'Autre']);
    $this->gestion->deposerReference($autre, UploadedFile::fake()->createWithContent('a.xlsx', str_repeat('x', 100 * 1024)));

    expect($this->gestion->tailleOctets($this->agent))->toBe(0);
});

it('deletes nothing on its own: only putting to sleep empties the work files, never references', function () {
    $this->gestion->deposerReference($this->agent, UploadedFile::fake()->createWithContent('stock.xlsx', 'contenu'));
    $execution = $this->gestion->preparerExecution($this->agent, $this->user, AgentExecution::EXECUTION, 'x');
    $execution->update(['created_at' => now()->subDays(90)]); // plus de 30 jours : rien ne part

    expect($this->gestion->tailleOctets($this->agent))->toBeGreaterThan(0);
    Storage::disk('local')->assertExists($execution->dossierEntree().'/stock.xlsx');

    $this->gestion->viderFichiersDeTravail($this->agent);

    Storage::disk('local')->assertMissing($execution->dossierEntree().'/stock.xlsx');
    Storage::disk('local')->assertExists('agents/'.$this->agent->id.'/references/stock.xlsx');
    expect(AgentExecution::count())->toBe(1);   // l'historique reste
});
