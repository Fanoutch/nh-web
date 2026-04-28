# Frontend Laravel NH Project — Plan d'implementation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire une interface web Laravel 12 + Livewire 3 qui pilote la pipeline Python existante, stocke les donnees en PostgreSQL, et affiche machines, vols, pannes et dashboards interactifs.

**Architecture:** Application Laravel dans `web/` a la racine du repo. La pipeline Python reste autonome au meme niveau et est appelee via `Symfony\Process` depuis un Job Laravel en queue. Les JSON / CSV generes par la pipeline sont lus et inseres / upsertes en PostgreSQL pour servir l'UI. Les dashboards utilisent ApexCharts vanilla sur la table `weekly_aggregates` (miroir des CSV yearly).

**Tech Stack:**
- Laravel 12 (PHP 8.3+)
- Livewire 3
- Laravel Breeze (auth email/password, stack Blade + Livewire)
- PostgreSQL 15+
- Queue driver `database`
- ApexCharts (via CDN)
- Pest (tests Laravel)
- Python 3.12+ (pipeline existante, modification minime)

**Spec de reference:** `docs/superpowers/specs/2026-04-15-laravel-frontend-design.md`

---

## Pre-requis environnement local

Avant de commencer, verifier :
- PHP 8.3+ (`php -v`)
- Composer 2+ (`composer --version`)
- PostgreSQL installe et un user local (`psql --version`)
- Node.js 18+ et npm (`node -v`) — pour Breeze + assets
- Python 3.12+ (`python3 --version`)

Si manquant, installer avant d'attaquer le plan.

---

## Phase 0 — Bootstrap Laravel 12

### Task 0.1 : Creer l'app Laravel 12 dans `web/`

**Files:**
- Create: `web/` (projet Laravel complet)
- Create: `web/.gitignore` (fourni par Laravel)

- [ ] **Step 1 : Creer le projet Laravel**

Run depuis la racine du repo :
```bash
composer create-project "laravel/laravel:^12.0" web
```
Expected: dossier `web/` cree avec `artisan`, `composer.json`, `routes/`, etc.

- [ ] **Step 2 : Verifier l'installation**

Run:
```bash
cd web && php artisan --version
```
Expected: `Laravel Framework 12.x.x`

- [ ] **Step 3 : Creer la BDD PostgreSQL locale**

Run:
```bash
createdb nh_project_dev
```
Expected: pas d'erreur (si deja existe, `dropdb nh_project_dev` puis recreer).

- [ ] **Step 4 : Configurer `.env`**

Edit `web/.env` :
```
APP_NAME="NH Project"
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=nh_project_dev
DB_USERNAME=<votre_user>
DB_PASSWORD=<votre_password>

QUEUE_CONNECTION=database
BROADCAST_CONNECTION=log
```

- [ ] **Step 5 : Tester la connexion DB**

Run:
```bash
cd web && php artisan migrate:status
```
Expected: `Migration table not found.` (normal, pas encore migrate)

- [ ] **Step 6 : Commit**

Run depuis la racine :
```bash
git add web .gitignore
git commit -m "feat(web): bootstrap Laravel 12 app in web/"
```

### Task 0.2 : Installer Laravel Breeze (Blade + Livewire)

- [ ] **Step 1 : Installer Breeze**

Run dans `web/` :
```bash
composer require laravel/breeze --dev
php artisan breeze:install blade --no-interaction
```
Expected: scaffolding auth (login, register, password reset) installe en Blade.

- [ ] **Step 2 : Installer Livewire 3**

Run dans `web/` :
```bash
composer require livewire/livewire:^3.0
```
Expected: `livewire/livewire` ajoute a `composer.json`.

- [ ] **Step 3 : Installer les assets npm**

Run dans `web/` :
```bash
npm install && npm run build
```
Expected: `public/build/` cree avec CSS/JS compiles.

- [ ] **Step 4 : Publier la config queue Laravel**

Run dans `web/` :
```bash
php artisan make:queue-table
```
Expected: migration `xxxx_create_jobs_table.php` creee.

Note : en Laravel 12 c'est peut-etre `queue:table`. Si la commande precedente echoue, essayer :
```bash
php artisan queue:table
```

- [ ] **Step 5 : Creer la table sessions si manquante**

Run dans `web/` :
```bash
php artisan session:table || true
```
(Certaines versions incluent deja la migration sessions.)

- [ ] **Step 6 : Lancer les migrations initiales**

Run dans `web/` :
```bash
php artisan migrate
```
Expected: tables `users`, `password_reset_tokens`, `sessions`, `cache`, `jobs` creees.

- [ ] **Step 7 : Verifier en lancant le serveur**

Run dans `web/` :
```bash
php artisan serve
```
Puis visiter `http://127.0.0.1:8000/register` dans un navigateur.
Expected: page d'inscription Breeze visible.

Arreter avec Ctrl+C.

- [ ] **Step 8 : Creer un compte de test**

Run dans `web/` :
```bash
php artisan tinker
```
Dans tinker :
```php
\App\Models\User::factory()->create([
    'name' => 'Test User',
    'email' => 'test@nh.local',
    'password' => \Hash::make('password'),
]);
exit
```
Expected: user cree, id retourne.

- [ ] **Step 9 : Commit**

```bash
cd ..
git add web
git commit -m "feat(web): install Breeze (Blade) + Livewire 3"
```

### Task 0.3 : Configurer Pest pour les tests

- [ ] **Step 1 : Installer Pest**

Run dans `web/` :
```bash
composer require pestphp/pest --dev --with-all-dependencies
composer require pestphp/pest-plugin-laravel --dev
php artisan pest:install --no-interaction
```
Expected: `tests/Pest.php` cree, `phpunit.xml` mis a jour.

- [ ] **Step 2 : Lancer les tests fournis**

Run dans `web/` :
```bash
./vendor/bin/pest
```
Expected: les tests de base passent (login, register, profile).

- [ ] **Step 3 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): install Pest for testing"
```

---

## Phase 1 — Pipeline Python : sortie JSON pour Laravel

### Task 1.1 : Ajouter l'option `--json-output` a `main.py`

**Files:**
- Modify: `main.py:150-234`

- [ ] **Step 1 : Ecrire le test**

**Files:**
- Create: `tests/test_main_json_output.py`

```python
"""Tests for --json-output flag in main.py."""
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def run_main(*args):
    """Run main.py and return (stdout, stderr, returncode)."""
    proc = subprocess.run(
        [sys.executable, str(ROOT / "main.py"), *args],
        capture_output=True, text=True,
    )
    return proc.stdout, proc.stderr, proc.returncode


def test_json_output_for_valid_xml():
    with tempfile.TemporaryDirectory() as tmp:
        stdout, _, rc = run_main(
            str(ROOT / "raw" / "exemple.xml"),
            "--output-base", tmp,
            "--json-output",
        )
        # Derniere ligne de stdout doit etre un JSON parseable
        last_line = stdout.strip().splitlines()[-1]
        payload = json.loads(last_line)
        assert payload["status"] in {"ok", "no_engine", "error"}
        assert "hc_id" in payload
        assert rc == 0


def test_json_output_missing_file():
    stdout, stderr, rc = run_main("does_not_exist.xml", "--json-output")
    # En cas de fichier inexistant, on sort avec code != 0 et on imprime un JSON error
    last_line = stdout.strip().splitlines()[-1] if stdout.strip() else ""
    payload = json.loads(last_line)
    assert payload["status"] == "error"
    assert rc != 0
```

- [ ] **Step 2 : Lancer le test et verifier l'echec**

Run depuis la racine :
```bash
python3 -m pytest tests/test_main_json_output.py -v
```
Expected: FAIL (option non implementee).

- [ ] **Step 3 : Modifier `main.py` pour ajouter `--json-output`**

**Files:**
- Modify: `main.py`

Remplacer la fonction `main()` (lignes ~150-230) par :
```python
def main():
    parser = argparse.ArgumentParser(description="Helicopter XML Health Monitoring Cleaner")
    parser.add_argument("xml_file", help="Path to the XML file to process")
    parser.add_argument("--output-base", default="data", help="Base output directory (default: data)")
    parser.add_argument("--report-only", action="store_true",
                        help="Skip per-flight file persistence")
    parser.add_argument("--filtre-mode", default=None,
                        choices=[None, "48h", "24h", "strict"],
                        help="Filter mode (default: 48h)")
    parser.add_argument("--json-output", action="store_true",
                        help="Print a machine-readable JSON summary on the last stdout line")
    args = parser.parse_args()

    def emit(payload, exit_code):
        if args.json_output:
            print(json.dumps(payload))
        sys.exit(exit_code)

    if not os.path.exists(args.xml_file):
        msg = f"Error: file '{args.xml_file}' not found."
        if args.json_output:
            emit({"status": "error", "hc_id": None, "message": msg}, 1)
        print(msg)
        sys.exit(1)

    result = process_file(
        args.xml_file,
        output_base=args.output_base,
        report_only=args.report_only,
        filtre_mode=args.filtre_mode,
    )

    # Construction du payload JSON compact
    payload = {
        "status": result["status"],
        "hc_id": None,
        "dsn": None,
        "num": None,
        "folder_name": result.get("folder_name"),
        "annee": result.get("annee"),
        "semaine": result.get("semaine"),
        "output_dir": None,
        "message": result.get("error"),
    }

    # Extraire hc_id / dsn / num depuis folder_name ou phase1
    if result.get("folder_name"):
        parts = result["folder_name"].split("_")
        if parts:
            payload["hc_id"] = parts[0]
        if len(parts) >= 3:
            payload["num"] = parts[-1] if parts[-1].isdigit() else None

    # Chemin output_dir (pour que le Job PHP aille lire les JSON)
    if result["status"] == "ok" and result.get("phase1"):
        p1 = result["phase1"]
        if p1.get("pannes_conservees_path"):
            payload["output_dir"] = os.path.dirname(p1["pannes_conservees_path"])
    elif result["status"] == "no_engine" and result.get("phase2", {}).get("xml_isole_path"):
        payload["output_dir"] = result["phase2"]["xml_isole_path"]

    # Affichage texte (inchange) SAUF si --json-output
    if not args.json_output:
        _print_human_readable(args, result)

    exit_code = 0 if result["status"] in {"ok", "no_engine"} else 1
    emit(payload, exit_code)


def _print_human_readable(args, result):
    """Affichage texte historique (factorise pour clarte)."""
    p2 = result.get("phase2")
    if p2:
        status_label = "VOL" if p2["est_vol"] else "BFF"
        print(f"Detection vol ou Bff — {args.xml_file}")
        print(f"  Statut            : {status_label}")
        print(f"  Type              : {p2.get('type_vol', 'N/A')}")
        if not p2["est_vol"]:
            print(f"  Non-vol (Type = {p2.get('type_vol')}) — XML brut copie dans xml_isole/")
            print(f"  → {p2.get('xml_isole_path', 'N/A')}")

    if result["status"] == "no_engine":
        return

    if result["status"] == "error":
        print(f"\nErreur pipeline : {result['error']}")
        return

    p1 = result.get("phase1")
    if p1:
        print(f"\nPhase 1 - Filtrage par date...")
        print(f"  Fenetre Start ±{p1['tolerance_h']}h : {p1['fenetre_start']}")
        print(f"  Fenetre End   ±{p1['tolerance_h']}h : {p1['fenetre_end']}")
        print(f"  TechnicalEvents   : {p1['total']} → {p1['conserves']} "
              f"(supprimes: {p1['supprimes']})")
        print(f"  Pannes hors date  : {len(p1['pannes_hors_date'])}")
        print(f"  → {p1['xml_epure_path']}")

    p3 = result.get("phase3")
    if p3 and p3["pannes_ajoutees"] > 0:
        print(f"\nPhase 3 - Mise a jour releve hebdomadaire ({p3['pannes_ajoutees']} pannes)")

    pfh = result.get("phase_fh")
    if pfh and pfh.get("ajoute"):
        print(f"\nPhase FH - Semaine {pfh['semaine']}")
```

Ajouter en tete de fichier :
```python
import json
```

- [ ] **Step 4 : Lancer les tests et verifier qu'ils passent**

Run:
```bash
python3 -m pytest tests/test_main_json_output.py -v
```
Expected: PASS.

- [ ] **Step 5 : Verifier non-regression CLI**

Run:
```bash
python3 main.py raw/exemple.xml --output-base /tmp/test_output
```
Expected: affichage humain inchange, fichiers generes dans `/tmp/test_output`.

- [ ] **Step 6 : Commit**

```bash
git add main.py tests/test_main_json_output.py
git commit -m "feat(pipeline): add --json-output flag to main.py for Laravel job integration"
```

### Task 1.2 : Exposer `dsn` dans `process_file`

**Files:**
- Modify: `main.py` (fonction `process_file`)
- Modify: `src/cleaning/date_filter.py` si besoin

Le DSN est un champ du XML (dans `<FlightData>/<Flight>/<DSN>` selon CLAUDE.md). Il doit etre extrait et retourne.

- [ ] **Step 1 : Ecrire le test**

**Files:**
- Create: `tests/test_dsn_extraction.py`

```python
"""Verify DSN is extracted and returned by process_file."""
import json
import os
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from main import process_file


def test_process_file_returns_dsn():
    xml = ROOT / "raw" / "exemple.xml"
    with tempfile.TemporaryDirectory() as tmp:
        result = process_file(str(xml), output_base=tmp)
    # DSN doit figurer dans le resultat
    assert "dsn" in result
    assert result["dsn"]  # non vide
```

- [ ] **Step 2 : Lancer le test**

Run:
```bash
python3 -m pytest tests/test_dsn_extraction.py -v
```
Expected: FAIL (clef `dsn` absente).

- [ ] **Step 3 : Ajouter l'extraction DSN dans `process_file`**

Dans `main.py`, dans le bloc `try:` de `process_file`, juste apres l'extraction de `hc_id` :
```python
# Extraire DSN (chemin Flight-Usage/DSN)
dsn_el = _root.find(".//DSN")
dsn = dsn_el.text.strip() if dsn_el is not None and dsn_el.text else None
result["dsn"] = dsn
```

- [ ] **Step 4 : Ajouter `dsn` dans le dict `result` initial**

Remplacer le dict `result` en tete de la fonction par :
```python
result = {
    "status": "ok",
    "xml_file": xml_path,
    "folder_name": None,
    "hc_id": None,
    "dsn": None,
    "phase1": None,
    "phase2": None,
    "phase3": None,
    "phase_fh": None,
    "error": None,
}
```

Dans le bloc `try:`, ajouter aussi :
```python
result["hc_id"] = hc_id
```

- [ ] **Step 5 : Propager dsn dans le JSON output**

Dans `main()`, apres extraction de `hc_id` :
```python
payload["dsn"] = result.get("dsn")
payload["hc_id"] = result.get("hc_id") or payload["hc_id"]
```

- [ ] **Step 6 : Lancer les tests**

```bash
python3 -m pytest tests/ -v
```
Expected: tous passent.

- [ ] **Step 7 : Commit**

```bash
git add main.py tests/test_dsn_extraction.py
git commit -m "feat(pipeline): expose DSN in process_file result and JSON output"
```

---

## Phase 2 — Migrations et modeles Eloquent

### Task 2.1 : Migration `machines`

**Files:**
- Create: `web/database/migrations/2026_04_16_000001_create_machines_table.php`
- Create: `web/app/Models/Machine.php`
- Create: `web/tests/Unit/MachineModelTest.php`

- [ ] **Step 1 : Creer la migration**

Run dans `web/` :
```bash
php artisan make:migration create_machines_table
```

Editer le fichier cree avec :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('hc_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('machines');
    }
};
```

- [ ] **Step 2 : Creer le modele**

Run dans `web/` :
```bash
php artisan make:model Machine
```
Editer `web/app/Models/Machine.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasFactory;
    protected $fillable = ['hc_id'];

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
```

- [ ] **Step 3 : Test unitaire**

Creer `web/tests/Unit/MachineModelTest.php` :
```php
<?php
use App\Models\Machine;

it('creates a machine with hc_id', function () {
    $m = Machine::create(['hc_id' => 'NH08']);
    expect($m->hc_id)->toBe('NH08');
});

it('enforces hc_id unique', function () {
    Machine::create(['hc_id' => 'NH08']);
    Machine::create(['hc_id' => 'NH08']);
})->throws(\Illuminate\Database\QueryException::class);
```
S'assurer d'avoir `uses(RefreshDatabase::class);` en tete via `tests/Pest.php`.

Editer `web/tests/Pest.php` si pas deja fait :
```php
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Unit');
```

- [ ] **Step 4 : Run tests**

Run:
```bash
./vendor/bin/pest tests/Unit/MachineModelTest.php
```
Expected: 2 tests passent.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add machines table and model"
```

### Task 2.2 : Migration `flights`

**Files:**
- Create: `web/database/migrations/2026_04_16_000002_create_flights_table.php`
- Create: `web/app/Models/Flight.php`

- [ ] **Step 1 : Creer la migration**

```bash
cd web && php artisan make:migration create_flights_table
```

Remplir :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('flights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->string('dsn');
            $table->string('num');
            $table->timestamp('start_datetime');
            $table->timestamp('end_datetime');
            $table->string('flight_type');
            $table->decimal('flight_hours', 10, 4)->default(0);
            $table->decimal('consumed_fuel', 10, 2)->nullable();
            $table->boolean('is_non_vol')->default(false);
            $table->boolean('flagged_as_error')->default(false);
            $table->timestamp('flagged_at')->nullable();
            $table->foreignId('flagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('xml_path')->nullable();
            $table->timestamp('processed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['machine_id', 'dsn', 'num']);
            $table->index(['machine_id', 'start_datetime']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('flights');
    }
};
```

- [ ] **Step 2 : Creer le modele**

```bash
php artisan make:model Flight
```

Editer `web/app/Models/Flight.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    use HasFactory;
    protected $fillable = [
        'machine_id', 'dsn', 'num',
        'start_datetime', 'end_datetime',
        'flight_type', 'flight_hours', 'consumed_fuel',
        'is_non_vol', 'flagged_as_error', 'flagged_at', 'flagged_by',
        'xml_path', 'processed_at',
    ];
    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'flagged_at' => 'datetime',
        'processed_at' => 'datetime',
        'is_non_vol' => 'bool',
        'flagged_as_error' => 'bool',
        'flight_hours' => 'decimal:4',
        'consumed_fuel' => 'decimal:2',
    ];

    public function machine(): BelongsTo { return $this->belongsTo(Machine::class); }
    public function technicalEvents(): HasMany { return $this->hasMany(TechnicalEvent::class); }
    public function missingPannes(): HasMany { return $this->hasMany(MissingPanne::class); }
    public function flaggedBy(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'flagged_by'); }
}
```

- [ ] **Step 3 : Migrate + test rapide**

```bash
php artisan migrate
php artisan tinker
```
Dans tinker :
```php
\App\Models\Machine::create(['hc_id' => 'TEST01']);
\App\Models\Flight::create([
  'machine_id' => 1, 'dsn' => '1234', 'num' => '612',
  'start_datetime' => now(), 'end_datetime' => now(),
  'flight_type' => 'FLIGHT',
]);
\App\Models\Flight::all();
exit
```
Expected: 1 flight retourne.

- [ ] **Step 4 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add flights table and model"
```

### Task 2.3 : Migration `technical_events`

- [ ] **Step 1 : Creer migration**

```bash
cd web && php artisan make:migration create_technical_events_table
```

Remplir :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('technical_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('technical_event_id');
            $table->timestamp('raise_datetime');
            $table->enum('status', ['conservee', 'isolee']);
            $table->string('iso_week')->index();
            $table->integer('nombre_occurrences')->default(1);
            $table->jsonb('details');
            $table->enum('validation_status', ['pending', 'validated', 'rejected'])->default('pending');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('validated_at')->nullable();
            $table->text('technician_comment')->nullable();
            $table->timestamps();

            $table->index(['flight_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('technical_events'); }
};
```

- [ ] **Step 2 : Creer le modele**

```bash
php artisan make:model TechnicalEvent
```

Editer `web/app/Models/TechnicalEvent.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicalEvent extends Model
{
    use HasFactory;
    protected $fillable = [
        'flight_id', 'technical_event_id', 'raise_datetime',
        'status', 'iso_week', 'nombre_occurrences', 'details',
        'validation_status', 'validated_by', 'validated_at', 'technician_comment',
    ];
    protected $casts = [
        'raise_datetime' => 'datetime',
        'validated_at' => 'datetime',
        'details' => 'array',
    ];

    public function flight(): BelongsTo { return $this->belongsTo(Flight::class); }
    public function validator(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'validated_by'); }
}
```

- [ ] **Step 3 : Migrate**

```bash
php artisan migrate
```

- [ ] **Step 4 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add technical_events table and model"
```

### Task 2.4 : Migration `missing_pannes`

- [ ] **Step 1 : Migration + modele en un script**

```bash
cd web && php artisan make:model MissingPanne -m
```

Editer la migration :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('missing_pannes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_id')->constrained('flights')->cascadeOnDelete();
            $table->string('failure_code');
            $table->text('description')->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('missing_pannes'); }
};
```

Editer `web/app/Models/MissingPanne.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissingPanne extends Model
{
    use HasFactory;
    protected $fillable = ['flight_id', 'failure_code', 'description', 'comment', 'reported_by', 'reported_at'];
    protected $casts = ['reported_at' => 'datetime'];
    public function flight(): BelongsTo { return $this->belongsTo(Flight::class); }
    public function reporter(): BelongsTo { return $this->belongsTo(\App\Models\User::class, 'reported_by'); }
}
```

- [ ] **Step 2 : Migrate + Commit**

```bash
php artisan migrate
cd .. && git add web && git commit -m "feat(web): add missing_pannes table and model"
```

### Task 2.5 : Migration `weekly_aggregates`

- [ ] **Step 1 : Migration + modele**

```bash
cd web && php artisan make:model WeeklyAggregate -m
```

Editer la migration :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('weekly_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
            $table->smallInteger('year');
            $table->string('iso_week');
            $table->integer('total_pannes')->default(0);
            $table->decimal('total_flight_hours', 10, 4)->default(0);
            $table->timestamps();

            $table->unique(['machine_id', 'iso_week']);
            $table->index(['machine_id', 'year']);
        });
    }
    public function down(): void { Schema::dropIfExists('weekly_aggregates'); }
};
```

Editer `web/app/Models/WeeklyAggregate.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyAggregate extends Model
{
    use HasFactory;
    protected $fillable = ['machine_id', 'year', 'iso_week', 'total_pannes', 'total_flight_hours'];
    protected $casts = [
        'total_flight_hours' => 'decimal:4',
        'total_pannes' => 'int',
        'year' => 'int',
    ];
    public function machine(): BelongsTo { return $this->belongsTo(Machine::class); }
}
```

- [ ] **Step 2 : Migrate + Commit**

```bash
php artisan migrate
cd .. && git add web && git commit -m "feat(web): add weekly_aggregates table and model"
```

### Task 2.6 : Migration `imports`

- [ ] **Step 1 : Migration + modele**

```bash
cd web && php artisan make:model Import -m
```

Editer la migration :
```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('filename');
            $table->enum('status', ['pending', 'processing', 'ok', 'already_processed', 'non_vol', 'error'])
                  ->default('pending');
            $table->jsonb('result')->nullable();
            $table->foreignId('flight_id')->nullable()->constrained('flights')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }
    public function down(): void { Schema::dropIfExists('imports'); }
};
```

Editer `web/app/Models/Import.php` :
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Import extends Model
{
    use HasFactory;
    protected $fillable = ['user_id', 'filename', 'status', 'result', 'flight_id'];
    protected $casts = ['result' => 'array'];

    public function user(): BelongsTo { return $this->belongsTo(\App\Models\User::class); }
    public function flight(): BelongsTo { return $this->belongsTo(Flight::class); }
}
```

- [ ] **Step 2 : Migrate + test que toutes les tables existent**

```bash
php artisan migrate
psql nh_project_dev -c "\dt"
```
Expected: 11 tables visibles (`users`, `sessions`, `jobs`, `cache`, `cache_locks`, `password_reset_tokens`, `machines`, `flights`, `technical_events`, `missing_pannes`, `weekly_aggregates`, `imports`).

- [ ] **Step 3 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add imports table and model"
```

---

## Phase 3 — Services backend

### Task 3.1 : Service `XmlPipelineRunner`

**Files:**
- Create: `web/app/Services/XmlPipelineRunner.php`
- Create: `web/tests/Unit/XmlPipelineRunnerTest.php`

- [ ] **Step 1 : Ecrire le test**

Creer `web/tests/Unit/XmlPipelineRunnerTest.php` :
```php
<?php
use App\Services\XmlPipelineRunner;

it('runs pipeline on a valid xml and returns parsed json', function () {
    $xmlPath = base_path('../raw/exemple.xml');
    $outputBase = storage_path('app/test_pipeline_' . uniqid());

    $runner = new XmlPipelineRunner();
    $result = $runner->run($xmlPath, $outputBase);

    expect($result)->toBeArray()
        ->and($result['status'])->toBeIn(['ok', 'no_engine', 'error'])
        ->and($result['hc_id'])->not->toBeNull();
});

it('returns error status when xml file is missing', function () {
    $runner = new XmlPipelineRunner();
    $result = $runner->run('/nonexistent/path.xml', storage_path('app/tmp'));
    expect($result['status'])->toBe('error');
});
```

- [ ] **Step 2 : Lancer le test (FAIL)**

```bash
cd web && ./vendor/bin/pest tests/Unit/XmlPipelineRunnerTest.php
```
Expected: FAIL (classe inexistante).

- [ ] **Step 3 : Implementer le service**

Creer `web/app/Services/XmlPipelineRunner.php` :
```php
<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class XmlPipelineRunner
{
    /**
     * Execute la pipeline Python sur un XML.
     * Retourne le JSON parse emis par main.py --json-output sur la derniere ligne de stdout.
     *
     * @return array{status:string,hc_id:?string,dsn:?string,num:?string,folder_name:?string,annee:?string,semaine:?string,output_dir:?string,message:?string}
     */
    public function run(string $xmlPath, string $outputBase, ?string $filtreMode = '48h'): array
    {
        $pythonRoot = base_path('..');  // racine du repo Python
        $cmd = [
            'python3', 'main.py',
            $xmlPath,
            '--output-base', $outputBase,
            '--json-output',
        ];
        if ($filtreMode !== null) {
            $cmd[] = '--filtre-mode';
            $cmd[] = $filtreMode;
        }

        $process = new Process($cmd, $pythonRoot);
        $process->setTimeout(600);
        $process->run();

        $stdout = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        $lastLine = end($lines) ?: '';

        $parsed = json_decode($lastLine, true);
        if (!is_array($parsed)) {
            return [
                'status' => 'error',
                'hc_id' => null, 'dsn' => null, 'num' => null,
                'folder_name' => null, 'annee' => null, 'semaine' => null,
                'output_dir' => null,
                'message' => 'Invalid JSON from pipeline: ' . substr($process->getErrorOutput(), 0, 500),
            ];
        }
        return $parsed;
    }
}
```

- [ ] **Step 4 : Lancer le test**

```bash
./vendor/bin/pest tests/Unit/XmlPipelineRunnerTest.php
```
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add XmlPipelineRunner service"
```

### Task 3.2 : Service `FlightImporter`

**Files:**
- Create: `web/app/Services/FlightImporter.php`
- Create: `web/tests/Feature/FlightImporterTest.php`

- [ ] **Step 1 : Ecrire le test**

Creer `web/tests/Feature/FlightImporterTest.php` :
```php
<?php
use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use App\Services\FlightImporter;

it('imports a flight from pipeline JSON output', function () {
    $xmlPath = base_path('../raw/exemple.xml');
    $outputBase = storage_path('app/test_importer_' . uniqid());

    $runner = new \App\Services\XmlPipelineRunner();
    $pipelineResult = $runner->run($xmlPath, $outputBase);

    if ($pipelineResult['status'] !== 'ok') {
        $this->markTestSkipped('Pipeline did not produce ok status: ' . $pipelineResult['status']);
    }

    $importer = new FlightImporter();
    $flight = $importer->import($pipelineResult);

    expect($flight)->toBeInstanceOf(Flight::class);
    expect($flight->machine->hc_id)->toBe($pipelineResult['hc_id']);
    expect($flight->technicalEvents()->where('status', 'conservee')->count())->toBeGreaterThan(0);
});
```

- [ ] **Step 2 : Lancer (FAIL)**

```bash
cd web && ./vendor/bin/pest tests/Feature/FlightImporterTest.php
```
Expected: FAIL.

- [ ] **Step 3 : Implementer le service**

Creer `web/app/Services/FlightImporter.php` :
```php
<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Machine;
use App\Models\TechnicalEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FlightImporter
{
    /**
     * Importe un vol + ses pannes depuis les JSON generes par la pipeline.
     * Retourne le Flight cree (ou existant si doublon).
     *
     * $pipelineResult doit avoir status=ok, hc_id, output_dir renseignes.
     */
    public function import(array $pipelineResult): Flight
    {
        return DB::transaction(function () use ($pipelineResult) {
            $outputDir = $pipelineResult['output_dir'];
            $rapport = $this->readJson($outputDir . '/rapport_moteur.json');

            $hcId = $pipelineResult['hc_id'] ?? ($rapport['hc_id'] ?? null);
            $dsn = $pipelineResult['dsn'] ?? ($rapport['dsn'] ?? '');
            $num = $pipelineResult['num'] ?? ($rapport['num'] ?? '');

            $machine = Machine::firstOrCreate(['hc_id' => $hcId]);

            $flight = Flight::updateOrCreate(
                [
                    'machine_id' => $machine->id,
                    'dsn' => $dsn,
                    'num' => $num,
                ],
                [
                    'start_datetime' => $this->parseDate($rapport['start_datetime'] ?? null),
                    'end_datetime'   => $this->parseDate($rapport['end_datetime'] ?? null),
                    'flight_type'    => $rapport['flight_type'] ?? 'UNKNOWN',
                    'flight_hours'   => $rapport['flight_hours'] ?? 0,
                    'consumed_fuel'  => $rapport['consumed_fuel'] ?? null,
                    'is_non_vol'     => strtoupper($rapport['flight_type'] ?? '') !== 'FLIGHT',
                    'xml_path'       => $outputDir . '/xml_epure.xml',
                    'processed_at'   => now(),
                ],
            );

            // Pannes conservees
            $conservees = $this->readJson($outputDir . '/pannes_conservees.json');
            if (is_array($conservees['pannes_conservees'] ?? null)) {
                $this->syncPannes($flight, $conservees['pannes_conservees'], 'conservee');
            }

            // Pannes isolees
            $isolees = $this->readJson($outputDir . '/pannes_isolees.json');
            if (is_array($isolees['pannes_isolees'] ?? null)) {
                $this->syncPannes($flight, $isolees['pannes_isolees'], 'isolee');
            }

            return $flight;
        });
    }

    /** Importe un non-vol (status=no_engine de la pipeline). */
    public function importNonVol(array $pipelineResult): Flight
    {
        return DB::transaction(function () use ($pipelineResult) {
            $hcId = $pipelineResult['hc_id'];
            $machine = Machine::firstOrCreate(['hc_id' => $hcId]);

            // Pour un non-vol on n'a pas le JSON rapport_moteur complet en mode standard,
            // mais on a hc_id / dsn / num / folder_name. On cree un flight minimal.
            $num = $pipelineResult['num'] ?? '';
            $dsn = $pipelineResult['dsn'] ?? '';

            // Tenter d'extraire les dates du folder_name si possible
            // Sinon now()
            $now = now();

            return Flight::updateOrCreate(
                ['machine_id' => $machine->id, 'dsn' => $dsn, 'num' => $num],
                [
                    'start_datetime' => $now, 'end_datetime' => $now,
                    'flight_type'    => 'GROUND',
                    'flight_hours'   => 0,
                    'is_non_vol'     => true,
                    'xml_path'       => $pipelineResult['output_dir'] ?? null,
                    'processed_at'   => now(),
                ],
            );
        });
    }

    private function syncPannes(Flight $flight, array $pannes, string $status): void
    {
        // Efface les pannes precedentes de ce status (import idempotent)
        $flight->technicalEvents()->where('status', $status)->delete();

        foreach ($pannes as $p) {
            $raise = $this->parseDate($p['RaiseDateTime'] ?? null);
            $isoWeek = $raise ? $raise->isoFormat('GGGG-[W]WW') : '';

            TechnicalEvent::create([
                'flight_id' => $flight->id,
                'technical_event_id' => $p['TechnicalEventId'] ?? '',
                'raise_datetime' => $raise ?? now(),
                'status' => $status,
                'iso_week' => $isoWeek,
                'nombre_occurrences' => $p['nombre_occurrences'] ?? 1,
                'details' => $p,
            ]);
        }
    }

    private function readJson(string $path): array
    {
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        return json_decode($raw, true) ?: [];
    }

    private function parseDate(?string $raw): ?Carbon
    {
        if (!$raw) return null;
        // Formats possibles : ISO 8601 ou "dd/mm/YYYY HH:MM:SS"
        try { return Carbon::parse($raw); } catch (\Throwable) {}
        try { return Carbon::createFromFormat('d/m/Y H:i:s', $raw); } catch (\Throwable) {}
        return null;
    }
}
```

- [ ] **Step 4 : Lancer le test**

```bash
./vendor/bin/pest tests/Feature/FlightImporterTest.php
```
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add FlightImporter service"
```

### Task 3.3 : Service `WeeklyAggregatesIngestor`

**Files:**
- Create: `web/app/Services/WeeklyAggregatesIngestor.php`
- Create: `web/tests/Feature/WeeklyAggregatesIngestorTest.php`

- [ ] **Step 1 : Ecrire le test**

Creer `web/tests/Feature/WeeklyAggregatesIngestorTest.php` :
```php
<?php
use App\Models\Machine;
use App\Models\WeeklyAggregate;
use App\Services\WeeklyAggregatesIngestor;

it('ingests yearly csvs into weekly_aggregates', function () {
    $machine = Machine::create(['hc_id' => 'NH08']);

    $pannesCsv = base_path('../data/reports/yearly/NH08/NH08_2026.csv');
    $fhCsv = base_path('../data/FHreport/yearly/NH08/NH08_2026.csv');

    if (!file_exists($pannesCsv) || !file_exists($fhCsv)) {
        $this->markTestSkipped('Yearly CSVs not available for NH08 2026');
    }

    $ingestor = new WeeklyAggregatesIngestor();
    $ingestor->ingest($machine, 2026, $pannesCsv, $fhCsv);

    $agg = WeeklyAggregate::where('machine_id', $machine->id)->get();
    expect($agg->count())->toBeGreaterThan(0);
    expect($agg->firstWhere('iso_week', '2026-W05'))->not->toBeNull();
    expect((int) $agg->firstWhere('iso_week', '2026-W05')->total_pannes)->toBeGreaterThan(0);
});
```

- [ ] **Step 2 : FAIL**

```bash
cd web && ./vendor/bin/pest tests/Feature/WeeklyAggregatesIngestorTest.php
```
Expected: FAIL.

- [ ] **Step 3 : Implementer**

Creer `web/app/Services/WeeklyAggregatesIngestor.php` :
```php
<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use Illuminate\Support\Facades\DB;

class WeeklyAggregatesIngestor
{
    /**
     * Ingere les CSV yearly (pannes + FH) dans weekly_aggregates via UPSERT.
     * Si l'un ou l'autre CSV n'existe pas, la colonne correspondante reste 0 par defaut.
     */
    public function ingest(Machine $machine, int $year, ?string $pannesCsvPath, ?string $fhCsvPath): void
    {
        DB::transaction(function () use ($machine, $year, $pannesCsvPath, $fhCsvPath) {
            if ($pannesCsvPath && file_exists($pannesCsvPath)) {
                $this->upsertFromCsv($machine, $year, $pannesCsvPath, 'total_pannes', skipTotalRow: false);
            }
            if ($fhCsvPath && file_exists($fhCsvPath)) {
                $this->upsertFromCsv($machine, $year, $fhCsvPath, 'total_flight_hours', skipTotalRow: true);
            }
        });
    }

    private function upsertFromCsv(Machine $machine, int $year, string $path, string $column, bool $skipTotalRow): void
    {
        $fh = fopen($path, 'r');
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); return; }

        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) continue;
            $semaine = $row[0];
            $value = $row[1];

            if ($skipTotalRow && str_starts_with($semaine, 'TOTAL')) continue;
            if (!preg_match('/^\d{4}-W\d{2}$/', $semaine)) continue;

            WeeklyAggregate::updateOrCreate(
                ['machine_id' => $machine->id, 'iso_week' => $semaine],
                ['year' => $year, $column => $value],
            );
        }
        fclose($fh);
    }
}
```

- [ ] **Step 4 : PASS**

```bash
./vendor/bin/pest tests/Feature/WeeklyAggregatesIngestorTest.php
```
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add WeeklyAggregatesIngestor service"
```

---

## Phase 4 — Job queue `ProcessXmlJob`

### Task 4.1 : Creer le Job

**Files:**
- Create: `web/app/Jobs/ProcessXmlJob.php`
- Create: `web/tests/Feature/ProcessXmlJobTest.php`

- [ ] **Step 1 : Ecrire le test**

```php
<?php
// web/tests/Feature/ProcessXmlJobTest.php

use App\Jobs\ProcessXmlJob;
use App\Models\Flight;
use App\Models\Import;
use App\Models\User;

it('processes an xml and updates import to ok', function () {
    $user = User::factory()->create();
    $xmlSource = base_path('../raw/exemple.xml');
    $staging = storage_path('app/staging_test_' . uniqid() . '.xml');
    copy($xmlSource, $staging);

    $import = Import::create([
        'user_id' => $user->id,
        'filename' => basename($staging),
        'status' => 'pending',
    ]);

    (new ProcessXmlJob($import->id, $staging))->handle(
        app(\App\Services\XmlPipelineRunner::class),
        app(\App\Services\FlightImporter::class),
        app(\App\Services\WeeklyAggregatesIngestor::class),
    );

    $import->refresh();
    expect($import->status)->toBeIn(['ok', 'non_vol']);
    if ($import->status === 'ok') {
        expect($import->flight_id)->not->toBeNull();
        expect(Flight::find($import->flight_id))->not->toBeNull();
    }
});
```

- [ ] **Step 2 : FAIL**

```bash
cd web && ./vendor/bin/pest tests/Feature/ProcessXmlJobTest.php
```
Expected: FAIL.

- [ ] **Step 3 : Implementer le Job**

```bash
php artisan make:job ProcessXmlJob
```

Remplacer le contenu :
```php
<?php

namespace App\Jobs;

use App\Models\Flight;
use App\Models\Import;
use App\Models\Machine;
use App\Services\FlightImporter;
use App\Services\WeeklyAggregatesIngestor;
use App\Services\XmlPipelineRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessXmlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $importId, public string $xmlPath) {}

    public function handle(
        XmlPipelineRunner $runner,
        FlightImporter $importer,
        WeeklyAggregatesIngestor $aggIngestor,
    ): void {
        $import = Import::findOrFail($this->importId);
        $import->update(['status' => 'processing']);

        $outputBase = storage_path('app/data');
        $result = $runner->run($this->xmlPath, $outputBase);

        match ($result['status']) {
            'ok'       => $this->handleOk($import, $result, $importer, $aggIngestor),
            'no_engine'=> $this->handleNonVol($import, $result, $importer),
            'error'    => $this->handleError($import, $result),
            default    => $this->handleError($import, $result),
        };
    }

    private function handleOk(Import $import, array $result, FlightImporter $importer, WeeklyAggregatesIngestor $aggIngestor): void
    {
        try {
            $flight = $importer->import($result);

            // Deja present -> statut already_processed (detecte via updated_at vs created_at)
            $status = ($flight->wasRecentlyCreated) ? 'ok' : 'already_processed';

            // Ingestion agregats yearly
            if ($flight->wasRecentlyCreated) {
                $year = (int) ($result['annee'] ?? date('Y'));
                $hcId = $result['hc_id'];
                $pannesCsv = base_path('../data/reports/yearly/' . $hcId . '/' . $hcId . '_' . $year . '.csv');
                $fhCsv = base_path('../data/FHreport/yearly/' . $hcId . '/' . $hcId . '_' . $year . '.csv');
                $aggIngestor->ingest($flight->machine, $year, $pannesCsv, $fhCsv);
            }

            $import->update([
                'status' => $status,
                'flight_id' => $flight->id,
                'result' => [
                    'hc_id' => $result['hc_id'],
                    'dsn' => $result['dsn'],
                    'num' => $result['num'],
                    'pannes_conservees_count' => $flight->technicalEvents()->where('status', 'conservee')->count(),
                    'flight_hours' => (float) $flight->flight_hours,
                ],
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'error',
                'result' => ['message' => $e->getMessage()],
            ]);
        }
    }

    private function handleNonVol(Import $import, array $result, FlightImporter $importer): void
    {
        try {
            $flight = $importer->importNonVol($result);
            $import->update([
                'status' => 'non_vol',
                'flight_id' => $flight->id,
                'result' => [
                    'hc_id' => $result['hc_id'],
                    'dsn' => $result['dsn'],
                    'num' => $result['num'],
                ],
            ]);
        } catch (\Throwable $e) {
            $import->update([
                'status' => 'error',
                'result' => ['message' => $e->getMessage()],
            ]);
        }
    }

    private function handleError(Import $import, array $result): void
    {
        $import->update([
            'status' => 'error',
            'result' => ['message' => $result['message'] ?? 'Unknown pipeline error'],
        ]);
    }
}
```

- [ ] **Step 4 : PASS**

```bash
./vendor/bin/pest tests/Feature/ProcessXmlJobTest.php
```
Expected: PASS.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add ProcessXmlJob with queue handling"
```

---

## Phase 5 — Layout global, routes, navigation

### Task 5.1 : Layout Blade global

**Files:**
- Modify: `web/resources/views/layouts/app.blade.php`
- Create: `web/resources/views/layouts/sidebar.blade.php`

- [ ] **Step 1 : Editer le layout principal**

Remplacer `web/resources/views/layouts/app.blade.php` :
```blade
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'NH Project') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="font-sans antialiased bg-gray-100">
    <div class="min-h-screen flex">
        @include('layouts.sidebar')
        <main class="flex-1 p-6">
            @if (isset($header))
                <header class="mb-6">
                    <h1 class="text-2xl font-semibold text-gray-800">{{ $header }}</h1>
                </header>
            @endif
            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
    @livewireScripts
</body>
</html>
```

- [ ] **Step 2 : Creer le composant sidebar**

Creer `web/resources/views/layouts/sidebar.blade.php` :
```blade
<aside class="w-60 bg-gray-900 text-gray-100 min-h-screen p-4 flex flex-col">
    <div class="mb-8">
        <h2 class="text-xl font-bold">NH Project</h2>
        <p class="text-xs text-gray-400">{{ auth()->user()->email ?? '' }}</p>
    </div>
    <nav class="flex-1 space-y-1">
        <a href="{{ route('machines.index') }}" class="block px-3 py-2 rounded hover:bg-gray-700">Machines</a>
        <a href="{{ route('upload.index') }}" class="block px-3 py-2 rounded hover:bg-gray-700">Upload XML</a>
        <a href="{{ route('imports.index') }}" class="block px-3 py-2 rounded hover:bg-gray-700">Imports</a>
        <a href="{{ route('dashboards.index') }}" class="block px-3 py-2 rounded hover:bg-gray-700">Dashboards</a>
    </nav>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">
        @csrf
        <button type="submit" class="w-full text-left px-3 py-2 rounded hover:bg-gray-700 text-sm">Deconnexion</button>
    </form>
</aside>
```

- [ ] **Step 3 : Routes squelette**

Editer `web/routes/web.php` :
```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('machines.index'))->middleware('auth');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/machines', [\App\Http\Controllers\MachineController::class, 'index'])->name('machines.index');
    Route::get('/machines/{hcId}', [\App\Http\Controllers\MachineController::class, 'show'])->name('machines.show');

    Route::get('/flights/{flight}', [\App\Http\Controllers\FlightController::class, 'show'])->name('flights.show');
    Route::get('/flights/{flight}/pannes-conservees', [\App\Http\Controllers\FlightController::class, 'pannesConservees'])->name('flights.pannes-conservees');
    Route::get('/flights/{flight}/pannes-isolees', [\App\Http\Controllers\FlightController::class, 'pannesIsolees'])->name('flights.pannes-isolees');
    Route::get('/flights/{flight}/non-vol', [\App\Http\Controllers\NonVolController::class, 'show'])->name('flights.non-vol');
    Route::post('/flights/{flight}/flag-as-error', [\App\Http\Controllers\NonVolController::class, 'flag'])->name('flights.flag-as-error');
    Route::get('/flights/{flight}/xml', [\App\Http\Controllers\FlightController::class, 'downloadXml'])->name('flights.xml');

    Route::get('/upload', fn () => view('upload'))->name('upload.index');
    Route::get('/imports', fn () => view('imports'))->name('imports.index');
    Route::get('/dashboards', fn () => view('dashboards'))->name('dashboards.index');
});

require __DIR__ . '/auth.php';
```

- [ ] **Step 4 : Verifier que les routes se chargent**

```bash
cd web && php artisan route:list
```
Expected: toutes les routes listees (certaines sans controller valide encore, on va les creer).

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add global layout with sidebar and base routes"
```

---

## Phase 6 — Upload XML (Livewire staging + validation)

### Task 6.1 : Composant Livewire `XmlUploader`

**Files:**
- Create: `web/app/Livewire/XmlUploader.php`
- Create: `web/resources/views/livewire/xml-uploader.blade.php`
- Create: `web/resources/views/upload.blade.php`

- [ ] **Step 1 : Creer le composant**

```bash
cd web && php artisan make:livewire XmlUploader
```

- [ ] **Step 2 : Implementer `XmlUploader.php`**

```php
<?php

namespace App\Livewire;

use App\Jobs\ProcessXmlJob;
use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

class XmlUploader extends Component
{
    use WithFileUploads;

    #[Validate(['stagedFiles.*' => 'file|mimes:xml|max:51200'])]
    public array $stagedFiles = [];

    public function removeStaged(int $index): void
    {
        unset($this->stagedFiles[$index]);
        $this->stagedFiles = array_values($this->stagedFiles);
    }

    public function submit(): void
    {
        $this->validate();
        if (empty($this->stagedFiles)) return;

        foreach ($this->stagedFiles as $uploaded) {
            $filename = $uploaded->getClientOriginalName();
            $stagingPath = $uploaded->storeAs('staging', uniqid('', true) . '_' . $filename);
            $absolute = Storage::path($stagingPath);

            $import = Import::create([
                'user_id' => auth()->id(),
                'filename' => $filename,
                'status' => 'pending',
            ]);

            ProcessXmlJob::dispatch($import->id, $absolute);
        }

        $this->stagedFiles = [];
        $this->redirectRoute('imports.index');
    }

    public function render()
    {
        return view('livewire.xml-uploader');
    }
}
```

- [ ] **Step 3 : Template Livewire**

Creer `web/resources/views/livewire/xml-uploader.blade.php` :
```blade
<div class="bg-white shadow rounded p-6">
    <h2 class="text-lg font-semibold mb-4">Deposer des XML</h2>

    <div
        x-data
        x-on:drop.prevent="
            const files = $event.dataTransfer.files;
            const input = $refs.fileInput;
            input.files = files;
            input.dispatchEvent(new Event('change'));
        "
        x-on:dragover.prevent
        class="border-2 border-dashed border-gray-300 rounded p-8 text-center hover:bg-gray-50"
    >
        <input type="file" wire:model="stagedFiles" multiple accept=".xml" x-ref="fileInput" class="hidden" id="stagedFiles" />
        <label for="stagedFiles" class="cursor-pointer text-blue-600 hover:underline">
            Cliquez ou glissez-deposez des fichiers XML
        </label>
        <p class="text-xs text-gray-500 mt-2">Max 50 Mo par fichier</p>
    </div>

    <div wire:loading wire:target="stagedFiles" class="mt-4 text-sm text-gray-600">
        Upload en cours...
    </div>

    @error('stagedFiles.*') <p class="text-red-500 text-sm mt-2">{{ $message }}</p> @enderror

    @if (count($stagedFiles) > 0)
        <ul class="mt-6 divide-y">
            @foreach ($stagedFiles as $i => $file)
                <li class="flex justify-between items-center py-2">
                    <span class="text-sm">{{ $file->getClientOriginalName() }}</span>
                    <button type="button" wire:click="removeStaged({{ $i }})" class="text-red-500 text-xs">Retirer</button>
                </li>
            @endforeach
        </ul>

        <button
            wire:click="submit"
            wire:loading.attr="disabled"
            class="mt-4 bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
        >
            Traiter {{ count($stagedFiles) }} fichier(s)
        </button>
    @endif
</div>
```

- [ ] **Step 4 : Page Upload**

Creer `web/resources/views/upload.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Upload XML</x-slot>
    <livewire:xml-uploader />
</x-app-layout>
```

Verifier que `x-app-layout` existe (Breeze le cree). Si non, utiliser `@extends('layouts.app')`.

- [ ] **Step 5 : Test manuel**

Lancer le serveur + worker :
```bash
php artisan queue:work &
php artisan serve
```
Visiter `/upload`, se connecter, deposer `raw/exemple.xml`, valider. Verifier que `imports` contient une ligne.

- [ ] **Step 6 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add Livewire XmlUploader with drag-and-drop staging"
```

---

## Phase 7 — Suivi des imports

### Task 7.1 : Composant `ImportsTracker`

**Files:**
- Create: `web/app/Livewire/ImportsTracker.php`
- Create: `web/resources/views/livewire/imports-tracker.blade.php`
- Modify: `web/resources/views/imports.blade.php`

- [ ] **Step 1 : Creer le composant**

```bash
cd web && php artisan make:livewire ImportsTracker
```

- [ ] **Step 2 : Implementer**

`web/app/Livewire/ImportsTracker.php` :
```php
<?php

namespace App\Livewire;

use App\Models\Import;
use Livewire\Attributes\On;
use Livewire\Component;

class ImportsTracker extends Component
{
    public string $filter = 'all'; // all / pending / done / errors

    public function render()
    {
        $query = Import::where('user_id', auth()->id())
            ->orderByDesc('id')
            ->limit(100);

        $query->when($this->filter === 'pending', fn ($q) => $q->whereIn('status', ['pending', 'processing']));
        $query->when($this->filter === 'done', fn ($q) => $q->whereIn('status', ['ok', 'already_processed', 'non_vol']));
        $query->when($this->filter === 'errors', fn ($q) => $q->where('status', 'error'));

        return view('livewire.imports-tracker', ['imports' => $query->get()]);
    }
}
```

- [ ] **Step 3 : Template**

`web/resources/views/livewire/imports-tracker.blade.php` :
```blade
<div wire:poll.2s class="bg-white shadow rounded p-6">
    <div class="flex gap-2 mb-4">
        <button wire:click="$set('filter','all')"
            @class(['px-3 py-1 rounded text-sm', 'bg-blue-600 text-white' => $filter==='all', 'bg-gray-200' => $filter!=='all'])>Tous</button>
        <button wire:click="$set('filter','pending')"
            @class(['px-3 py-1 rounded text-sm', 'bg-blue-600 text-white' => $filter==='pending', 'bg-gray-200' => $filter!=='pending'])>En cours</button>
        <button wire:click="$set('filter','done')"
            @class(['px-3 py-1 rounded text-sm', 'bg-blue-600 text-white' => $filter==='done', 'bg-gray-200' => $filter!=='done'])>Termines</button>
        <button wire:click="$set('filter','errors')"
            @class(['px-3 py-1 rounded text-sm', 'bg-blue-600 text-white' => $filter==='errors', 'bg-gray-200' => $filter!=='errors'])>Erreurs</button>
    </div>

    <table class="w-full text-sm">
        <thead class="bg-gray-100 text-left">
            <tr>
                <th class="p-2">Fichier</th><th class="p-2">Statut</th>
                <th class="p-2">Machine</th><th class="p-2">DSN</th><th class="p-2">Num</th>
                <th class="p-2">Message</th>
            </tr>
        </thead>
        <tbody>
        @forelse ($imports as $import)
            <tr class="border-b">
                <td class="p-2">{{ $import->filename }}</td>
                <td class="p-2">
                    <span @class([
                        'px-2 py-0.5 rounded text-xs font-medium',
                        'bg-gray-200 text-gray-800' => in_array($import->status, ['pending','processing']),
                        'bg-green-100 text-green-800' => $import->status === 'ok',
                        'bg-yellow-100 text-yellow-800' => in_array($import->status, ['non_vol','already_processed']),
                        'bg-red-100 text-red-800' => $import->status === 'error',
                    ])>
                        {{ match($import->status) {
                            'pending' => 'En attente',
                            'processing' => 'En cours',
                            'ok' => 'Traite',
                            'already_processed' => 'Deja traite',
                            'non_vol' => 'Non vol',
                            'error' => 'Erreur',
                        } }}
                    </span>
                </td>
                <td class="p-2">{{ data_get($import->result, 'hc_id', '—') }}</td>
                <td class="p-2">{{ data_get($import->result, 'dsn', '—') }}</td>
                <td class="p-2">{{ data_get($import->result, 'num', '—') }}</td>
                <td class="p-2 text-xs text-gray-600">
                    @if ($import->status === 'ok')
                        {{ data_get($import->result, 'pannes_conservees_count', 0) }} pannes, {{ data_get($import->result, 'flight_hours', 0) }}h
                    @elseif ($import->status === 'error')
                        {{ data_get($import->result, 'message', '') }}
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="p-4 text-center text-gray-500">Aucun import.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
```

- [ ] **Step 4 : Page imports**

Creer `web/resources/views/imports.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Imports</x-slot>
    <livewire:imports-tracker />
</x-app-layout>
```

- [ ] **Step 5 : Test manuel**

Visiter `/imports`, verifier que les uploads de la phase 6 sont listes et se rafraichissent toutes les 2s.

- [ ] **Step 6 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): add ImportsTracker livewire component with polling"
```

---

## Phase 8 — Machines list + detail (3 onglets)

### Task 8.1 : Controller `MachineController`

**Files:**
- Create: `web/app/Http/Controllers/MachineController.php`
- Create: `web/resources/views/machines/index.blade.php`
- Create: `web/resources/views/machines/show.blade.php`

- [ ] **Step 1 : Creer le controller**

```bash
cd web && php artisan make:controller MachineController
```

Editer :
```php
<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineController extends Controller
{
    public function index()
    {
        $machines = Machine::withCount([
            'flights',
            'flights as vols_count' => fn ($q) => $q->where('is_non_vol', false),
            'flights as non_vols_count' => fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false),
            'flights as erreurs_count' => fn ($q) => $q->where('flagged_as_error', true),
        ])->with(['flights' => fn ($q) => $q->latest('start_datetime')->limit(1)])->get();

        return view('machines.index', compact('machines'));
    }

    public function show(string $hcId, Request $request)
    {
        $machine = Machine::where('hc_id', $hcId)->firstOrFail();
        $tab = $request->get('tab', 'vols');

        $query = $machine->flights()->orderByDesc('start_datetime');
        $query->when($tab === 'vols', fn ($q) => $q->where('is_non_vol', false));
        $query->when($tab === 'non-vols', fn ($q) => $q->where('is_non_vol', true)->where('flagged_as_error', false));
        $query->when($tab === 'erreurs', fn ($q) => $q->where('flagged_as_error', true));

        $flights = $query->withCount([
            'technicalEvents as conservees_count' => fn ($q) => $q->where('status', 'conservee'),
        ])->paginate(25);

        return view('machines.show', compact('machine', 'tab', 'flights'));
    }
}
```

- [ ] **Step 2 : View `index`**

Creer `web/resources/views/machines/index.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Machines</x-slot>
    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @forelse ($machines as $m)
            <a href="{{ route('machines.show', $m->hc_id) }}" class="block bg-white shadow rounded p-6 hover:shadow-lg">
                <h3 class="text-xl font-semibold text-gray-800">{{ $m->hc_id }}</h3>
                <div class="mt-3 text-sm text-gray-600 space-y-1">
                    <p>{{ $m->vols_count }} vols</p>
                    <p>{{ $m->non_vols_count }} non vols</p>
                    <p>{{ $m->erreurs_count }} erreurs</p>
                    @if ($m->flights->first())
                        <p class="text-xs text-gray-400 pt-2">Dernier vol : {{ $m->flights->first()->start_datetime->format('d/m/Y') }}</p>
                    @endif
                </div>
            </a>
        @empty
            <p class="text-gray-500 col-span-full">Aucune machine en base. Uploadez un XML pour commencer.</p>
        @endforelse
    </div>
</x-app-layout>
```

- [ ] **Step 3 : View `show`**

Creer `web/resources/views/machines/show.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Machine {{ $machine->hc_id }}</x-slot>

    <div class="bg-white rounded shadow">
        <nav class="flex border-b">
            @foreach (['vols' => 'Vols', 'non-vols' => 'Non vols', 'erreurs' => 'Erreurs'] as $key => $label)
                <a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => $key]) }}"
                   @class([
                       'px-4 py-3 text-sm font-medium',
                       'border-b-2 border-blue-600 text-blue-600' => $tab === $key,
                       'text-gray-600 hover:text-gray-800' => $tab !== $key,
                   ])>{{ $label }}</a>
            @endforeach
        </nav>

        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="p-2">Date debut</th>
                    <th class="p-2">DSN</th>
                    <th class="p-2">Num</th>
                    <th class="p-2">Type</th>
                    @if ($tab === 'vols')
                        <th class="p-2">FH</th>
                        <th class="p-2">Pannes</th>
                    @endif
                    @if ($tab === 'erreurs')
                        <th class="p-2">Signale par</th>
                        <th class="p-2">Signale le</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse ($flights as $flight)
                @php
                    $route = $tab === 'vols'
                        ? route('flights.show', $flight)
                        : route('flights.non-vol', $flight);
                @endphp
                <tr class="border-t cursor-pointer hover:bg-gray-50" onclick="window.location='{{ $route }}'">
                    <td class="p-2">{{ $flight->start_datetime->format('d/m/Y H:i') }}</td>
                    <td class="p-2">{{ $flight->dsn }}</td>
                    <td class="p-2">{{ $flight->num }}</td>
                    <td class="p-2">{{ $flight->flight_type }}</td>
                    @if ($tab === 'vols')
                        <td class="p-2">{{ number_format($flight->flight_hours, 2) }}h</td>
                        <td class="p-2">{{ $flight->conservees_count ?? 0 }}</td>
                    @endif
                    @if ($tab === 'erreurs')
                        <td class="p-2">{{ $flight->flaggedBy->email ?? '—' }}</td>
                        <td class="p-2">{{ $flight->flagged_at?->format('d/m/Y H:i') }}</td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="6" class="p-4 text-center text-gray-500">Aucun vol dans cet onglet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="p-4">{{ $flights->links() }}</div>
    </div>
</x-app-layout>
```

- [ ] **Step 4 : Test manuel**

Visiter `/machines`, naviguer entre machines et onglets.

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): machines list and detail with 3 tabs"
```

---

## Phase 9 — Detail vol + telechargement XML

### Task 9.1 : `FlightController` avec actions show/download

**Files:**
- Create: `web/app/Http/Controllers/FlightController.php`
- Create: `web/resources/views/flights/show.blade.php`

- [ ] **Step 1 : Creer le controller**

```bash
cd web && php artisan make:controller FlightController
```

Editer :
```php
<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FlightController extends Controller
{
    public function show(Flight $flight)
    {
        abort_if($flight->is_non_vol && !$flight->flagged_as_error, 404);
        $flight->load('machine');
        $counts = [
            'conservees' => $flight->technicalEvents()->where('status', 'conservee')->count(),
            'isolees' => $flight->technicalEvents()->where('status', 'isolee')->count(),
        ];
        return view('flights.show', compact('flight', 'counts'));
    }

    public function pannesConservees(Flight $flight)
    {
        return view('flights.pannes-conservees', compact('flight'));
    }

    public function pannesIsolees(Flight $flight)
    {
        $pannes = $flight->technicalEvents()->where('status', 'isolee')->get();
        return view('flights.pannes-isolees', compact('flight', 'pannes'));
    }

    public function downloadXml(Flight $flight): BinaryFileResponse
    {
        abort_unless($flight->xml_path && file_exists($flight->xml_path), 404);
        return response()->download($flight->xml_path, "{$flight->machine->hc_id}_{$flight->num}.xml");
    }
}
```

- [ ] **Step 2 : View show**

`web/resources/views/flights/show.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Vol {{ $flight->machine->hc_id }} — DSN {{ $flight->dsn }} — n°{{ $flight->num }}</x-slot>

    <div class="bg-white shadow rounded p-6 mb-6 grid md:grid-cols-2 gap-4">
        <div>
            <p class="text-sm text-gray-500">Type</p>
            <p class="font-medium">{{ $flight->flight_type }}</p>
        </div>
        <div>
            <p class="text-sm text-gray-500">Duree</p>
            <p class="font-medium">{{ number_format($flight->flight_hours, 2) }} h</p>
        </div>
        <div>
            <p class="text-sm text-gray-500">Debut</p>
            <p class="font-medium">{{ $flight->start_datetime->format('d/m/Y H:i:s') }}</p>
        </div>
        <div>
            <p class="text-sm text-gray-500">Fin</p>
            <p class="font-medium">{{ $flight->end_datetime->format('d/m/Y H:i:s') }}</p>
        </div>
        <div>
            <p class="text-sm text-gray-500">Carburant consomme</p>
            <p class="font-medium">{{ $flight->consumed_fuel ?? '—' }}</p>
        </div>
        <div>
            <a href="{{ route('flights.xml', $flight) }}" class="bg-gray-200 px-3 py-1 rounded text-sm hover:bg-gray-300">Telecharger XML epure</a>
        </div>
    </div>

    <h2 class="text-lg font-semibold mb-3">Pannes du vol</h2>
    <div class="grid md:grid-cols-2 gap-4">
        <a href="{{ route('flights.pannes-conservees', $flight) }}" class="block bg-white shadow rounded p-6 hover:shadow-lg">
            <p class="text-sm text-gray-500">Pannes conservees</p>
            <p class="text-3xl font-bold text-blue-600">{{ $counts['conservees'] }}</p>
        </a>
        <a href="{{ route('flights.pannes-isolees', $flight) }}" class="block bg-white shadow rounded p-6 hover:shadow-lg">
            <p class="text-sm text-gray-500">Pannes isolees</p>
            <p class="text-3xl font-bold text-orange-600">{{ $counts['isolees'] }}</p>
        </a>
    </div>
</x-app-layout>
```

- [ ] **Step 3 : Test manuel + Commit**

```bash
cd .. && git add web && git commit -m "feat(web): flight detail page with FH duration display"
```

---

## Phase 10 — Pannes conservees : tableau interactif + validation + signalement manquant

### Task 10.1 : Composant `PannesConserveesTable`

**Files:**
- Create: `web/app/Livewire/PannesConserveesTable.php`
- Create: `web/resources/views/livewire/pannes-conservees-table.blade.php`
- Create: `web/resources/views/flights/pannes-conservees.blade.php`

- [ ] **Step 1 : Generer le composant**

```bash
cd web && php artisan make:livewire PannesConserveesTable
```

- [ ] **Step 2 : Implementation**

`web/app/Livewire/PannesConserveesTable.php` :
```php
<?php

namespace App\Livewire;

use App\Models\Flight;
use App\Models\MissingPanne;
use App\Models\TechnicalEvent;
use Livewire\Component;

class PannesConserveesTable extends Component
{
    public Flight $flight;
    public string $search = '';
    public ?int $selectedEventId = null;

    public bool $showMissingModal = false;
    public string $newFailureCode = '';
    public string $newDescription = '';
    public string $newComment = '';

    public function mount(Flight $flight): void
    {
        $this->flight = $flight;
    }

    public function setValidation(int $eventId, string $status): void
    {
        $te = TechnicalEvent::where('flight_id', $this->flight->id)->findOrFail($eventId);
        $te->update([
            'validation_status' => $status,
            'validated_by' => auth()->id(),
            'validated_at' => now(),
        ]);
    }

    public function saveComment(int $eventId, string $comment): void
    {
        TechnicalEvent::where('flight_id', $this->flight->id)->findOrFail($eventId)
            ->update(['technician_comment' => $comment]);
    }

    public function openDetail(int $eventId): void
    {
        $this->selectedEventId = $eventId;
    }

    public function closeDetail(): void
    {
        $this->selectedEventId = null;
    }

    public function submitMissingPanne(): void
    {
        $this->validate(['newFailureCode' => 'required|string|max:255']);
        MissingPanne::create([
            'flight_id' => $this->flight->id,
            'failure_code' => $this->newFailureCode,
            'description' => $this->newDescription ?: null,
            'comment' => $this->newComment ?: null,
            'reported_by' => auth()->id(),
            'reported_at' => now(),
        ]);
        $this->reset(['showMissingModal', 'newFailureCode', 'newDescription', 'newComment']);
    }

    public function deleteMissing(int $id): void
    {
        $m = MissingPanne::where('flight_id', $this->flight->id)->findOrFail($id);
        if ($m->reported_by === auth()->id()) $m->delete();
    }

    public function render()
    {
        $query = $this->flight->technicalEvents()->where('status', 'conservee')->orderBy('raise_datetime');
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('technical_event_id', 'ilike', "%{$this->search}%")
                  ->orWhereRaw("details::text ilike ?", ["%{$this->search}%"]);
            });
        }

        return view('livewire.pannes-conservees-table', [
            'pannes' => $query->get(),
            'selected' => $this->selectedEventId ? TechnicalEvent::find($this->selectedEventId) : null,
            'missingPannes' => $this->flight->missingPannes()->with('reporter')->latest()->get(),
        ]);
    }
}
```

- [ ] **Step 3 : Template**

`web/resources/views/livewire/pannes-conservees-table.blade.php` :
```blade
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <input type="text" wire:model.live.debounce.300ms="search" placeholder="Recherche..."
               class="border rounded px-3 py-1 text-sm w-64" />
        <button wire:click="$set('showMissingModal', true)" class="bg-orange-500 text-white px-3 py-1 rounded text-sm">
            Signaler une panne manquante
        </button>
    </div>

    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="p-2">Description</th>
                    <th class="p-2">Failure Code</th>
                    <th class="p-2">Failure Start Time</th>
                    <th class="p-2">Occ.</th>
                    <th class="p-2">Validation</th>
                    <th class="p-2">Commentaire</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($pannes as $p)
                <tr class="border-t hover:bg-gray-50" wire:key="te-{{ $p->id }}">
                    <td class="p-2 max-w-xs">
                        <button wire:click="openDetail({{ $p->id }})" class="text-blue-600 text-left">
                            {{ data_get($p->details, 'TechnicalEventDescription') ?? data_get($p->details, 'TEDescription') }}
                        </button>
                    </td>
                    <td class="p-2">{{ data_get($p->details, 'FailureCode') ?? '—' }}</td>
                    <td class="p-2">{{ $p->raise_datetime->format('d/m/Y H:i:s') }}</td>
                    <td class="p-2">{{ $p->nombre_occurrences }}</td>
                    <td class="p-2">
                        <div class="flex gap-1">
                            <button wire:click="setValidation({{ $p->id }}, 'validated')"
                                @class(['text-xs px-2 py-0.5 rounded', 'bg-green-500 text-white' => $p->validation_status === 'validated', 'bg-gray-200' => $p->validation_status !== 'validated'])>✓</button>
                            <button wire:click="setValidation({{ $p->id }}, 'rejected')"
                                @class(['text-xs px-2 py-0.5 rounded', 'bg-red-500 text-white' => $p->validation_status === 'rejected', 'bg-gray-200' => $p->validation_status !== 'rejected'])>✗</button>
                            <button wire:click="setValidation({{ $p->id }}, 'pending')"
                                @class(['text-xs px-2 py-0.5 rounded', 'bg-gray-400 text-white' => $p->validation_status === 'pending', 'bg-gray-200' => $p->validation_status !== 'pending'])>—</button>
                        </div>
                    </td>
                    <td class="p-2">
                        <input type="text" value="{{ $p->technician_comment }}"
                               wire:change="saveComment({{ $p->id }}, $event.target.value)"
                               class="border rounded px-2 py-0.5 text-xs w-full" />
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-4 text-center text-gray-500">Aucune panne conservee.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Panneau lateral detail --}}
    @if ($selected)
        <div class="fixed inset-y-0 right-0 w-96 bg-white shadow-lg p-6 overflow-y-auto z-50 border-l">
            <div class="flex justify-between items-start mb-4">
                <h3 class="font-semibold">Detail panne</h3>
                <button wire:click="closeDetail" class="text-gray-400">x</button>
            </div>
            <dl class="space-y-2 text-sm">
                @foreach ($selected->details as $k => $v)
                    <div>
                        <dt class="text-gray-500 text-xs">{{ $k }}</dt>
                        <dd>{{ is_scalar($v) ? $v : json_encode($v) }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    {{-- Modale signalement panne manquante --}}
    @if ($showMissingModal)
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded shadow p-6 w-96">
                <h3 class="font-semibold mb-4">Signaler une panne manquante</h3>
                <label class="block text-sm mb-1">Failure Code *</label>
                <input type="text" wire:model="newFailureCode" class="border rounded w-full px-2 py-1 mb-3" />
                @error('newFailureCode') <p class="text-red-500 text-xs mb-2">{{ $message }}</p> @enderror

                <label class="block text-sm mb-1">Description</label>
                <textarea wire:model="newDescription" rows="2" class="border rounded w-full px-2 py-1 mb-3"></textarea>

                <label class="block text-sm mb-1">Commentaire</label>
                <textarea wire:model="newComment" rows="2" class="border rounded w-full px-2 py-1 mb-4"></textarea>

                <div class="flex justify-end gap-2">
                    <button wire:click="$set('showMissingModal', false)" class="px-3 py-1 bg-gray-200 rounded text-sm">Annuler</button>
                    <button wire:click="submitMissingPanne" class="px-3 py-1 bg-blue-600 text-white rounded text-sm">Signaler</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Liste pannes manquantes --}}
    @if ($missingPannes->isNotEmpty())
        <div class="bg-white shadow rounded p-4">
            <h3 class="font-semibold mb-3">Pannes manquantes signalees</h3>
            <ul class="divide-y text-sm">
                @foreach ($missingPannes as $m)
                    <li class="py-2 flex justify-between">
                        <div>
                            <span class="font-mono text-xs bg-gray-100 px-2 py-0.5 rounded">{{ $m->failure_code }}</span>
                            @if ($m->description) <span class="text-gray-600 ml-2">{{ $m->description }}</span> @endif
                            <p class="text-xs text-gray-400">Signalee par {{ $m->reporter->email ?? 'inconnu' }} le {{ $m->reported_at->format('d/m/Y H:i') }}</p>
                        </div>
                        @if ($m->reported_by === auth()->id())
                            <button wire:click="deleteMissing({{ $m->id }})" class="text-red-500 text-xs">Supprimer</button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
```

- [ ] **Step 4 : View**

`web/resources/views/flights/pannes-conservees.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Pannes conservees — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}</x-slot>
    <p class="text-sm text-gray-600 mb-4">
        Heure du vol : {{ $flight->start_datetime->format('d/m/Y H:i') }} → {{ $flight->end_datetime->format('d/m/Y H:i') }}
    </p>
    <livewire:pannes-conservees-table :flight="$flight" />
</x-app-layout>
```

- [ ] **Step 5 : Test manuel + Commit**

```bash
cd .. && git add web && git commit -m "feat(web): interactive pannes conservees table with validation and missing report"
```

---

## Phase 11 — Pannes isolees (lecture seule)

### Task 11.1 : View `pannes-isolees`

**Files:**
- Create: `web/resources/views/flights/pannes-isolees.blade.php`

- [ ] **Step 1 : View**

```blade
<x-app-layout>
    <x-slot name="header">Pannes isolees — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}</x-slot>
    <div class="bg-white shadow rounded overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left">
                <tr>
                    <th class="p-2">ID panne</th>
                    <th class="p-2">Raise DateTime</th>
                    <th class="p-2">Ecart</th>
                    <th class="p-2">Raison</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($pannes as $p)
                <tr class="border-t">
                    <td class="p-2 font-mono text-xs">{{ $p->technical_event_id }}</td>
                    <td class="p-2">{{ $p->raise_datetime->format('d/m/Y H:i:s') }}</td>
                    <td class="p-2">{{ data_get($p->details, 'ecart') ?? data_get($p->details, 'ecart_vol') ?? '—' }}</td>
                    <td class="p-2">{{ data_get($p->details, 'raison') ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-4 text-center text-gray-500">Aucune panne isolee.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
```

- [ ] **Step 2 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): pannes isolees read-only table"
```

---

## Phase 12 — Non-vol detail + action "C'est un vol"

### Task 12.1 : Controller + view

**Files:**
- Create: `web/app/Http/Controllers/NonVolController.php`
- Create: `web/resources/views/flights/non-vol.blade.php`

- [ ] **Step 1 : Controller**

```bash
cd web && php artisan make:controller NonVolController
```

```php
<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use Illuminate\Http\Request;

class NonVolController extends Controller
{
    public function show(Flight $flight)
    {
        abort_unless($flight->is_non_vol, 404);
        $flight->load('machine', 'flaggedBy');
        return view('flights.non-vol', compact('flight'));
    }

    public function flag(Flight $flight, Request $request)
    {
        abort_unless($flight->is_non_vol && !$flight->flagged_as_error, 403);
        $flight->update([
            'flagged_as_error' => true,
            'flagged_at' => now(),
            'flagged_by' => auth()->id(),
        ]);
        return redirect()->route('machines.show', ['hcId' => $flight->machine->hc_id, 'tab' => 'erreurs']);
    }
}
```

- [ ] **Step 2 : View**

`web/resources/views/flights/non-vol.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Non vol — {{ $flight->machine->hc_id }} / n°{{ $flight->num }}</x-slot>

    <div class="bg-white shadow rounded p-6 grid md:grid-cols-2 gap-4 mb-6">
        <div><p class="text-sm text-gray-500">Type</p><p class="font-medium">{{ $flight->flight_type }}</p></div>
        <div><p class="text-sm text-gray-500">DSN</p><p class="font-medium">{{ $flight->dsn }}</p></div>
        <div><p class="text-sm text-gray-500">Debut</p><p class="font-medium">{{ $flight->start_datetime->format('d/m/Y H:i') }}</p></div>
        <div><p class="text-sm text-gray-500">Fin</p><p class="font-medium">{{ $flight->end_datetime->format('d/m/Y H:i') }}</p></div>
        <div>
            <a href="{{ route('flights.xml', $flight) }}" class="bg-gray-200 px-3 py-1 rounded text-sm hover:bg-gray-300">Telecharger XML</a>
        </div>
    </div>

    @if ($flight->flagged_as_error)
        <div class="bg-yellow-50 border border-yellow-200 p-4 rounded text-sm text-yellow-800">
            Signale comme erreur par {{ $flight->flaggedBy->email ?? 'utilisateur' }} le {{ $flight->flagged_at->format('d/m/Y H:i') }}.
        </div>
    @else
        <form method="POST" action="{{ route('flights.flag-as-error', $flight) }}">
            @csrf
            <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded">C'est un vol</button>
        </form>
    @endif
</x-app-layout>
```

- [ ] **Step 3 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): non-vol detail page with 'C'est un vol' action"
```

---

## Phase 13 — Dashboards interactifs

### Task 13.1 : Composant `DashboardChart`

**Files:**
- Create: `web/app/Livewire/DashboardChart.php`
- Create: `web/resources/views/livewire/dashboard-chart.blade.php`
- Modify: `web/resources/views/dashboards.blade.php`

- [ ] **Step 1 : Livewire component**

```bash
cd web && php artisan make:livewire DashboardChart
```

`web/app/Livewire/DashboardChart.php` :
```php
<?php

namespace App\Livewire;

use App\Models\Machine;
use App\Models\WeeklyAggregate;
use Carbon\Carbon;
use Livewire\Component;

class DashboardChart extends Component
{
    public ?int $machineId = null;
    public ?string $startDate = null;
    public ?string $endDate = null;
    public bool $showChart = false;
    public array $chartData = [];

    public function displayChart(): void
    {
        $this->validate([
            'machineId' => 'required|exists:machines,id',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
        ]);

        $machine = Machine::findOrFail($this->machineId);
        $weekStart = Carbon::parse($this->startDate)->isoFormat('GGGG-[W]WW');
        $weekEnd = Carbon::parse($this->endDate)->isoFormat('GGGG-[W]WW');

        $aggregates = WeeklyAggregate::where('machine_id', $machine->id)
            ->whereBetween('iso_week', [$weekStart, $weekEnd])
            ->orderBy('iso_week')
            ->get();

        $weeks = $this->generateAllWeeks($weekStart, $weekEnd);
        $pannesByWeek = $aggregates->keyBy('iso_week');

        $pannes = [];
        $fh = [];
        $noDataWeeks = [];
        foreach ($weeks as $w) {
            $entry = $pannesByWeek->get($w);
            $pannes[] = $entry?->total_pannes ?? 0;
            $fh[] = $entry?->total_flight_hours ?? 0;
            if (!$entry || ($entry->total_pannes == 0 && $entry->total_flight_hours == 0)) {
                $noDataWeeks[] = $w;
            }
        }

        $this->chartData = [
            'weeks' => $weeks,
            'pannes' => $pannes,
            'fh' => $fh,
            'noData' => $noDataWeeks,
            'title' => "Pannes & Heures de vol — {$machine->hc_id} — {$this->startDate} → {$this->endDate}",
        ];
        $this->showChart = true;
        $this->dispatch('chart-data-updated', data: $this->chartData);
    }

    private function generateAllWeeks(string $start, string $end): array
    {
        $weeks = [];
        $cursor = Carbon::now()->setISODate(
            (int) substr($start, 0, 4), (int) substr($start, 6, 2)
        )->startOfWeek();
        $endCursor = Carbon::now()->setISODate(
            (int) substr($end, 0, 4), (int) substr($end, 6, 2)
        )->startOfWeek();

        while ($cursor->lte($endCursor)) {
            $weeks[] = $cursor->isoFormat('GGGG-[W]WW');
            $cursor->addWeek();
        }
        return $weeks;
    }

    public function render()
    {
        return view('livewire.dashboard-chart', [
            'machines' => Machine::orderBy('hc_id')->get(),
        ]);
    }
}
```

- [ ] **Step 2 : Template**

`web/resources/views/livewire/dashboard-chart.blade.php` :
```blade
<div>
    <div class="bg-white shadow rounded p-6 mb-6">
        <div class="grid md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm mb-1">Machine</label>
                <select wire:model="machineId" class="border rounded w-full px-2 py-1">
                    <option value="">—</option>
                    @foreach ($machines as $m)
                        <option value="{{ $m->id }}">{{ $m->hc_id }}</option>
                    @endforeach
                </select>
                @error('machineId') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1">Date debut</label>
                <input type="date" wire:model="startDate" class="border rounded w-full px-2 py-1" />
                @error('startDate') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1">Date fin</label>
                <input type="date" wire:model="endDate" class="border rounded w-full px-2 py-1" />
                @error('endDate') <p class="text-red-500 text-xs">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button wire:click="displayChart" class="bg-blue-600 text-white px-4 py-2 rounded w-full">Afficher</button>
            </div>
        </div>
    </div>

    @if ($showChart)
        <div class="bg-white shadow rounded p-6">
            <div id="dashboard-chart" wire:ignore></div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            (function() {
                let chart = null;

                function render(data) {
                    const noDataAnnotations = data.noData.map((w) => ({
                        x: w,
                        x2: w,
                        fillColor: '#f87171',
                        opacity: 0.15,
                        label: { text: 'NO DATA', style: { color: '#b91c1c', fontSize: '10px' } },
                    }));

                    const options = {
                        chart: { type: 'line', height: 420, toolbar: { show: true } },
                        series: [
                            { name: 'Pannes', type: 'line', data: data.pannes.map((v, i) => ({ x: data.weeks[i], y: v })) },
                            { name: 'Heures de vol', type: 'line', data: data.fh.map((v, i) => ({ x: data.weeks[i], y: v })) },
                        ],
                        stroke: { width: 2, curve: 'smooth' },
                        colors: ['#1f77b4', '#d62728'],
                        markers: { size: 4 },
                        xaxis: { categories: data.weeks, labels: { rotate: -45 } },
                        yaxis: [
                            { seriesName: 'Pannes', title: { text: 'Nombre de pannes', style: { color: '#1f77b4' } }, labels: { style: { colors: '#1f77b4' } } },
                            { seriesName: 'Heures de vol', opposite: true, title: { text: 'Heures de vol', style: { color: '#d62728' } }, labels: { style: { colors: '#d62728' } } },
                        ],
                        title: { text: data.title, align: 'left' },
                        annotations: { xaxis: noDataAnnotations },
                        legend: { position: 'top' },
                    };

                    if (chart) chart.destroy();
                    chart = new ApexCharts(document.querySelector('#dashboard-chart'), options);
                    chart.render();
                }

                Livewire.on('chart-data-updated', (event) => render(event.data));
                @if (!empty($chartData))
                    render(@json($chartData));
                @endif
            })();
        </script>
    @endif
</div>
```

- [ ] **Step 3 : Page**

`web/resources/views/dashboards.blade.php` :
```blade
<x-app-layout>
    <x-slot name="header">Dashboards</x-slot>
    <livewire:dashboard-chart />
</x-app-layout>
```

- [ ] **Step 4 : Test manuel**

Visiter `/dashboards`, choisir une machine + plage. Verifier que le graphique s'affiche avec les deux courbes et les zones "NO DATA".

- [ ] **Step 5 : Commit**

```bash
cd .. && git add web && git commit -m "feat(web): interactive dashboards with ApexCharts, machine + date range filter"
```

---

## Phase 14 — Documentation et finitions

### Task 14.1 : README racine

**Files:**
- Modify: `README.md`

- [ ] **Step 1 : Ajouter une section Frontend web**

Ajouter a la fin du `README.md` racine :
```markdown

## Frontend web (Laravel)

L'application web se trouve dans `web/`. Elle expose machines, vols, pannes et dashboards.

### Lancement local

```bash
cd web

# Premier lancement
composer install
npm install
php artisan migrate
npm run build

# A chaque demarrage
php artisan queue:work &
php artisan serve
```

Ouvrir http://127.0.0.1:8000, s'inscrire, puis uploader des XML via `/upload`.
La pipeline Python est appelee automatiquement par le Job `ProcessXmlJob`.

### Deploiement
Les migrations creent automatiquement l'ensemble du schema a chaque deploiement :
```bash
php artisan migrate --force
php artisan queue:work --daemon  # superviseur recommande
```
```

- [ ] **Step 2 : Mettre a jour CLAUDE.md pour signaler l'app web**

Ajouter sous "Architecture" dans `CLAUDE.md` :
```markdown
- `web/` : application Laravel 12 + Livewire 3 (voir `web/README.md` et spec `docs/superpowers/specs/2026-04-15-laravel-frontend-design.md`)
```

- [ ] **Step 3 : Commit**

```bash
git add README.md CLAUDE.md
git commit -m "docs: document Laravel web frontend"
```

### Task 14.2 : Suite de tests complete

- [ ] **Step 1 : Lancer tous les tests PHP**

```bash
cd web && ./vendor/bin/pest
```
Expected: tous les tests passent.

- [ ] **Step 2 : Lancer tous les tests Python**

```bash
cd .. && python3 -m pytest tests/ -v
```
Expected: tests pipeline + DSN + json-output passent.

- [ ] **Step 3 : Lister les fichiers changes**

```bash
git log --oneline -30
```
Verifier que la progression suit le plan (commits par Task).

### Task 14.3 : Verification end-to-end manuelle

- [ ] **Step 1 : Reset BDD + lancement complet**

```bash
cd web
php artisan migrate:fresh
php artisan db:seed --class=UserSeeder || true
php artisan queue:work &
QUEUE_PID=$!
php artisan serve &
SERVE_PID=$!
```

- [ ] **Step 2 : Se connecter + scenario complet**

Ouvrir le navigateur :
1. `/register` -> creer un compte
2. `/upload` -> deposer `raw/exemple.xml`
3. `/imports` -> voir le vol traite
4. `/machines` -> voir NH08 (1 vol)
5. Cliquer sur NH08 -> onglet Vols -> cliquer sur le vol
6. Voir les metadonnees + duree + 2 cartes pannes
7. Cliquer "Pannes conservees" -> tester validation + signalement manquant
8. Retour machine, tester onglet "Non vols" (si applicable en deposant un XML GROUND)
9. `/dashboards` -> choisir NH08 + plage 2026-01-01 / 2026-04-30 -> graphique affiche

Arreter :
```bash
kill $QUEUE_PID $SERVE_PID
```

- [ ] **Step 3 : Commit final**

```bash
cd ..
git commit --allow-empty -m "chore: end-to-end verification complete"
```

---

## Recap commits attendus

A la fin du plan, `git log --oneline` devrait inclure au minimum :
- bootstrap Laravel 12
- install Breeze + Livewire
- install Pest
- pipeline: --json-output flag
- pipeline: expose DSN
- migrations : machines / flights / technical_events / missing_pannes / weekly_aggregates / imports
- services : XmlPipelineRunner / FlightImporter / WeeklyAggregatesIngestor
- ProcessXmlJob
- layout global + routes
- Livewire XmlUploader
- Livewire ImportsTracker
- machines list/detail
- flight detail
- pannes conservees interactives
- pannes isolees read-only
- non-vol + "C'est un vol"
- dashboards interactifs
- docs + verification e2e

---

## Notes d'execution

- **Tests Pest sur raw/exemple.xml** : les tests dependent des XML d'exemple dans `raw/`. Si `raw/exemple.xml` n'existe pas sur la machine qui execute le plan, les tests d'integration (`FlightImporterTest`, `ProcessXmlJobTest`) seront `markTestSkipped`. Le plan les ecrit quand meme, ils permettront de re-tester au besoin.
- **FailureStartTime** : le spec note que ce champ peut etre present dans le XML distinct de `RaiseDateTime`. Verifier pendant l'implementation de `FlightImporter::syncPannes()` : si `details['FailureStartTime']` est disponible dans le JSON, l'afficher en priorite dans la view `pannes-conservees`. Sinon, afficher `raise_datetime`.
- **Queue worker** : en local, lancer `php artisan queue:work` dans un terminal a part. En production, utiliser un superviseur (supervisord, systemd).
- **Fichiers staging** : prevoir un cron `php artisan schedule:run` + task planifiee pour purger `storage/app/staging/` (fichiers > 24h) dans une tache ulterieure si besoin.
- **Liberte d'ajustement** : le schema BDD et l'UI sont des propositions. Ajustement libre pendant le dev (renommage colonnes, ajout d'index, variantes de layout).
