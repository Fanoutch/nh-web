# Commandes — nh-web + nh-pipeline

Reference complete des commandes Laravel, serveur et deploiement. Source de verite unique pour les deux repos (`nh-web` Laravel + `nh-pipeline` Python).

> **Pour le deploiement complet sur un nouveau serveur**, voir aussi `docs/Pre-DEPLOIEMENT.md` qui detaille les etapes pas a pas (vhost nginx, supervisord, bootstrap super admin, smoke test).

## Sommaire

1. [Developpement local](#1-developpement-local)
2. [Base de donnees](#2-base-de-donnees)
3. [Cache et optimisation](#3-cache-et-optimisation)
4. [Queue worker](#4-queue-worker)
5. [Tests](#5-tests)
6. [Pipeline Python](#6-pipeline-python)
7. [Services systemd (machine actuelle)](#7-services-systemd-machine-actuelle)
8. [Deploiement nouveau serveur (prod)](#8-deploiement-nouveau-serveur-prod)
9. [Mises a jour en prod](#9-mises-a-jour-en-prod)
10. [Troubleshooting](#10-troubleshooting)
11. [Dependances et separation des repos](#11-dependances-et-separation-des-repos)
12. [Changement de logo](#12-changement-de-logo)

---

## 1. Developpement local

### Lancer l'app pour developper

```bash
cd /path/to/nh-web

# Terminal 1 : serveur web
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 : worker (pour traiter les uploads XML)
php artisan queue:work --tries=1 --timeout=600
```

Acces : http://127.0.0.1:8000

### Creer un user

```bash
cd /path/to/nh-web

# Via tinker
php artisan tinker
# Dans tinker :
\App\Models\User::create([
    'name' => 'Camille',
    'email' => 'camille@nh.local',
    'password' => Hash::make('motdepassesecure'),
]);
exit
```

Ou via la page web `/register`.

### Modifier la config

```bash
nano /path/to/nh-web/.env

# Apres modification, vider le cache :
php artisan config:clear
```

### Tinker — Shell PHP interactif

`php artisan tinker` ouvre un REPL PHP avec acces complet a l'app Laravel (modeles, facades, BDD, jobs). Utile pour debug, admin ponctuelle, tester du code sans ecrire de script.

```bash
cd /root/camille2/nh-web
php artisan tinker
# Pour quitter : exit
```

#### Inspecter la BDD

```php
// Lister les colonnes d'une table
\Schema::getColumnListing('users');
\Schema::getColumnListing('technical_events');

// Verifier qu'une colonne existe
\Schema::hasColumn('users', 'is_admin');

// Compter / requeter
\App\Models\User::count();
\App\Models\Flight::latest()->first();
\App\Models\TechnicalEvent::where('validation_status', 'validated')->count();
```

#### Gestion des utilisateurs

```php
// Creer un user
\App\Models\User::create([
    'name' => 'Admin',
    'email' => 'admin@x.com',
    'password' => 'mot_de_passe',  // hashe automatiquement
]);

// Promouvoir un user en admin (apres migration is_admin)
$u = \App\Models\User::where('email', 'contact@aivolutionedge.com')->first();
$u->is_admin = true;
$u->save();

// Lister tous les admins
\App\Models\User::where('is_admin', true)->get();

// Retirer le statut admin
\App\Models\User::where('email', 'x@y.com')->update(['is_admin' => false]);

// Creer un super admin (utile pour bootstrap : apres ca, gestion via /admin/users)
\App\Models\User::create([
    'name' => 'Super Admin',
    'email' => 'super@nh.local',
    'password' => 'mdpfort',
    'is_admin' => true,
    'is_super_admin' => true,
]);

// Lister tous les super admins
\App\Models\User::where('is_super_admin', true)->get();

// Promouvoir un admin existant en super admin
$u = \App\Models\User::where('email', 'admin@nh.local')->first();
$u->is_super_admin = true;
$u->is_admin = true;  // un super admin est aussi admin
$u->save();

// Voir tous les comptes avec leur niveau
\App\Models\User::all()->map(fn($u) => [
    'email' => $u->email,
    'is_admin' => $u->is_admin,
    'is_super_admin' => $u->is_super_admin,
]);
```

#### Jobs et queue

```php
// Dispatcher un job manuellement
\App\Jobs\ProcessXmlJob::dispatch('/chemin/fichier.xml');

// Compter les jobs en attente
\DB::table('jobs')->count();

// Compter les jobs en echec
\DB::table('failed_jobs')->count();
```

#### Audit log (Spatie ActivityLog)

Le journal d'audit est dans la table `activity_log`. Il enregistre :
- les modifications sur `TechnicalEvent` (validation des pannes : created/updated/deleted)
- les erreurs pipeline (`log_name='pipeline'`, `event='error'`) — voir `app/Jobs/ProcessXmlJob.php`

Page UI : `/admin/audit-log` (super admin uniquement).

```php
use Spatie\Activitylog\Models\Activity;

// Compter les entrees
Activity::count();

// 20 dernieres entrees, plus recentes en haut
Activity::latest()->take(20)->get(['id','log_name','event','subject_type','subject_id','causer_id','created_at']);

// Filtrer par module / event
Activity::where('log_name', 'pipeline')->where('event', 'error')->get();
Activity::where('log_name', 'default')->where('event', 'updated')->count();

// Filtrer par utilisateur (causer)
$u = \App\Models\User::where('email', 'contact@aivolutionedge.com')->first();
Activity::where('causer_id', $u->id)->latest()->take(10)->get();

// Voir les changements detailles d'une entree
$a = Activity::find(123);
$a->properties->toArray();   // ['old' => [...], 'attributes' => [...]]

// Lister les modules (log_name) presents
Activity::distinct()->pluck('log_name');

// Voir la cible d'une entree (TechnicalEvent, Import, etc.)
$a = Activity::latest()->first();
$a->subject;                 // resout polymorphiquement le modele cible

// Purger les entrees plus vieilles que 90 jours
Activity::where('created_at', '<', now()->subDays(90))->delete();

// Vider tout le journal (DESTRUCTIF)
Activity::truncate();

// Logger manuellement (debug/admin ponctuelle)
activity('manual')
    ->event('note')
    ->causedBy(auth()->user())
    ->withProperties(['attributes' => ['msg' => 'Maintenance prevue']])
    ->log('Note manuelle');
```

#### Pannes occurrentes (recurrent_failures)

Table alimentee par `RecurrentFailuresIngestor` apres chaque XML traite. Page UI : `/machines/{hcId}` onglet "Pannes occurrentes". Le lien vers la machine est via `machine_id` (FK), pas `hc_id` direct.

```php
// Lister toutes les pannes occurrentes pour une machine (via hc_id)
\App\Models\RecurrentFailure::whereHas('machine', fn($q) => $q->where('hc_id', 'NH03'))
    ->orderByDesc('score')->get(['id','description','status','score','active_depuis_date']);

// Top 10 toutes machines confondues (score = indicateur de recurrence)
\App\Models\RecurrentFailure::with('machine')->orderByDesc('score')->take(10)->get()
    ->map(fn($r) => [
        'hc_id'       => $r->machine->hc_id,
        'description' => $r->description,
        'score'       => $r->score,
        'status'      => $r->status,
    ]);

// Compter par machine
\App\Models\RecurrentFailure::with('machine')->get()
    ->groupBy(fn($r) => $r->machine->hc_id)
    ->map->count();

// Pannes manquantes (detectees recurrentes mais jamais reportees explicitement)
\App\Models\MissingPanne::with('flight.machine')->get()
    ->map(fn($m) => [
        'hc_id'       => $m->flight->machine->hc_id,
        'description' => $m->description,
        'failure_code'=> $m->failure_code,
        'reported_by' => $m->reporter?->name,
    ]);
```

#### Astuces

- Auto-completion : commence a taper et appuie sur Tab
- Historique : fleche haut pour rappeler une commande precedente
- Les erreurs ne crashent pas tinker — tu peux retenter
- Mode one-shot sans shell : `php artisan tinker --execute="echo \App\Models\User::count();"`

---

## 2. Base de donnees

### Lister les tables

```bash
PGPASSWORD=$DB_PASSWORD psql -h $DB_HOST -U $DB_USER -d $DB_NAME -c "\dt"

# Exemple Supabase :
PGPASSWORD="ton_password" psql -h db.xxx.supabase.co -U postgres -d postgres -c "\dt"
```

### Migrations Laravel

```bash
cd web

# Voir le statut de toutes les migrations
php artisan migrate:status

# Lancer les migrations en attente
php artisan migrate

# En prod (sans confirmation interactive)
php artisan migrate --force

# Annuler la derniere migration
php artisan migrate:rollback

# Tout reset puis re-migrer (DESTRUCTIF — efface tout)
php artisan migrate:fresh

# Reset + migrer + seeder (DESTRUCTIF)
php artisan migrate:fresh --seed
```

### Creer une nouvelle migration

```bash
cd web

# Creer une table
php artisan make:migration create_pilotes_table

# Modifier une table existante
php artisan make:migration add_email_to_pilotes_table --table=pilotes

# Appliquer
php artisan migrate
```

### Sauvegarder / restaurer la BDD

```bash
# Backup BDD locale
pg_dump -h 127.0.0.1 -U nh_user nh_project_dev > backup_$(date +%Y%m%d).sql

# Backup Supabase
PGPASSWORD="ton_password" pg_dump -h db.xxx.supabase.co -U postgres postgres > backup_supabase.sql

# Restauration
PGPASSWORD="ton_password" psql -h db.xxx.supabase.co -U postgres postgres < backup_supabase.sql
```

### Recreer la BDD sur un nouveau serveur / nouvelle DB

Scenario : tu as une **nouvelle base Postgres vide** (autre Supabase project, nouveau serveur, autre VPS) et tu veux y recreer toutes les tables du schema NH Project, puis y rebooter le 1er super admin.

**Pas de `pg_dump` necessaire** : Laravel sait recreer tout le schema depuis les fichiers `database/migrations/*.php` du repo. Aucune donnee metier n'est migree (vols, pannes, hélicos, imports) — les comptes users sont aussi vides, tu les re-seedes via tinker.

```bash
# 1. Pointer .env vers la nouvelle DB
cd /path/to/nh-web
nano .env
# Modifier :
#   DB_HOST=db.NOUVEAU.supabase.co   (ou IP du nouveau Postgres)
#   DB_DATABASE=postgres
#   DB_USERNAME=postgres
#   DB_PASSWORD=NOUVEAU_PASSWORD
#   DB_SSLMODE=require               (Supabase) ou prefer (Postgres local)

# 2. Vider le cache de config (toujours apres modif .env)
php artisan config:clear

# 3. Verifier la connexion
php artisan tinker
# >>> \DB::connection()->getPdo();   // doit pas planter
# >>> \Schema::getAllTables();       // peut etre vide ou contenir les tables d'un autre projet
# exit

# 4. Recreer toutes les tables NH Project
php artisan migrate --force

# 5. Verifier (15 tables environ)
php artisan migrate:status
# OU via psql :
PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -U "$DB_USERNAME" -d "$DB_DATABASE" -c "\dt"

# 6. Re-seeder le 1er super admin (DB vide = aucun compte)
php artisan tinker
```

```php
\App\Models\User::create([
    'name'           => 'Admin Principal',
    'email'          => 'admin@ton-domaine.fr',
    'password'       => bcrypt('MdpFortDeProd!'),
    'is_admin'       => true,
    'is_super_admin' => true,
]);
exit
```

```bash
# 7. Connexion via /login → /admin/users pour creer les autres comptes
# 8. Premier upload XML via /upload → cree machines + flights + pannes auto
```

**Tables creees par les migrations :** `users`, `sessions`, `cache`, `jobs`, `failed_jobs`, `machines`, `flights`, `technical_events`, `imports`, `weekly_aggregates`, `recurrent_failures`, `missing_pannes`, `activity_log`, `password_reset_tokens`, `personal_access_tokens`.

**Si la nouvelle DB n'est PAS vide** (ex. tables d'un ancien projet) — soit clean d'abord avec un `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` cote psql, soit utilise `php artisan migrate:fresh --force` (DESTRUCTIF — drop toutes les tables avant migrate).

### Reset des donnees metier (dev) — sans detruire le schema

Pour repartir de zero sur les donnees (vols, pannes, imports) **en gardant les comptes users + le schema**, plus rapide que `migrate:fresh` :

```bash
cd /path/to/nh-web
php artisan tinker
```

```php
// Vide les tables metier dans l'ordre (FK)
\DB::table('technical_events')->truncate();
\DB::table('flights')->truncate();
\DB::table('weekly_aggregates')->truncate();
\DB::table('recurrent_failures')->truncate();
\DB::table('missing_pannes')->truncate();
\DB::table('imports')->truncate();
\DB::table('machines')->truncate();

// Optionnel : journal d'audit
\DB::table('activity_log')->truncate();

// Optionnel : queue + jobs en echec
\DB::table('jobs')->truncate();
\DB::table('failed_jobs')->truncate();

// Garde intactes : users, sessions, cache, migrations
exit
```

Ensuite, vider les fichiers staging cote disque :

```bash
rm -rf /path/to/nh-web/storage/app/staging_*
rm -rf /path/to/nh-pipeline/data/reports/yearly/*
rm -rf /path/to/nh-pipeline/data/FHreport/yearly/*
```

Reset complet (DESTRUCTIF — efface AUSSI les users) :

```bash
php artisan migrate:fresh
```

---

## 3. Cache et optimisation

```bash
cd web

# Vider tous les caches
php artisan optimize:clear

# Caches individuels
php artisan config:clear        # cache de config (.env)
php artisan route:clear         # cache de routes
php artisan view:clear          # templates Blade compiles
php artisan cache:clear         # cache applicatif

# En PROD : pre-compiler les caches (perf++)
php artisan config:cache
php artisan route:cache
php artisan view:cache
# Ou tout en une commande :
php artisan optimize
```

---

## 4. Queue worker

```bash
cd web

# Lancer le worker (mode foreground)
php artisan queue:work --tries=1 --timeout=600

# Avec sleep pour reduire la charge CPU
php artisan queue:work --sleep=3 --tries=1

# Lancer en mode "stop quand vide" (utile pour tests)
php artisan queue:work --once --stop-when-empty

# Voir les jobs en attente
php artisan tinker
>>> \DB::table('jobs')->count();

# Voir les jobs en echec
php artisan queue:failed

# Relancer un job en echec
php artisan queue:retry <id>

# Relancer tous les jobs en echec
php artisan queue:retry all

# Vider les jobs en echec
php artisan queue:flush
```

---

## 5. Tests

### Tests Laravel (Pest)

```bash
cd web

# Tous les tests
./vendor/bin/pest

# Un seul fichier
./vendor/bin/pest tests/Feature/XmlUploaderTest.php

# Un seul test (par nom)
./vendor/bin/pest --filter "imports a flight"

# Avec coverage
./vendor/bin/pest --coverage
```

### Tests Python (pytest)

La suite pytest n'est plus packagee dans `nh-pipeline` (runtime prod minimal). Elle est archivee dans `nh_project/tests/` pour relancement ponctuel.

```bash
cd /chemin/vers/nh_project
python3 -m pytest tests/ -v
python3 -m pytest tests/test_main_json_output.py
```

---

## 6. Pipeline Python

```bash
cd /path/to/nh-pipeline

# Traiter un XML unique
python3 main.py raw/exemple.xml --output-base data

# Avec sortie JSON (utilisee par Laravel Job)
python3 main.py raw/exemple.xml --output-base /tmp/test --json-output

# Mode strict (pas de tolerance 48h)
python3 main.py raw/exemple.xml --filtre-mode strict

# Mode 24h
python3 main.py raw/exemple.xml --filtre-mode 24h

# Batch (lit config/settings.yaml)
python3 run.py
```

> Le script `dashboard.py` (generateur PNG legacy) a ete sorti du runtime apres Phase 2 (le dashboard web ApexCharts lit Postgres directement). Il est archive dans `nh_project/dashboard.py` si tu veux le rejouer ponctuellement.

---

## 7. Services systemd (machine actuelle)

Configures en service permanent sur cette machine.

```bash
# Voir l'etat
sudo systemctl status nh-laravel.service nh-queue.service postgresql

# Demarrer / arreter / redemarrer
sudo systemctl start nh-laravel
sudo systemctl stop nh-laravel
sudo systemctl restart nh-laravel
sudo systemctl restart nh-queue   # apres modif du code

# Activer / desactiver le boot auto
sudo systemctl enable nh-laravel
sudo systemctl disable nh-laravel

# Voir les logs en live
sudo tail -f /var/log/nh-laravel.log
sudo tail -f /var/log/nh-queue.log
sudo journalctl -u nh-laravel -f -n 50

# Editer la config d'un service
sudo nano /etc/systemd/system/nh-laravel.service
sudo systemctl daemon-reload   # apres modif
sudo systemctl restart nh-laravel
```

---

## 8. Deploiement nouveau serveur (prod)

> **Le deroule complet est dans `docs/Pre-DEPLOIEMENT.md`** (architecture 2 repos, prerequis serveur, vhost nginx, supervisord, bootstrap super admin, smoke test, mise a jour applicative).

Les commandes ci-dessous sont une **synthese** pour rappel rapide. Pour un premier deploiement, suivre `docs/Pre-DEPLOIEMENT.md`.

### Resume des etapes cles

```bash
# 1. Pre-requis (Ubuntu 22.04+)
apt install -y php8.3-cli php8.3-fpm php8.3-pgsql php8.3-xml php8.3-mbstring \
               php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl \
               nodejs python3 python3-pip python3-venv nginx supervisor git unzip

# 2. Cloner les 2 repos
git clone https://github.com/Fanoutch/nh-web.git      /path/to/nh-web
git clone https://github.com/Fanoutch/nh-pipeline.git /path/to/nh-pipeline

# 3. Installer les deps
cd /path/to/nh-web
composer install --no-dev --optimize-autoloader && npm ci && npm run build
cd /path/to/nh-pipeline
python3 -m venv venv && source venv/bin/activate && pip install -r requirements.txt && deactivate

# 4. Config .env (cf. Pre-DEPLOIEMENT.md section 5)
cd /path/to/nh-web
cp .env.example .env
php artisan key:generate
nano .env   # DB_*, APP_URL, PIPELINE_PATH=/path/to/nh-pipeline

# 5. Migrations + symlink storage + caches
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache

# 6. Permissions
chown -R www-data:www-data /path/to/nh-web/storage /path/to/nh-web/bootstrap/cache
chmod -R 775 /path/to/nh-web/storage /path/to/nh-web/bootstrap/cache

# 7. Bootstrap super admin
sudo -u www-data php artisan tinker
# >>> \App\Models\User::create(['name'=>'Admin','email'=>'admin@x.fr','password'=>bcrypt('xxx'),'is_admin'=>true,'is_super_admin'=>true]);

# 8. Vhost nginx + SSL (cf. Pre-DEPLOIEMENT.md sections 9-10)
# 9. Worker supervisord (cf. Pre-DEPLOIEMENT.md section 10)
```

Visiter `https://tondomaine.com` dans un navigateur, se connecter, uploader un XML.

---

## 9. Mises a jour en prod

Workflow standard apres une modification du code en local :

### Cote local (ton PC)

```bash
cd /path/to/nh-web
git add .
git commit -m "fix: ..."
git push origin main

# Idem cote pipeline Python si modif :
cd /path/to/nh-pipeline
git add . && git commit -m "..." && git push
```

### Cote serveur prod

```bash
ssh root@ip_serveur
cd /path/to/nh-web

# Recuperer le code
git pull

# Si nouvelles dependances PHP
sudo -u www-data composer install --no-dev --optimize-autoloader

# Si nouvelles dependances JS / CSS modifies
sudo -u www-data npm ci
sudo -u www-data npm run build

# Si nouvelles migrations
sudo -u www-data php artisan migrate --force

# Rebuild les caches
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# Si modifs cote pipeline Python
cd /path/to/nh-pipeline && git pull
# Si requirements.txt change :
source venv/bin/activate
pip install -r requirements.txt
deactivate

# Relancer le worker (supervisord ou systemd selon setup)
supervisorctl restart nh-web-queue:*
# ou : systemctl restart nh-queue.service
```

### Script tout-en-un (a creer une fois sur le serveur)

```bash
nano /path/to/nh-web/deploy.sh
```

```bash
#!/bin/bash
set -e

NH_WEB=/path/to/nh-web
NH_PIPELINE=/path/to/nh-pipeline

echo "==> Pulling nh-web..."
cd "$NH_WEB" && git pull

echo "==> Pulling nh-pipeline..."
cd "$NH_PIPELINE" && git pull

echo "==> Installing PHP deps..."
cd "$NH_WEB"
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Building frontend..."
sudo -u www-data npm ci
sudo -u www-data npm run build

echo "==> Running migrations..."
sudo -u www-data php artisan migrate --force

echo "==> Rebuilding caches..."
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "==> Restarting queue worker..."
supervisorctl restart nh-web-queue:* || systemctl restart nh-queue.service

echo "==> Done!"
```

```bash
chmod +x /path/to/nh-web/deploy.sh

# Pour deployer ensuite :
ssh root@ip_serveur '/path/to/nh-web/deploy.sh'
```

---

## 10. Troubleshooting

### Le site renvoie 500

```bash
# Voir les logs
tail -50 /path/to/nh-web/storage/logs/laravel.log
tail -50 /var/log/nginx/error.log

# Causes frequentes :
# - Permissions manquantes sur storage/ ou bootstrap/cache/
chown -R www-data:www-data /path/to/nh-web/storage /path/to/nh-web/bootstrap/cache

# - Cache corrompu
php artisan optimize:clear

# - Config manquante (APP_KEY)
php artisan key:generate
```

### La queue ne traite pas

```bash
# Verifier que le worker tourne
systemctl status nh-queue.service

# Voir les jobs en attente
php artisan tinker
>>> \DB::table('jobs')->count();
>>> \DB::table('failed_jobs')->count();

# Relancer manuellement
php artisan queue:work --once --stop-when-empty -v
```

### Connexion BDD echoue

```bash
# Tester depuis le serveur
PGPASSWORD="$DB_PASSWORD" psql -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE -c "SELECT 1"

# Verifier .env
cat /path/to/nh-web/.env | grep DB_

# Vider le cache de config (souvent oublie)
php artisan config:clear
```

### Pipeline Python echoue

```bash
# Tester en standalone
cd /path/to/nh-pipeline
python3 main.py raw/test.xml --output-base /tmp/test --json-output

# Voir les logs du Job
tail -f /var/log/nh-web-worker.log
# ou : tail -f /var/log/nh-queue.log

# Verifier les permissions
ls -la /path/to/nh-pipeline/data/
chown -R www-data:www-data /path/to/nh-pipeline/data
```

### Liberer de l'espace disque

```bash
# Nettoyer les fichiers staging vieux de plus de 7 jours
find /path/to/nh-web/storage/app/staging -type f -mtime +7 -delete

# Vider les logs Laravel
truncate -s 0 /path/to/nh-web/storage/logs/laravel.log

# Vider les logs systemd
journalctl --vacuum-time=7d
```

---

## Annexe : variables `.env` complete

Reference des variables principales du `.env`.

| Variable | Dev | Prod | Description |
|----------|-----|------|-------------|
| `APP_NAME` | "NH Project" | "NH Project" | Nom affiche dans l'UI |
| `APP_ENV` | `local` | `production` | Mode d'execution |
| `APP_KEY` | (genere) | (genere) | Cle de chiffrement (artisan key:generate) |
| `APP_DEBUG` | `true` | `false` | Affichage des erreurs detaillees |
| `APP_URL` | `http://127.0.0.1:8000` | `https://tondomaine.com` | URL de base |
| `LOG_LEVEL` | `debug` | `warning` | Verbosite des logs |
| `DB_CONNECTION` | `pgsql` | `pgsql` | Driver BDD |
| `DB_HOST` | (Supabase) | (au choix) | Host BDD |
| `DB_PORT` | `5432` | `5432` | Port BDD |
| `DB_DATABASE` | `postgres` | `postgres` | Nom BDD |
| `DB_USERNAME` | `postgres` | `postgres` | User BDD |
| `DB_PASSWORD` | (secret) | (secret) | Password BDD |
| `DB_SSLMODE` | `require` | `require` (Supabase) ou `prefer` (local) | Mode SSL |
| `QUEUE_CONNECTION` | `database` | `database` | Driver de queue |
| `SESSION_DRIVER` | `database` | `database` | Stockage des sessions |
| `CACHE_STORE` | `database` | `database` ou `redis` | Stockage du cache |

---

## 11. Dependances et separation des repos

### Architecture des 2 repos

Le projet est decoupe en 2 repos Git distincts qui doivent vivre **sur la meme machine** :

```
nh-web/         → Laravel (ce repo)
nh-pipeline/    → Python (repo separe : https://github.com/Fanoutch/nh-pipeline)
```

Le pont entre les deux est la variable `PIPELINE_PATH` dans `nh-web/.env`, qui pointe vers le chemin absolu du repo `nh-pipeline`. Laravel appelle la pipeline via `Symfony\Process` lors de chaque upload XML.

### Variable PIPELINE_PATH

Dans `nh-web/.env` :

```env
# Chemin absolu vers le repo nh-pipeline (Python)
PIPELINE_PATH=/root/camille2/nh-pipeline      # dev local
# PIPELINE_PATH=/var/www/nh-pipeline          # prod
```

Si la variable est vide ou absente, fallback sur `base_path('..')` (compatibilite ascendante avec l'ancien layout monolithique).

### Vendor / node_modules : a quoi ca sert et quand re-installer

| Dossier | Taille | Contenu | Sur GitHub ? |
|---------|--------|---------|---------------|
| `vendor/` | ~94 Mo | Dependances PHP/Composer (Laravel, Livewire, Pest, etc.) | NON (gitignore) |
| `node_modules/` | ~85 Mo | Dependances JS/npm (Tailwind, Vite, Alpine.js) | NON (gitignore) |
| `public/build/` | ~144 Ko | Assets compiles (CSS + JS) | NON (gitignore) |

Ces dossiers sont **regenerables** depuis `composer.json` et `package.json` — c'est pour ca qu'ils ne sont jamais sur git. On push uniquement les **manifests** + leurs **lockfiles** pour garantir des versions identiques partout.

### Quand faut-il regenerer ces dossiers ?

**Cas 1 : nouveau clone du repo (autre machine, deploiement, autre dev)**

```bash
git clone https://github.com/Fanoutch/nh-web.git
cd nh-web

composer install --no-dev --optimize-autoloader   # regenere vendor/
npm ci                                             # regenere node_modules/ depuis package-lock.json
npm run build                                      # regenere public/build/

cp .env.example .env                               # creer la config locale
php artisan key:generate                           # generer APP_KEY

# Editer .env : DB_*, PIPELINE_PATH, APP_URL, APP_ENV
nano .env

php artisan migrate --force                        # creer les tables
php artisan storage:link                           # lier public/storage -> storage/app/public
```

**Cas 2 : composer.json ou package.json modifie**

```bash
composer install                                   # si nouveau package ajoute
# ou
composer update                                    # mettre a jour vers les dernieres versions compatibles
npm install
npm run build                                      # recompiler les assets
```

**Cas 3 : developpement quotidien (pas de modif des manifests)**

Rien a faire. `vendor/` et `node_modules/` sont deja la, l'app fonctionne directement.

### Pousser sur GitHub depuis un nouveau clone

```bash
cd nh-web
git remote -v                                      # voir le remote configure
git remote add origin https://github.com/Fanoutch/nh-web.git   # si absent
git push -u origin main                            # premier push
git push                                           # pushs suivants
```

### Cloner sur le serveur de prod (workflow type)

```bash
# Sur le serveur cible
mkdir -p /var/www && cd /var/www
git clone https://github.com/Fanoutch/nh-web.git
git clone https://github.com/Fanoutch/nh-pipeline.git

# Setup Laravel
cd nh-web
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env
php artisan key:generate
# Editer .env : PIPELINE_PATH=/var/www/nh-pipeline, DB_*, APP_URL, APP_ENV=production
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache

# Setup pipeline Python
cd ../nh-pipeline
python3 -m venv venv && source venv/bin/activate
pip install -r requirements.txt
deactivate

# Permissions
chown -R www-data:www-data /var/www/nh-web /var/www/nh-pipeline
chmod -R 775 /var/www/nh-web/storage /var/www/nh-web/bootstrap/cache
```

Voir aussi section **8. Deploiement nouveau serveur (prod)** pour Nginx + systemd + Let's Encrypt.

---

## 12. Changement de logo

Le logo est affiche a 2 endroits :

| Endroit | Fichier vue | Taille affichee |
|---|---|---|
| Sidebar (haut gauche) | `resources/views/layouts/sidebar.blade.php` | `h-9` (36 px) |
| Page de login | `resources/views/auth/login.blade.php` | `h-16` (64 px) |

Le fichier image pointe par defaut vers `public/images/logo-flottille-31f.png`.

### Methode 1 — Remplacer le fichier (sans toucher au code)

```bash
# Ecraser l'image existante avec la nouvelle (memes nom de fichier)
cp /chemin/vers/nouveau-logo.png /path/to/nh-web/public/images/logo-flottille-31f.png
```

Recharger la page dans le navigateur → c'est tout.

### Methode 2 — Nouveau fichier + renommer la reference

```bash
# 1. Deposer le nouveau logo
cp /chemin/vers/nouveau-logo.png /path/to/nh-web/public/images/nouveau-logo.png
```

```bash
# 2. Remplacer la reference dans les 2 vues (sed in-place)
cd /path/to/nh-web
sed -i 's|logo-flottille-31f.png|nouveau-logo.png|g' \
    resources/views/layouts/sidebar.blade.php \
    resources/views/auth/login.blade.php

# 3. Si view:cache active (prod), vider :
php artisan view:clear
```

### Specs recommandees

- **Format** : PNG avec fond transparent (evite les halos blancs/gris)
- **Resolution** : fournir x4 la taille d'affichage pour rester net en HiDPI
  - Sidebar (h-9 = 36 px) → image ~120×120 ou plus
  - Login (h-16 = 64 px) → image ~200×200 ou plus
- **Format carre ou portrait leger** preferable (le sidebar a peu de largeur)

### Crop / suppression de fond avec ImageMagick

Si l'image source a un fond uni (ex. fond gris) qu'il faut rendre transparent :

```bash
# Installer ImageMagick si besoin
apt install -y imagemagick

# Convertir un fond gris (#aaaaaa, fuzz 8%) en transparent + trim auto
cd /path/to/nh-web/public/images
convert source.jpg \
    -fuzz 8% -transparent '#aaaaaa' \
    -trim +repage \
    nouveau-logo.png

# Verifier le resultat
file nouveau-logo.png   # doit dire "PNG image data, ... 8-bit/color RGBA"
```

Adapter `'#aaaaaa'` a la couleur reelle du fond de ton image source. La valeur `fuzz` controle la tolerance (plus eleve = supprime plus de nuances proches).

### Note sur le dossier `logo/`

Le dossier `logo/` a la racine du repo est **gitignore** (cf. `.gitignore`) — c'est l'endroit pour ranger les **sources de design** (PSD, SVG, JPG haute resolution, variantes). Seul le PNG final dans `public/images/` est versionne et deploye.
