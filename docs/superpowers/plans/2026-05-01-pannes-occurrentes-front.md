# Pannes occurrentes — Intégration front Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Brancher le module Python `recurrent_failures` (déjà existant côté pipeline) au front Laravel : ingestion en BDD à chaque XML traité, et 2 widgets par machine sur la page `/machines` (occurrentes actives + pannes du dernier vol).

**Architecture:** Une nouvelle table `recurrent_failures` synchronisée par un service `RecurrentFailuresIngestor` appelé depuis `ProcessXmlJob` après `FlightImporter`. Le contrôleur `MachineController@index` eager-load les pannes actives + le dernier vol et ses pannes ; la vue `machines/index.blade.php` est refondue avec 2 widgets par ligne et 2 modals "voir plus" par widget. Pas de Livewire.

**Tech Stack:** Laravel 12, PHP 8.3, PostgreSQL (Supabase), Pest 3, Tailwind, Alpine.js (via Breeze `<x-modal>`).

**Spec source:** `docs/superpowers/specs/2026-05-01-pannes-occurrentes-front-design.md`

---

## Task 1: Commit du spec et création de la branche de travail

**Files:**
- Modify: `docs/superpowers/specs/2026-05-01-pannes-occurrentes-front-design.md` (déjà écrit, juste à committer)

- [ ] **Step 1: Vérifier l'état du repo et la présence du spec**

```bash
cd /root/camille2/nh-web
git status
ls docs/superpowers/specs/2026-05-01-pannes-occurrentes-front-design.md
```

Expected: spec présent, git status montre les 2 fichiers de spec/plan untracked.

- [ ] **Step 2: Commit le spec et le plan**

```bash
git add docs/superpowers/specs/2026-05-01-pannes-occurrentes-front-design.md \
        docs/superpowers/plans/2026-05-01-pannes-occurrentes-front.md
git commit -m "docs: add spec and plan for pannes occurrentes front integration"
```

---

## Task 2: Migration de la table `recurrent_failures`

**Files:**
- Create: `database/migrations/{auto-timestamp}_create_recurrent_failures_table.php`

- [ ] **Step 1: Créer le fichier de migration**

```bash
cd /root/camille2/nh-web
php artisan make:migration create_recurrent_failures_table
```

Expected: création d'un fichier sous `database/migrations/2026_MM_DD_HHMMSS_create_recurrent_failures_table.php` (timestamp auto-généré par Laravel à partir de l'heure courante). Récupérer le chemin exact pour le Step 2.

- [ ] **Step 2: Écrire le contenu de la migration**

Remplacer le contenu du fichier généré par :

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrent_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->string('technical_event_id');
            $table->enum('status', ['active', 'archived'])->default('active');

            $table->text('te_description')->nullable();
            $table->text('description')->nullable();
            $table->string('system_description')->nullable();
            $table->string('type_description')->nullable();
            $table->string('failure_code')->nullable();

            $table->string('active_depuis_vol')->nullable();
            $table->date('active_depuis_date')->nullable();
            $table->timestamp('first_apparition')->nullable();

            $table->unsignedTinyInteger('score')->default(1);
            $table->jsonb('details')->nullable();

            $table->timestamps();
            $table->index(['machine_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX recurrent_failures_active_unique
                       ON recurrent_failures (machine_id, technical_event_id)
                       WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrent_failures');
    }
};
```

- [ ] **Step 3: Lancer la migration et vérifier**

```bash
php artisan migrate
```

Expected: `INFO  Running migrations.` puis `... DONE`.

- [ ] **Step 4: Vérifier en BDD que la table existe avec l'index partiel**

```bash
php artisan tinker --execute="echo \DB::select(\"SELECT indexname FROM pg_indexes WHERE tablename = 'recurrent_failures'\")[0]->indexname ?? 'missing';"
```

Expected: imprime au moins une ligne, dont `recurrent_failures_active_unique`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/
git commit -m "feat: add recurrent_failures table migration"
```

---

## Task 3: Modèle Eloquent `RecurrentFailure` + relation Machine

**Files:**
- Create: `app/Models/RecurrentFailure.php`
- Modify: `app/Models/Machine.php`
- Test: `tests/Feature/RecurrentFailureModelTest.php`

- [ ] **Step 1: Écrire le test du modèle**

Créer `tests/Feature/RecurrentFailureModelTest.php` :

```php
<?php

use App\Models\Machine;
use App\Models\RecurrentFailure;

it('creates a recurrent failure with cast attributes', function () {
    $machine = Machine::create(['hc_id' => 'NH99']);

    $rf = RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'FFP1000011A',
        'status' => 'active',
        'te_description' => 'SONAR 1 LOSS',
        'description' => 'ROTORS FLIGHT CONTROL',
        'system_description' => 'Flight Control System',
        'type_description' => 'Fault Code',
        'failure_code' => '1000011',
        'active_depuis_vol' => 'NH99_2026-05-01_001',
        'active_depuis_date' => '2026-05-01',
        'first_apparition' => '2026-04-28T08:00:00',
        'score' => 2,
        'details' => ['raw' => 'value'],
    ]);

    expect($rf->machine->hc_id)->toBe('NH99');
    expect($rf->active_depuis_date->format('Y-m-d'))->toBe('2026-05-01');
    expect($rf->first_apparition->format('Y-m-d H:i:s'))->toBe('2026-04-28 08:00:00');
    expect($rf->details)->toBe(['raw' => 'value']);
});

it('exposes recurrentFailures relation on Machine', function () {
    $machine = Machine::create(['hc_id' => 'NH98']);
    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE1',
        'status' => 'active',
    ]);
    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE2',
        'status' => 'active',
    ]);

    expect($machine->recurrentFailures)->toHaveCount(2);
});
```

- [ ] **Step 2: Lancer le test (doit échouer car le modèle n'existe pas)**

```bash
php artisan test --filter=RecurrentFailureModelTest
```

Expected: FAIL avec "Class App\Models\RecurrentFailure not found" (ou équivalent).

- [ ] **Step 3: Créer le modèle**

Créer `app/Models/RecurrentFailure.php` :

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurrentFailure extends Model
{
    protected $guarded = [];

    protected $casts = [
        'active_depuis_date' => 'date',
        'first_apparition'   => 'datetime',
        'score'              => 'integer',
        'details'            => 'array',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
```

- [ ] **Step 4: Ajouter la relation `recurrentFailures()` sur le modèle Machine**

Lire d'abord le fichier `app/Models/Machine.php` pour voir la structure existante, puis ajouter avant la dernière `}` (fermeture de la classe) :

```php
    public function recurrentFailures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RecurrentFailure::class);
    }
```

- [ ] **Step 5: Relancer les tests pour vérifier qu'ils passent**

```bash
php artisan test --filter=RecurrentFailureModelTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/RecurrentFailure.php app/Models/Machine.php tests/Feature/RecurrentFailureModelTest.php
git commit -m "feat: add RecurrentFailure model and Machine relation"
```

---

## Task 4: Service `RecurrentFailuresIngestor` — squelette + test "no file"

**Files:**
- Create: `app/Services/RecurrentFailuresIngestor.php`
- Test: `tests/Feature/RecurrentFailuresIngestorTest.php`

- [ ] **Step 1: Écrire le test "no file"**

Créer `tests/Feature/RecurrentFailuresIngestorTest.php` :

```php
<?php

use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Services\RecurrentFailuresIngestor;

beforeEach(function () {
    $this->ingestor = new RecurrentFailuresIngestor();
    $this->occurrentesDir = storage_path('app/data/reports/occurrentes');

    // Cleanup any leftover fixtures from previous runs
    if (is_dir($this->occurrentesDir)) {
        foreach (glob($this->occurrentesDir . '/NH9*/occurrentes.json') as $f) {
            @unlink($f);
        }
    }
});

it('returns zero counts when occurrentes.json does not exist', function () {
    $result = $this->ingestor->ingest('NH_DOES_NOT_EXIST');

    expect($result)->toBe(['synced' => 0, 'removed' => 0]);
});
```

- [ ] **Step 2: Créer le service avec une méthode minimale qui fait passer ce test**

Créer `app/Services/RecurrentFailuresIngestor.php` :

```php
<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Facades\DB;

class RecurrentFailuresIngestor
{
    public function ingest(string $hcId): array
    {
        $path = storage_path("app/data/reports/occurrentes/{$hcId}/occurrentes.json");
        if (!file_exists($path)) {
            return ['synced' => 0, 'removed' => 0];
        }

        $json = json_decode(file_get_contents($path), true);
        if (!is_array($json)) {
            return ['synced' => 0, 'removed' => 0];
        }

        $active = $json['active'] ?? [];
        $volsHistory = $json['vols_history'] ?? [];
        $machine = Machine::firstOrCreate(['hc_id' => $hcId]);

        $activeIds = array_map(fn ($e) => $e['id'], $active);

        return DB::transaction(function () use ($machine, $active, $activeIds, $volsHistory) {
            $removed = $machine->recurrentFailures()
                ->where('status', 'active')
                ->when(!empty($activeIds), fn ($q) => $q->whereNotIn('technical_event_id', $activeIds))
                ->delete();

            $synced = 0;
            foreach ($active as $entry) {
                $score = $this->computeScore($entry['id'], $volsHistory);
                $machine->recurrentFailures()->updateOrCreate(
                    [
                        'technical_event_id' => $entry['id'],
                        'status'             => 'active',
                    ],
                    [
                        'te_description'      => $entry['te_description'] ?? null,
                        'description'         => $entry['description'] ?? null,
                        'system_description'  => $entry['system_description'] ?? null,
                        'type_description'    => $entry['type_description'] ?? null,
                        'failure_code'        => $entry['failure_code'] ?? null,
                        'active_depuis_vol'   => $entry['active_depuis_vol'] ?? null,
                        'active_depuis_date'  => $entry['active_depuis_date'] ?? null,
                        'first_apparition'    => $entry['first_apparition'] ?? null,
                        'score'               => $score,
                        'details'             => $entry,
                    ],
                );
                $synced++;
            }

            return ['synced' => $synced, 'removed' => $removed];
        });
    }

    private function computeScore(string $teId, array $volsHistory): int
    {
        $last3 = array_slice($volsHistory, -3);
        $count = 0;
        foreach ($last3 as $vol) {
            if (in_array($teId, $vol['te_ids'] ?? [], true)) {
                $count++;
            }
        }
        return max(1, $count);
    }
}
```

- [ ] **Step 3: Lancer le test "no file"**

```bash
php artisan test --filter=RecurrentFailuresIngestorTest
```

Expected: PASS pour le test "returns zero counts when occurrentes.json does not exist".

---

## Task 5: Tests d'ingestion — sync, removed, score, idempotence

**Files:**
- Modify: `tests/Feature/RecurrentFailuresIngestorTest.php`

- [ ] **Step 1: Helper pour écrire un JSON fixture**

À la fin du `beforeEach` existant dans `tests/Feature/RecurrentFailuresIngestorTest.php`, ajouter une fonction helper qu'on utilisera dans les tests suivants. Ajouter juste après le `beforeEach` :

```php
function writeOccurrentesFixture(string $hcId, array $active, array $volsHistory = []): string
{
    $dir = storage_path("app/data/reports/occurrentes/{$hcId}");
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $path = $dir . '/occurrentes.json';
    file_put_contents($path, json_encode([
        'hc_id' => $hcId,
        'last_updated' => '2026-05-01T12:00:00',
        'vols_history' => $volsHistory,
        'active' => $active,
    ], JSON_PRETTY_PRINT));
    return $path;
}
```

- [ ] **Step 2: Test "syncs N active entries"**

Ajouter à la fin de `tests/Feature/RecurrentFailuresIngestorTest.php` :

```php
it('syncs active entries from JSON into the table', function () {
    writeOccurrentesFixture('NH99', [
        [
            'id' => 'TE_A',
            'te_description' => 'TE A long desc',
            'description' => 'Desc A',
            'system_description' => 'Sys A',
            'type_description' => 'Fault Code',
            'failure_code' => '111',
            'active_depuis_vol' => 'NH99_2026-04-29_001',
            'active_depuis_date' => '2026-04-29',
            'first_apparition' => '2026-04-28T08:00:00',
        ],
        [
            'id' => 'TE_B',
            'te_description' => 'TE B long desc',
            'description' => 'Desc B',
            'system_description' => 'Sys B',
            'type_description' => 'Fault Code',
            'failure_code' => '222',
            'active_depuis_vol' => 'NH99_2026-04-30_001',
            'active_depuis_date' => '2026-04-30',
            'first_apparition' => '2026-04-29T08:00:00',
        ],
    ], [
        ['vol' => 'V1', 'end_datetime' => '2026-04-28T10:00:00', 'te_ids' => ['TE_A']],
        ['vol' => 'V2', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A', 'TE_B']],
        ['vol' => 'V3', 'end_datetime' => '2026-04-30T10:00:00', 'te_ids' => ['TE_A', 'TE_B']],
    ]);

    $result = $this->ingestor->ingest('NH99');

    expect($result)->toBe(['synced' => 2, 'removed' => 0]);

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(2);

    $teA = $machine->recurrentFailures()->where('technical_event_id', 'TE_A')->first();
    expect($teA->te_description)->toBe('TE A long desc');
    expect($teA->score)->toBe(3); // present in last 3 vols
    $teB = $machine->recurrentFailures()->where('technical_event_id', 'TE_B')->first();
    expect($teB->score)->toBe(2);
});
```

- [ ] **Step 3: Test "removes entries no longer active"**

Ajouter :

```php
it('removes active entries that disappear from JSON between two ingests', function () {
    // Ingest initial : 2 actives
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_A'],
        ['id' => 'TE_B'],
    ]);
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(2);

    // 2e ingest : TE_A retiré, TE_C ajouté
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_B'],
        ['id' => 'TE_C'],
    ]);
    $result = $this->ingestor->ingest('NH99');

    expect($result['removed'])->toBe(1);
    expect($result['synced'])->toBe(2);

    $ids = $machine->recurrentFailures()->where('status', 'active')->pluck('technical_event_id')->sort()->values()->all();
    expect($ids)->toBe(['TE_B', 'TE_C']);
});
```

- [ ] **Step 4: Test "is idempotent (re-ingest same JSON)"**

Ajouter :

```php
it('is idempotent : re-ingesting same JSON does not duplicate rows', function () {
    writeOccurrentesFixture('NH99', [
        ['id' => 'TE_A', 'te_description' => 'first'],
    ]);

    $this->ingestor->ingest('NH99');
    $this->ingestor->ingest('NH99');
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    expect($machine->recurrentFailures()->where('status', 'active')->count())->toBe(1);
});
```

- [ ] **Step 5: Test "updates score on re-ingest"**

Ajouter :

```php
it('updates score and description on re-ingest', function () {
    writeOccurrentesFixture('NH99',
        [['id' => 'TE_A', 'te_description' => 'desc v1']],
        [['vol' => 'V1', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A']]],
    );
    $this->ingestor->ingest('NH99');

    $machine = Machine::where('hc_id', 'NH99')->first();
    $rf = $machine->recurrentFailures()->where('technical_event_id', 'TE_A')->first();
    expect($rf->score)->toBe(1);
    expect($rf->te_description)->toBe('desc v1');

    // 2e ingest : TE_A présent dans 3 vols + description changée
    writeOccurrentesFixture('NH99',
        [['id' => 'TE_A', 'te_description' => 'desc v2']],
        [
            ['vol' => 'V1', 'end_datetime' => '2026-04-29T10:00:00', 'te_ids' => ['TE_A']],
            ['vol' => 'V2', 'end_datetime' => '2026-04-30T10:00:00', 'te_ids' => ['TE_A']],
            ['vol' => 'V3', 'end_datetime' => '2026-05-01T10:00:00', 'te_ids' => ['TE_A']],
        ],
    );
    $this->ingestor->ingest('NH99');

    $rf->refresh();
    expect($rf->score)->toBe(3);
    expect($rf->te_description)->toBe('desc v2');
    // toujours 1 seule ligne (pas de duplication)
    expect($machine->recurrentFailures()->where('technical_event_id', 'TE_A')->count())->toBe(1);
});
```

- [ ] **Step 6: Lancer toute la suite et vérifier qu'elle passe**

```bash
php artisan test --filter=RecurrentFailuresIngestorTest
```

Expected: 5 tests passants (1 du Task 4 + 4 ajoutés ici).

- [ ] **Step 7: Commit**

```bash
git add app/Services/RecurrentFailuresIngestor.php tests/Feature/RecurrentFailuresIngestorTest.php
git commit -m "feat: add RecurrentFailuresIngestor service with sync/upsert/score logic"
```

---

## Task 6: Intégration dans `ProcessXmlJob`

**Files:**
- Modify: `app/Jobs/ProcessXmlJob.php`
- Modify: `tests/Feature/ProcessXmlJobTest.php` (si existant — ajouter un test d'intégration)

- [ ] **Step 1: Lire le fichier ProcessXmlJob actuel pour repérer le bon point d'injection**

```bash
cat app/Jobs/ProcessXmlJob.php
```

Repérer :
- La signature de `handle()` (ligne ~22) qui reçoit déjà `$runner, $importer, $aggIngestor`
- La méthode `handleOk` (ligne ~40) qui appelle `$importer->import()` puis `$aggIngestor->ingest(...)`

- [ ] **Step 2: Ajouter l'import du service en haut du fichier**

Modifier `app/Jobs/ProcessXmlJob.php` ligne ~7-9 (les `use`), ajouter :

```php
use App\Services\RecurrentFailuresIngestor;
```

(à insérer dans l'ordre alphabétique parmi les `use App\Services\...` existants)

- [ ] **Step 3: Modifier la signature de `handle()` pour injecter le service**

Remplacer dans `app/Jobs/ProcessXmlJob.php` :

```php
    public function handle(
        XmlPipelineRunner $runner,
        FlightImporter $importer,
        WeeklyAggregatesIngestor $aggIngestor,
    ): void {
```

par :

```php
    public function handle(
        XmlPipelineRunner $runner,
        FlightImporter $importer,
        WeeklyAggregatesIngestor $aggIngestor,
        RecurrentFailuresIngestor $recurrentIngestor,
    ): void {
```

- [ ] **Step 4: Modifier le `match` pour passer l'ingestor à `handleOk`**

Remplacer :

```php
        match ($result['status']) {
            'ok'        => $this->handleOk($import, $result, $importer, $aggIngestor),
            'no_engine' => $this->handleNonVol($import, $result, $importer),
            default     => $this->handleError($import, $result),
        };
```

par :

```php
        match ($result['status']) {
            'ok'        => $this->handleOk($import, $result, $importer, $aggIngestor, $recurrentIngestor),
            'no_engine' => $this->handleNonVol($import, $result, $importer),
            default     => $this->handleError($import, $result),
        };
```

- [ ] **Step 5: Modifier `handleOk` pour appeler l'ingestor**

Remplacer la signature :

```php
    private function handleOk(Import $import, array $result, FlightImporter $importer, WeeklyAggregatesIngestor $aggIngestor): void
```

par :

```php
    private function handleOk(Import $import, array $result, FlightImporter $importer, WeeklyAggregatesIngestor $aggIngestor, RecurrentFailuresIngestor $recurrentIngestor): void
```

Puis dans le corps de `handleOk`, juste **après** `$aggIngestor->ingest($flight->machine, $year, $pannesCsv, $fhCsv);` et **avant** `$import->update([...])`, ajouter :

```php
            $recurrentIngestor->ingest($result['hc_id']);
```

- [ ] **Step 6: Lancer la suite de tests existante pour vérifier qu'on n'a rien cassé**

```bash
php artisan test --filter=ProcessXmlJobTest
```

Expected: PASS (le DI Laravel résout automatiquement le nouveau service).

- [ ] **Step 7: Lancer la suite globale**

```bash
php artisan test
```

Expected: tous les tests passent.

- [ ] **Step 8: Commit**

```bash
git add app/Jobs/ProcessXmlJob.php
git commit -m "feat: call RecurrentFailuresIngestor after FlightImporter in ProcessXmlJob"
```

---

## Task 7: Eager-load et tri dans `MachineController@index`

**Files:**
- Modify: `app/Http/Controllers/MachineController.php`
- Test: `tests/Feature/MachinesIndexPageTest.php` (nouveau)

- [ ] **Step 1: Écrire un test feature pour la page index**

Créer `tests/Feature/MachinesIndexPageTest.php` :

```php
<?php

use App\Models\Flight;
use App\Models\Machine;
use App\Models\RecurrentFailure;
use App\Models\TechnicalEvent;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('renders /machines with active recurrent failures eager-loaded', function () {
    $machine = Machine::create(['hc_id' => 'NH50']);

    RecurrentFailure::create([
        'machine_id' => $machine->id,
        'technical_event_id' => 'TE_RF1',
        'status' => 'active',
        'te_description' => 'RECURRENT EVENT 1',
        'description' => 'ROTORS FLIGHT CONTROL',
        'system_description' => 'Flight Control System',
        'type_description' => 'Fault Code',
        'score' => 3,
    ]);

    $response = $this->actingAs($this->user)->get('/machines');

    $response->assertOk();
    $response->assertSee('NH50');
    $response->assertSee('RECURRENT EVENT 1');
});

it('renders pannes from the last flight in the row', function () {
    $machine = Machine::create(['hc_id' => 'NH51']);
    $flight = Flight::create([
        'machine_id' => $machine->id,
        'dsn' => 'D1', 'num' => 'N1',
        'start_datetime' => '2026-04-30 08:00:00',
        'end_datetime' => '2026-04-30 10:00:00',
        'flight_type' => 'FLIGHT',
        'flight_hours' => 2.0,
        'is_non_vol' => false,
    ]);
    TechnicalEvent::create([
        'flight_id' => $flight->id,
        'technical_event_id' => 'TE_LF1',
        'raise_datetime' => '2026-04-30 09:30:00',
        'status' => 'conservee',
        'iso_week' => '2026-W18',
        'nombre_occurrences' => 5,
        'details' => [
            'TechnicalEventDescription' => 'LAST FLIGHT EVENT 1',
            'Description' => 'ENGINE',
            'SystemDescription' => 'Engine System',
            'TypeDescription' => 'Fault Code',
        ],
    ]);

    $response = $this->actingAs($this->user)->get('/machines');

    $response->assertOk();
    $response->assertSee('NH51');
    $response->assertSee('LAST FLIGHT EVENT 1');
});
```

- [ ] **Step 2: Lancer les tests (doivent ÉCHOUER car la vue n'affiche pas encore ces infos)**

```bash
php artisan test --filter=MachinesIndexPageTest
```

Expected: les 2 tests **échouent** (textes "RECURRENT EVENT 1" et "LAST FLIGHT EVENT 1" absents). On les fera passer dans la Task 8.

- [ ] **Step 3: Modifier le contrôleur pour eager-loader les bonnes données**

Remplacer dans `app/Http/Controllers/MachineController.php` la méthode `index()` (lignes ~10-22) par :

```php
    public function index()
    {
        $machines = Machine::withCount([
            'flights as vols_count' => fn ($q) => $q->where('is_non_vol', false),
            'flights as non_vols_count' => fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false),
            'flights as erreurs_count' => fn ($q) => $q->where('flagged_as_error', true),
            'recurrentFailures as active_count' => fn ($q) => $q->where('status', 'active'),
        ])
        ->with([
            'recurrentFailures' => fn ($q) => $q->where('status', 'active')->orderByDesc('score'),
            'flights' => fn ($q) => $q->latest('start_datetime')
                ->with(['technicalEvents' => fn ($q2) => $q2->where('status', 'conservee')->orderByDesc('nombre_occurrences')])
                ->limit(1),
        ])
        ->orderBy('hc_id')
        ->get();

        return view('machines.index', compact('machines'));
    }
```

- [ ] **Step 4: Commit le contrôleur (la vue suit)**

```bash
git add app/Http/Controllers/MachineController.php tests/Feature/MachinesIndexPageTest.php
git commit -m "feat: eager-load recurrent failures and last flight pannes in MachineController@index"
```

---

## Task 8: Refonte du layout de la ligne machine (sans widgets)

**Files:**
- Modify: `resources/views/machines/index.blade.php`

> Ce task se concentre sur la **réorganisation** du grid (HcId / compteurs / espace droite) et la suppression du `<a>` global. Les widgets eux-mêmes seront ajoutés au Task 9-10.

- [ ] **Step 1: Lire la vue actuelle pour avoir la version de référence**

```bash
cat resources/views/machines/index.blade.php
```

- [ ] **Step 2: Réécrire le bloc d'une ligne machine**

Remplacer le bloc `@foreach ($machines as $m) ... @endforeach` (lignes ~20-71) par :

```blade
            @foreach ($machines as $m)
                <div class="grid grid-cols-12 gap-4 px-6 py-4 items-start hover:bg-slate-50 transition">
                    {{-- HcId : col-span-2 --}}
                    <a href="{{ route('machines.show', $m->hc_id) }}"
                       class="col-span-2 flex items-center gap-3 group">
                        <div class="w-10 h-10 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-blue-600">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                            </svg>
                        </div>
                        <div>
                            <p class="text-base font-bold text-slate-900 group-hover:text-blue-600 transition">{{ $m->hc_id }}</p>
                            <p class="text-xs text-slate-500">{{ $m->vols_count + $m->non_vols_count + $m->erreurs_count }} enregistrement(s)</p>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-slate-300 group-hover:text-blue-500 transition ml-1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>

                    {{-- Compteurs : col-span-3 --}}
                    <div class="col-span-3 grid grid-cols-3 gap-2">
                        <div class="flex flex-col items-center">
                            <p class="text-xl font-bold text-slate-900 tabular-nums leading-none">{{ $m->vols_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Vols</p>
                        </div>
                        <div class="flex flex-col items-center">
                            <p class="text-xl font-bold text-slate-900 tabular-nums leading-none">{{ $m->non_vols_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Non-vols</p>
                        </div>
                        <div class="flex flex-col items-center">
                            <p @class([
                                'text-xl font-bold tabular-nums leading-none',
                                'text-red-600' => $m->erreurs_count > 0,
                                'text-slate-900' => $m->erreurs_count === 0,
                            ])>{{ $m->erreurs_count }}</p>
                            <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium mt-1">Erreurs</p>
                        </div>
                    </div>

                    {{-- Widgets : col-span-7 (placeholders ; remplis aux tasks 9-10) --}}
                    <div class="col-span-7 grid grid-cols-2 gap-3">
                        @include('machines.partials.widget-recurrent', ['machine' => $m])
                        @include('machines.partials.widget-last-flight', ['machine' => $m])
                    </div>
                </div>
            @endforeach
```

- [ ] **Step 3: Créer des partials placeholders vides pour que la vue ne plante pas**

Créer `resources/views/machines/partials/widget-recurrent.blade.php` :

```blade
<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 text-xs text-slate-400">
    {{-- placeholder widget 1, rempli au task 9 --}}
    Widget recurrent — {{ $machine->hc_id }}
</div>
```

Créer `resources/views/machines/partials/widget-last-flight.blade.php` :

```blade
<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 text-xs text-slate-400">
    {{-- placeholder widget 2, rempli au task 10 --}}
    Widget last flight — {{ $machine->hc_id }}
</div>
```

- [ ] **Step 4: Vérifier que la page se charge sans erreur**

```bash
php artisan test --filter=MachinesIndexPageTest
```

Expected: `assertOk` passe pour les 2 tests, mais `assertSee('RECURRENT EVENT 1')` et `assertSee('LAST FLIGHT EVENT 1')` échouent encore (normal — widgets pas encore implémentés).

- [ ] **Step 5: Commit**

```bash
git add resources/views/machines/index.blade.php resources/views/machines/partials/
git commit -m "feat: redesign machines/index row layout (HcId | counts | widgets area)"
```

---

## Task 9: Widget 1 — pannes occurrentes actives

**Files:**
- Modify: `resources/views/machines/partials/widget-recurrent.blade.php`
- Create: `resources/views/machines/partials/modal-recurrent.blade.php`

- [ ] **Step 1: Écrire le widget**

Remplacer **tout** le contenu de `resources/views/machines/partials/widget-recurrent.blade.php` par :

```blade
@php
    $actives = $machine->recurrentFailures;
    $count = $actives->count();
    $top = $actives->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-recurrent-{$machine->hc_id}";
@endphp

<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 flex flex-col">
    <div class="flex items-center justify-between mb-2">
        <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium">Pannes occurrentes actives</p>
        <p class="text-sm font-semibold text-slate-700 tabular-nums">{{ $count }}</p>
    </div>

    @if ($count === 0)
        <p class="text-xs text-slate-400 italic py-2">— Aucune panne occurrente active —</p>
    @else
        <ul class="space-y-2">
            @foreach ($top as $rf)
                <li class="text-xs">
                    <p class="text-slate-900 font-medium truncate" title="{{ $rf->te_description }}">
                        {{ $rf->te_description ?? $rf->technical_event_id }}
                    </p>
                    <p class="text-[11px] text-slate-500 truncate">
                        {{ $rf->description }} · {{ $rf->system_description }} · {{ $rf->type_description }}
                    </p>
                </li>
            @endforeach
        </ul>

        @if ($rest > 0)
            <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                    class="text-xs text-blue-600 hover:text-blue-700 font-medium mt-2 self-start">
                voir plus ({{ $rest }})
            </button>
        @endif

        @include('machines.partials.modal-recurrent', ['machine' => $machine, 'actives' => $actives, 'modalId' => $modalId])
    @endif
</div>
```

- [ ] **Step 2: Créer le modal**

Créer `resources/views/machines/partials/modal-recurrent.blade.php` :

```blade
<x-modal name="{{ $modalId }}" maxWidth="2xl">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">
            Pannes occurrentes actives — {{ $machine->hc_id }}
        </h2>
        <div class="space-y-3 max-h-[60vh] overflow-y-auto">
            @foreach ($actives as $rf)
                <div class="border border-slate-200 rounded-lg p-3">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm text-slate-900 font-medium">{{ $rf->te_description ?? $rf->technical_event_id }}</p>
                        <span class="text-[11px] px-2 py-0.5 rounded bg-blue-100 text-blue-700 font-medium tabular-nums">
                            {{ $rf->score }}/3
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $rf->description }} · {{ $rf->system_description }} · {{ $rf->type_description }}
                    </p>
                </div>
            @endforeach
        </div>
        <div class="mt-5 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
        </div>
    </div>
</x-modal>
```

- [ ] **Step 3: Lancer le test du widget 1**

```bash
php artisan test --filter='MachinesIndexPageTest::renders /machines with active recurrent failures'
```

Expected: PASS (le test cherche "RECURRENT EVENT 1" qui apparaît maintenant dans le widget).

- [ ] **Step 4: Commit**

```bash
git add resources/views/machines/partials/widget-recurrent.blade.php resources/views/machines/partials/modal-recurrent.blade.php
git commit -m "feat: add widget for active recurrent failures with see-more modal"
```

---

## Task 10: Widget 2 — pannes du dernier vol

**Files:**
- Modify: `resources/views/machines/partials/widget-last-flight.blade.php`
- Create: `resources/views/machines/partials/modal-last-flight.blade.php`

- [ ] **Step 1: Écrire le widget**

Remplacer **tout** le contenu de `resources/views/machines/partials/widget-last-flight.blade.php` par :

```blade
@php
    $lastFlight = $machine->flights->first();
    $pannes = $lastFlight?->technicalEvents ?? collect();
    $count = $pannes->count();
    $top = $pannes->take(3);
    $rest = max(0, $count - 3);
    $modalId = "modal-last-flight-{$machine->hc_id}";
    $flightDate = $lastFlight?->start_datetime?->format('d/m/Y');
@endphp

<div class="bg-slate-50 rounded-lg border border-slate-200 p-3 flex flex-col">
    <div class="flex items-center justify-between mb-2">
        <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium">Pannes dernier vol</p>
        <div class="flex items-center gap-2">
            @if ($flightDate)
                <span class="text-[11px] text-slate-500 tabular-nums">{{ $flightDate }}</span>
            @endif
            <span class="text-sm font-semibold text-slate-700 tabular-nums">{{ $count }}</span>
        </div>
    </div>

    @if ($count === 0)
        <p class="text-xs text-slate-400 italic py-2">— Aucune panne sur le dernier vol —</p>
    @else
        <ul class="space-y-2">
            @foreach ($top as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $desc = $d['Description'] ?? '';
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                @endphp
                <li class="text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-slate-900 font-medium truncate flex-1" title="{{ $teDesc }}">
                            {{ $teDesc }}
                        </p>
                        <span class="text-[11px] px-1.5 py-0.5 rounded bg-slate-200 text-slate-700 tabular-nums font-medium">
                            ×{{ $te->nombre_occurrences }}
                        </span>
                    </div>
                    <p class="text-[11px] text-slate-500 truncate">
                        {{ $desc }} · {{ $sysDesc }} · {{ $typeDesc }}
                    </p>
                </li>
            @endforeach
        </ul>

        @if ($rest > 0)
            <button x-data x-on:click="$dispatch('open-modal', '{{ $modalId }}')"
                    class="text-xs text-blue-600 hover:text-blue-700 font-medium mt-2 self-start">
                voir plus ({{ $rest }})
            </button>
        @endif

        @include('machines.partials.modal-last-flight', [
            'machine' => $machine,
            'flightDate' => $flightDate,
            'pannes' => $pannes,
            'modalId' => $modalId,
        ])
    @endif
</div>
```

- [ ] **Step 2: Créer le modal**

Créer `resources/views/machines/partials/modal-last-flight.blade.php` :

```blade
<x-modal name="{{ $modalId }}" maxWidth="2xl">
    <div class="p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">
            Pannes du dernier vol — {{ $machine->hc_id }} — {{ $flightDate }}
        </h2>
        <div class="space-y-3 max-h-[60vh] overflow-y-auto">
            @foreach ($pannes as $te)
                @php
                    $d = is_array($te->details) ? $te->details : [];
                    $teDesc = $d['TechnicalEventDescription'] ?? $te->technical_event_id;
                    $desc = $d['Description'] ?? '';
                    $sysDesc = $d['SystemDescription'] ?? '';
                    $typeDesc = $d['TypeDescription'] ?? '';
                @endphp
                <div class="border border-slate-200 rounded-lg p-3">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm text-slate-900 font-medium">{{ $teDesc }}</p>
                        <span class="text-[11px] px-2 py-0.5 rounded bg-slate-200 text-slate-700 font-medium tabular-nums">
                            ×{{ $te->nombre_occurrences }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $desc }} · {{ $sysDesc }} · {{ $typeDesc }}
                    </p>
                </div>
            @endforeach
        </div>
        <div class="mt-5 flex justify-end">
            <x-secondary-button x-on:click="$dispatch('close')">Fermer</x-secondary-button>
        </div>
    </div>
</x-modal>
```

- [ ] **Step 3: Lancer le test du widget 2**

```bash
php artisan test --filter='MachinesIndexPageTest::renders pannes from the last flight'
```

Expected: PASS (le test cherche "LAST FLIGHT EVENT 1" qui apparaît dans le widget).

- [ ] **Step 4: Lancer toute la suite globale**

```bash
php artisan test
```

Expected: tous les tests passent.

- [ ] **Step 5: Commit**

```bash
git add resources/views/machines/partials/widget-last-flight.blade.php resources/views/machines/partials/modal-last-flight.blade.php
git commit -m "feat: add widget for last-flight pannes with see-more modal"
```

---

## Task 11: Smoke test manuel (browser)

**Files:** aucun.

- [ ] **Step 1: Vérifier que les services tournent**

```bash
ss -ltnp 2>/dev/null | grep -E ':(8000)\b' | head -1
ps aux | grep -E 'queue:work' | grep -v grep | head -1
```

Expected: voir `php artisan serve` sur :8000 et un `queue:work` actif. Si pas de queue worker, le lancer :

```bash
php artisan queue:work --tries=1 &
```

- [ ] **Step 2: Uploader un XML test**

Demander à l'utilisateur (ou utiliser un XML existant dans `nh-pipeline/raw/`) :

1. Ouvrir `http://127.0.0.1:8000` dans le navigateur, login `test@nh.local` / `password`
2. Aller sur `/upload`, uploader un XML
3. Attendre que `/imports` montre le statut "ok"

- [ ] **Step 3: Vérifier la page /machines**

1. Aller sur `/machines`
2. Vérifier visuellement :
   - Le HcId est à gauche, cliquable, redirige vers `/machines/{HcId}`
   - Les compteurs Vols/Non-Vols/Erreurs sont à droite du HcId
   - 2 widgets côte à côte à droite
   - Widget 1 affiche les pannes occurrentes actives (si la machine a ≥2 vols avec pannes communes)
   - Widget 2 affiche les pannes du dernier vol avec le badge `×N` (nombre_occurrences)
3. Cliquer sur "voir plus" d'un widget → un modal s'ouvre avec la liste complète, le bouton "Fermer" le ferme.

- [ ] **Step 4: Vérifier l'état de la BDD**

```bash
php artisan tinker --execute="echo \App\Models\RecurrentFailure::with('machine')->get()->map(fn(\$rf) => \$rf->machine->hc_id . ' / ' . \$rf->technical_event_id . ' / score=' . \$rf->score)->implode(\"\n\");"
```

Expected: liste des actives par machine. Cohérent avec le contenu des `occurrentes.json` produits par la pipeline.

- [ ] **Step 5: Si tout est OK, push (à la demande de l'utilisateur)**

```bash
git log --oneline -10
# Ne pas push sans demande explicite — laisser la décision à l'utilisateur
```

---

## Notes / pièges connus

- **`<x-modal>` Breeze** : utilise Alpine.js et écoute l'event `open-modal` avec le nom passé. Voir `resources/views/components/modal.blade.php` pour la signature.
- **`<x-secondary-button>`** : composant Breeze déjà présent dans le projet pour le bouton "Fermer".
- **Tests Pest** : `RefreshDatabase` est appliqué automatiquement via `tests/Pest.php`. Les fixtures de fichiers (occurrentes.json) doivent être nettoyées explicitement dans `beforeEach` car elles ne sont pas dans la BDD.
- **Index partiel Postgres** : la contrainte unique sur (machine_id, technical_event_id) WHERE status='active' est gérée via `DB::statement(...)` car Schema Blueprint Laravel ne supporte pas les conditions WHERE dans les index. La migration `down()` n'a pas besoin de drop l'index (Postgres le drop avec la table).
- **DI ProcessXmlJob** : le service `RecurrentFailuresIngestor` est résolu automatiquement par le container Laravel. Pas de binding manuel à ajouter dans `AppServiceProvider`.
- **Sécurité du test feature** : `User::factory()->create()` puis `actingAs($this->user)` car les routes `/machines` sont sous middleware `auth`.
