# Architecture du site web NH Project — comprendre comment tout fonctionne

Ce document explique **le fonctionnement complet de l'application**, du navigateur de l'utilisateur jusqu'a la base de donnees, en passant par la pipeline Python. L'objectif est de te permettre de comprendre precisement **qui fait quoi** et **pourquoi**.

## 1. Vue d'ensemble en une phrase

Un utilisateur uploade un XML helicoptere depuis le navigateur ; **Laravel** le met dans une file d'attente ; un **worker** execute la **pipeline Python** qui filtre/nettoie les pannes ; les resultats sont stockes dans **PostgreSQL** (Supabase) ; l'utilisateur voit les donnees refletees en temps reel grace a **Livewire**.

## 2. Les 5 briques principales

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. NAVIGATEUR (Chrome, Firefox, ...)                            │
│    - Affiche les pages HTML                                     │
│    - Execute un peu de JavaScript genere par Livewire           │
└─────────────────────────────┬───────────────────────────────────┘
                              │ HTTP (GET/POST, upload, AJAX)
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. LARAVEL (serveur PHP)                                        │
│    - Recoit les requetes HTTP                                   │
│    - Execute la logique metier (en PHP)                         │
│    - Parle a la BDD                                             │
│    - Dispatche des Jobs en file d'attente                       │
└────────────┬─────────────────────────────────┬──────────────────┘
             │ SQL                             │ exec (sous-processus)
             ▼                                 ▼
┌────────────────────────────┐    ┌────────────────────────────────┐
│ 3. POSTGRESQL (Supabase)   │    │ 4. PIPELINE PYTHON (main.py)   │
│    - Stocke les vols,      │    │    - Parse le XML brut         │
│      pannes, imports       │    │    - Filtre les pannes         │
│    - Accessible a distance │    │    - Genere JSON + CSV         │
└────────────────────────────┘    └────────────────────────────────┘

                              ▲
                              │ une fois la pipeline terminee
                              │
┌─────────────────────────────┴───────────────────────────────────┐
│ 5. WORKER DE QUEUE (php artisan queue:work)                     │
│    - Processus qui tourne en continu                            │
│    - Depile les Jobs un par un                                  │
│    - Appelle la pipeline Python                                 │
│    - Met les resultats en BDD                                   │
└─────────────────────────────────────────────────────────────────┘
```

## 3. C'est quoi chaque brique, concretement ?

### Brique 1 — Le navigateur et l'UI

C'est ce que voit l'utilisateur : des pages HTML, des formulaires, des boutons, des tableaux.

**UI** = **User Interface** = **Interface Utilisateur**. Tout ce qui est visible dans le navigateur.

Dans notre projet, l'UI est construite avec **Blade** (templates HTML generes par Laravel) + **Livewire** (pour l'interactivite dynamique).

**Exemple de ce que contient l'UI** :

- Page `/login` avec un formulaire email/mot de passe
- Page `/upload` avec une zone de drag & drop
- Page `/imports` avec un tableau qui se rafraichit tout seul toutes les 2 secondes
- Page `/machines/NH08` avec 3 onglets (Vols / Non vols / Erreurs)

### Brique 2 — Laravel (backend)

**Backend** = la partie serveur, qui n'est **jamais** visible directement par l'utilisateur. C'est du code PHP execute sur le serveur a chaque requete HTTP.

Laravel est un **framework** qui fournit tout le squelette :

- **Routing** : quel code executer quand l'user demande telle URL ?
- **ORM Eloquent** : acceder a la BDD en PHP (sans ecrire de SQL)
- **Queue** : file d'attente pour taches lourdes
- **Auth Breeze** : login, register, reset password
- **Services** : classes reutilisables pour encapsuler la logique metier

### Brique 3 — PostgreSQL (la base de donnees)

PostgreSQL est le SGBD (systeme de gestion de bases de donnees) qui stocke toutes nos tables :

- `users` : les comptes utilisateurs
- `machines` : la liste des helicopteres (HcId)
- `flights` : les vols (un vol = un XML traite)
- `technical_events` : les pannes (conservees ou isolees)
- `missing_pannes` : les pannes signalees par les techniciens comme manquantes
- `weekly_aggregates` : les pannes et heures de vol agregees par semaine ISO (pour les dashboards)
- `imports` : historique des uploads avec leur statut (en cours, termine, erreur)

La BDD est hebergee sur **Supabase** (accessible de partout via internet). Elle contient le **schema** cree par les migrations Laravel.

### Brique 4 — La pipeline Python

La pipeline Python existait **avant** le site web. C'est `main.py` + les modules dans `src/`. Elle fait :

- Phase 1 : filtrage des `<TechnicalEvent>` par date du vol (fenetre 48h)
- Phase 2 : detection non-vol (Type != FLIGHT)
- Phase 3 : releve hebdomadaire des pannes
- Phase FH : rapport flight hours

**Important** : la pipeline Python n'est **pas** integree a Laravel. C'est un programme **separe**. Laravel l'appelle comme si tu tapais dans un terminal :

```bash
python3 main.py raw/fichier.xml --output-base storage/app/data --json-output
```

La pipeline produit des fichiers sur disque (JSON, CSV) + imprime un resume JSON sur stdout. Laravel recupere ce JSON et le parse.

### Brique 5 — Le worker de queue

Un **worker** est un processus qui tourne en continu en arriere-plan et qui depile les **Jobs** de la file d'attente un par un.

Dans notre projet, on lance le worker avec :

```bash
php artisan queue:work
```

Le worker regarde la table `jobs` de la BDD. Des qu'il y a un nouveau Job, il l'execute.

## 4. Terminologie Laravel : les concepts cles

### 4.1 Route

Une **route** fait correspondre une URL a du code PHP.

```php
// dans web/routes/web.php
Route::get('/machines', [MachineController::class, 'index'])->name('machines.index');
```

Signifie : quand un navigateur demande `GET /machines`, Laravel execute la methode `index()` du `MachineController`.

### 4.2 Controller

Un **controller** est une classe PHP qui contient la logique de gestion d'une URL :

```php
class MachineController {
    public function index() {
        $machines = Machine::all();
        return view('machines.index', compact('machines'));
    }
}
```

Il recupere des donnees (ici toutes les machines de la BDD) et rend une **view** (un template HTML) en lui passant ces donnees.

### 4.3 Model (Eloquent)

Un **model** est une classe PHP qui represente une table de la BDD. L'ORM **Eloquent** permet de manipuler la BDD en PHP sans ecrire de SQL :

```php
$m = Machine::create(['hc_id' => 'NH08']);   // INSERT
$all = Machine::all();                        // SELECT *
$nh08 = Machine::where('hc_id', 'NH08')->first();  // SELECT ... WHERE
```

### 4.4 View (template Blade)

Un **template Blade** est un fichier HTML avec des balises `{{ }}` pour afficher des variables PHP :

```blade
{{-- web/resources/views/machines/index.blade.php --}}
<ul>
    @foreach ($machines as $m)
        <li>{{ $m->hc_id }}</li>
    @endforeach
</ul>
```

Laravel execute ce template cote serveur, remplace les `{{ }}` par les vraies valeurs, et envoie le HTML final au navigateur.

### 4.5 Migration

Une **migration** est un fichier PHP qui decrit une modification du schema de la BDD (creer une table, ajouter une colonne, etc.) :

```php
Schema::create('machines', function ($table) {
    $table->id();
    $table->string('hc_id')->unique();
    $table->timestamps();
});
```

La commande `php artisan migrate` execute toutes les migrations non encore appliquees.

**Avantage** : le schema de la BDD est versionne dans le code. Quand tu deploies sur un nouveau serveur, `php artisan migrate` recree toutes les tables automatiquement.

### 4.6 Service

Un **service** est une classe PHP reutilisable qui encapsule une logique metier.

Dans notre projet, on a 3 services :

- **XmlPipelineRunner** : appelle la pipeline Python et parse le JSON retourne
- **FlightImporter** : lit les fichiers JSON produits par la pipeline et les insere dans la BDD
- **WeeklyAggregatesIngestor** : lit les CSV yearly et fait des UPSERT dans la table `weekly_aggregates`

### 4.7 Job

Un **Job** est une tache qui est placee en file d'attente et executee en arriere-plan (pas pendant la requete HTTP).

Dans notre projet : `ProcessXmlJob`. Quand l'utilisateur upload un XML :

1. Le controller cree une ligne `imports` (status=pending)
2. Le controller dispatche un `ProcessXmlJob` dans la queue
3. Le controller repond **immediatement** au navigateur : "OK je traite"
4. Plus tard, le worker depile le Job et l'execute : pipeline Python -> insert BDD -> mise a jour de `imports.status`

**Pourquoi ?** Parce que la pipeline peut prendre 30-60 secondes. Si on faisait tout dans le controller, le navigateur timeouterait.

### 4.8 Queue et worker

La **queue** est une file d'attente de Jobs. Chez nous, elle est stockee dans la table `jobs` de PostgreSQL (driver `database`).

Le **worker** (`php artisan queue:work`) est le processus qui consomme la queue :

```
Queue (table jobs):
  [Job1: traiter exemple.xml]
  [Job2: traiter exemple2.xml]
  [Job3: traiter exemple3.xml]

Worker:
  -> prend Job1 -> execute la pipeline -> met resultats en BDD -> supprime Job1
  -> prend Job2 -> ...
```

### 4.9 Livewire

**Livewire** est un package qui permet de construire des composants d'UI interactifs **en PHP pur**, sans ecrire de JavaScript.

Exemple : un bouton qui valide une panne.

```blade
<button wire:click="setValidation(42, 'validated')">Valider</button>
```

Sans Livewire, il faudrait ecrire un handler JavaScript qui fait une requete AJAX. Avec Livewire, tu ecris ta methode PHP :

```php
class PannesConserveesTable extends Component {
    public function setValidation(int $id, string $status) {
        TechnicalEvent::find($id)->update(['validation_status' => $status]);
    }
}
```

Livewire s'occupe automatiquement de :

- Intercepter le clic en JavaScript
- Faire une requete AJAX vers Laravel
- Executer la methode PHP correspondante
- Rerender le composant
- Renvoyer le nouveau HTML au navigateur
- Mettre a jour la page sans reload

Autre exemple : **polling** automatique (rafraichissement de page).

```blade
<div wire:poll.2s>
    {{-- Cette zone se rafraichit toutes les 2 secondes --}}
</div>
```

C'est comme ca qu'on fera la page `/imports` qui met a jour l'etat des traitements en temps reel.

## 5. Le flow complet d'un upload, etape par etape

Scenario : Alice ouvre `/upload`, depose 3 XML, clique "Traiter 3 fichiers", et regarde les imports se derouler.

### Etape 1 — Ouverture de la page upload

```
Alice -> navigateur : tape http://127.0.0.1:8000/upload
Navigateur -> Laravel : GET /upload
Laravel (route) : detecte l'URL, appelle le controller associe
Laravel (controller) : rend le template `upload.blade.php`
Template : contient <livewire:xml-uploader />
Livewire : initialise le composant XmlUploader
Laravel -> navigateur : envoie le HTML de la page
Navigateur : affiche la zone drag & drop
```

### Etape 2 — Drag & drop

```
Alice : glisse 3 fichiers XML sur la zone
Livewire (JS) : intercepte le drop, envoie les fichiers a Laravel via AJAX
Laravel : valide les fichiers (taille, extension), les stocke dans storage/app/staging/
Livewire (PHP) : met a jour la liste $stagedFiles
Livewire -> navigateur : renvoie le HTML mis a jour avec les 3 fichiers listes
```

### Etape 3 — Clic sur "Traiter"

```
Alice : clique sur "Traiter 3 fichiers"
Livewire (JS) : appelle la methode submit() de XmlUploader via AJAX
Livewire (PHP submit) :
  pour chaque fichier :
    - cree une ligne Import (status=pending)
    - dispatch un ProcessXmlJob(importId, stagingPath) dans la queue
  - redirige vers /imports
Navigateur : charge /imports
```

### Etape 4 — Arriere-plan : le worker bosse

Pendant qu'Alice regarde la page `/imports`, le worker (autre processus) fait :

```
Worker : lit la table jobs, trouve ProcessXmlJob #1
Worker -> execute ProcessXmlJob->handle() :
  1. Import #1 -> status = processing (en BDD)
  2. Appelle XmlPipelineRunner->run(stagingPath, outputBase)
     XmlPipelineRunner : lance "python3 main.py ..." via Symfony\Process
     Pipeline Python : filtre les pannes, ecrit xml_epure.xml + pannes_conservees.json + pannes_isolees.json + CSV cumules
     XmlPipelineRunner : parse le JSON imprime sur stdout, retourne un array
  3. Selon status :
     - "ok" -> FlightImporter->import() : cree Machine/Flight/TechnicalEvents
     - "no_engine" -> FlightImporter->importNonVol() : cree Flight is_non_vol=true
     - "error" -> met Import.status = error avec message
  4. WeeklyAggregatesIngestor->ingest() : lit les CSV yearly, upsert weekly_aggregates
  5. Import #1 -> status = ok + result = {hc_id, dsn, num, pannes_count, flight_hours}
Worker : passe au Job #2
```

### Etape 5 — Alice voit les resultats en temps reel

```
Page /imports : composant ImportsTracker avec wire:poll.2s
Toutes les 2s :
  Livewire (JS) : fait une requete AJAX
  Livewire (PHP) : relit la table imports
  Livewire : renvoie le nouveau HTML
  Navigateur : met a jour le tableau (statuts qui passent de "en cours" a "ok")
Alice : voit en direct "XML #1 traite ✓ NH08 — DSN 612 — 13 pannes — 2.43h"
```

### Etape 6 — Alice navigue vers les donnees importees

```
Alice : clique sur Machines
Laravel : affiche la liste des HcId avec nb vols
Alice : clique sur NH08
Laravel : affiche les 3 onglets (Vols / Non vols / Erreurs)
Alice : clique sur le vol NH08 #612
Laravel : affiche les metadonnees + 2 cartes (Pannes conservees / isolees)
Alice : clique Pannes conservees
Livewire : affiche un tableau interactif avec boutons de validation
```

Tout est **dans la BDD** a ce stade. La pipeline Python n'est plus sollicitee.

## 6. Architecture des dossiers

### Racine du repo

```
nh_project/
├── main.py                  # Point d'entree pipeline Python
├── src/                     # Modules pipeline Python
│   ├── cleaning/            #   date_filter.py, engine_filter.py
│   └── reporting/           #   weekly_report.py, fh_report.py, aggregate_report.py
├── data/                    # Sorties pipeline : XML epures, JSON pannes, CSV rapports
├── raw/                     # XML bruts (entrees)
├── tests/                   # Tests pytest de la pipeline
├── web/                     # Application Laravel (voir ci-dessous)
├── docs/                    # Specs, plans, architecture (ce fichier)
└── CLAUDE.md                # Notes du projet (lu automatiquement par Claude)
```

### Dossier `web/` (Laravel)

```
web/
├── app/
│   ├── Http/
│   │   └── Controllers/     # Logique par URL (MachineController, FlightController, ...)
│   ├── Jobs/                # ProcessXmlJob (queue worker)
│   ├── Livewire/            # Composants UI interactifs (XmlUploader, ImportsTracker, ...)
│   ├── Models/              # Tables BDD -> classes PHP (Machine, Flight, Import, ...)
│   └── Services/            # XmlPipelineRunner, FlightImporter, WeeklyAggregatesIngestor
├── config/                  # Fichiers de config Laravel
├── database/
│   └── migrations/          # Modifications du schema BDD
├── resources/
│   ├── css/                 # Tailwind
│   ├── js/                  # Alpine.js, etc.
│   └── views/               # Templates Blade
│       ├── layouts/         # Layout global (sidebar)
│       ├── machines/        # Pages /machines
│       ├── flights/         # Pages /flights
│       └── livewire/        # Templates des composants Livewire
├── routes/
│   └── web.php              # Definition des routes
├── storage/
│   ├── app/
│   │   ├── staging/         # Fichiers XML uploades temporairement
│   │   └── data/            # Sorties pipeline (appelee par Laravel)
│   └── logs/                # Logs Laravel
├── tests/                   # Tests Pest (feature + unit)
├── .env                     # Config sensible (DB_PASSWORD, APP_KEY...) — PAS commit
└── artisan                  # CLI Laravel (php artisan ...)
```

## 7. Comment ca tourne en developpement local

Pour lancer l'app en local, on a besoin de **3 processus tournant en parallele** :

```
Terminal 1 : php artisan serve       # Serveur web (port 8000)
Terminal 2 : php artisan queue:work  # Worker de queue
Terminal 3 : sudo service postgresql start (si pas deja fait) + rien a faire
```

Puis dans un navigateur : `http://127.0.0.1:8000` -> se connecter avec `test@nh.local` / `password`.

Note : en prod, on utilisera un **superviseur** (systemd, supervisord) pour maintenir le worker actif en permanence.

## 8. Securite et environnements

### Dev (local) vs Prod

- **Dev local** : `APP_ENV=local`, `APP_DEBUG=true`, BDD Supabase (cloud)
- **Prod (hebergeur)** : `APP_ENV=production`, `APP_DEBUG=false`, meme BDD Supabase (ou une autre), domain `https://...`
- Les migrations Laravel garantissent que le schema est identique dev et prod

### Fichier .env

Le fichier `web/.env` contient les secrets (mot de passe BDD, cle d'app, URL). Il est **dans `.gitignore`** donc jamais commit sur GitHub. Chaque machine a son propre `.env`.

### Auth

Laravel Breeze gere le login avec email + mot de passe (hash bcrypt). Les sessions sont stockees dans la table `sessions` de PostgreSQL.

Aucune page du site (sauf /login et /register) n'est accessible sans etre connecte, grace au middleware `auth` sur toutes les routes.

## 9. Glossaire rapide

| Terme | Signification |
|---|---|
| **UI** | User Interface, ce que voit l'utilisateur |
| **Backend** | Code serveur, jamais vu directement par l'utilisateur |
| **Framework** | Ensemble de conventions + code pret a l'emploi (Laravel) |
| **Route** | URL -> code a executer |
| **Controller** | Classe qui traite les requetes d'une URL |
| **Model** | Classe PHP representant une table BDD (Eloquent) |
| **Migration** | Fichier PHP qui modifie le schema BDD |
| **Template Blade** | Fichier HTML avec variables PHP `{{ }}` |
| **Service** | Classe PHP encapsulant une logique metier |
| **Job** | Tache executee en arriere-plan via queue |
| **Queue** | File d'attente de Jobs (table `jobs` en BDD) |
| **Worker** | Processus qui depile et execute les Jobs |
| **Livewire** | Package qui rend l'UI interactive en PHP (pas de JS a ecrire) |
| **ORM (Eloquent)** | Interroge la BDD en PHP sans SQL direct |
| **ISO 8601** | Format de date standard `YYYY-MM-DDTHH:MM:SS` |
| **UPSERT** | INSERT si nouveau, UPDATE si existe (cle unique) |
| **JSON** | Format texte leger pour echanger des donnees |
| **CSV** | Fichier tabulaire avec valeurs separees par virgules |
| **PostgreSQL** | SGBD relationnel, notre BDD |
| **Supabase** | Service cloud qui heberge notre PostgreSQL |
| **Composer** | Gestionnaire de dependances PHP |
| **npm** | Gestionnaire de dependances JavaScript |
| **Vite** | Compilateur de CSS/JS moderne (build) |
| **Pest** | Framework de tests PHP |

## 10. Resume en 10 lignes

1. Le site web est une app **Laravel 12** hebergee dans le dossier `web/`
2. La BDD est **PostgreSQL sur Supabase** (accessible de partout)
3. L'UI est faite en **Blade** (templates HTML) + **Livewire** (interactivite sans JS)
4. L'utilisateur uploade des XML, qui sont stages puis mis en **file d'attente** (Jobs Laravel)
5. Un **worker** (`php artisan queue:work`) depile les Jobs
6. Chaque Job appelle la **pipeline Python** (programme separe, inchange)
7. La pipeline ecrit des JSON/CSV, que Laravel lit et stocke en BDD
8. L'utilisateur voit l'avancement en temps reel grace a Livewire (polling 2s)
9. Ensuite il peut naviguer dans les machines, vols, pannes, dashboards
10. Tout est versionne dans git, les migrations recreent la BDD partout ou on deploie
