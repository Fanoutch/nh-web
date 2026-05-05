# Commandes — NH Project

Reference complete des commandes Laravel, serveur et deploiement.

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

---

## 1. Developpement local

### Lancer l'app pour developper

```bash
cd /root/camille2/nh_project/web

# Terminal 1 : serveur web
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2 : worker (pour traiter les uploads XML)
php artisan queue:work --tries=1 --timeout=600
```

Acces : http://127.0.0.1:8000

### Creer un user

```bash
cd /root/camille2/nh_project/web

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
nano /root/camille2/nh_project/web/.env

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

```bash
cd /root/camille2/nh_project

python3 -m pytest tests/ -v
python3 -m pytest tests/test_main_json_output.py
```

---

## 6. Pipeline Python

```bash
cd /root/camille2/nh_project

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

# Generer les dashboards PNG (legacy, optionnel)
python3 dashboard.py
```

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

Etapes complete pour deployer sur un nouveau serveur (Hetzner, OVH, AWS EC2, etc.).
Suppose serveur Ubuntu 22.04+ avec acces SSH root.

### 8.1 Pre-requis serveur

```bash
ssh root@ip_serveur

# Mettre a jour le systeme
apt update && apt upgrade -y

# Installer PHP 8.3 + extensions
apt install -y php8.3-cli php8.3-fpm php8.3-pgsql php8.3-xml \
               php8.3-mbstring php8.3-curl php8.3-zip php8.3-bcmath \
               php8.3-intl

# Installer Composer
cd /tmp && curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Installer Node.js 20 + npm
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Installer Python 3
apt install -y python3 python3-pip python3-venv

# Installer Nginx
apt install -y nginx

# Installer PostgreSQL (uniquement si tu n'utilises PAS Supabase)
apt install -y postgresql postgresql-contrib

# Installer Git
apt install -y git unzip
```

### 8.2 Cloner le repo

```bash
mkdir -p /var/www
cd /var/www
git clone https://github.com/ton-user/nh_project.git
cd nh_project
```

### 8.3 Installer les dependances

```bash
# Pipeline Python
pip3 install -r requirements.txt --break-system-packages

# Laravel
cd web
composer install --no-dev --optimize-autoloader

# Frontend assets
npm install
npm run build
```

### 8.4 Configurer .env de prod

```bash
cd /var/www/nh_project/web
cp .env.example .env
nano .env
```

Contenu du `.env` prod :
```env
APP_NAME="NH Project"
APP_ENV=production
APP_KEY=                              # genere a l'etape suivante
APP_DEBUG=false
APP_URL=https://tondomaine.com

LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=db.xxx.supabase.co            # ou 127.0.0.1 si PG local
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=postgres
DB_PASSWORD="ton_password_prod"
DB_SSLMODE=require                    # require pour Supabase, prefer sinon

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

### 8.5 Generer la cle d'app + migrations

```bash
php artisan key:generate           # remplit APP_KEY automatiquement
php artisan migrate --force        # cree les 15 tables sur la BDD prod
```

### 8.6 Optimiser pour la prod

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 8.7 Permissions

```bash
chown -R www-data:www-data /var/www/nh_project
chmod -R 755 /var/www/nh_project
chmod -R 775 /var/www/nh_project/web/storage /var/www/nh_project/web/bootstrap/cache
```

### 8.8 Configurer Nginx

```bash
nano /etc/nginx/sites-available/nh-project
```

Contenu :
```nginx
server {
    listen 80;
    server_name tondomaine.com www.tondomaine.com;
    root /var/www/nh_project/web/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    client_max_body_size 60M;   # pour upload de XML jusqu'a 50 Mo
}
```

Activer + reload :
```bash
ln -s /etc/nginx/sites-available/nh-project /etc/nginx/sites-enabled/
rm /etc/nginx/sites-enabled/default
nginx -t                                 # tester la config
systemctl reload nginx
```

### 8.9 SSL avec Let's Encrypt

```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d tondomaine.com -d www.tondomaine.com
# Suivre les instructions, certbot configure automatiquement HTTPS dans Nginx

# Verifier le renouvellement auto
certbot renew --dry-run
```

### 8.10 Service systemd pour le queue worker

```bash
nano /etc/systemd/system/nh-queue.service
```

Contenu :
```ini
[Unit]
Description=NH Project Laravel Queue Worker
After=network.target nginx.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/nh_project/web
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=600 --sleep=3
Restart=always
RestartSec=5
StandardOutput=append:/var/log/nh-queue.log
StandardError=append:/var/log/nh-queue.log

[Install]
WantedBy=multi-user.target
```

Activer + demarrer :
```bash
systemctl daemon-reload
systemctl enable nh-queue.service
systemctl start nh-queue.service
systemctl status nh-queue.service
```

### 8.11 Creer le premier compte admin

```bash
cd /var/www/nh_project/web
sudo -u www-data php artisan tinker
>>> \App\Models\User::create([
...     'name' => 'Admin',
...     'email' => 'admin@tondomaine.com',
...     'password' => \Hash::make('mot_de_passe_fort'),
... ]);
>>> exit
```

Ou via la page `/register` directement.

### 8.12 Verifier

```bash
# Tester l'app
curl -I https://tondomaine.com
# -> doit repondre HTTP 200 ou 302

# Tester un upload manuel via tinker
sudo -u www-data php artisan tinker
>>> \App\Jobs\ProcessXmlJob::dispatch(...);
```

Visiter `https://tondomaine.com` dans un navigateur, se connecter, uploader un XML.

---

## 9. Mises a jour en prod

Workflow standard apres une modification du code en local :

### Cote local (ton PC)

```bash
cd /root/camille2/nh_project
git add .
git commit -m "fix: ..."
git push origin main
```

### Cote serveur prod

```bash
ssh root@ip_serveur
cd /var/www/nh_project

# Recuperer le code
git pull

# Si nouvelles dependances PHP
cd web
sudo -u www-data composer install --no-dev --optimize-autoloader

# Si nouvelles dependances JS / CSS modifies
sudo -u www-data npm install
sudo -u www-data npm run build

# Si nouvelles migrations
sudo -u www-data php artisan migrate --force

# Rebuild les caches
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# Si requirements.txt Python a change
pip3 install -r ../requirements.txt --break-system-packages

# Relancer le worker
systemctl restart nh-queue.service
```

### Script tout-en-un (a creer une fois sur le serveur)

```bash
nano /var/www/nh_project/deploy.sh
```

```bash
#!/bin/bash
set -e
cd /var/www/nh_project

echo "==> Pulling code..."
git pull

echo "==> Installing PHP deps..."
cd web
sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Building frontend..."
sudo -u www-data npm install
sudo -u www-data npm run build

echo "==> Running migrations..."
sudo -u www-data php artisan migrate --force

echo "==> Rebuilding caches..."
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "==> Restarting queue worker..."
systemctl restart nh-queue.service

echo "==> Done!"
```

```bash
chmod +x /var/www/nh_project/deploy.sh

# Pour deployer ensuite :
ssh root@ip_serveur '/var/www/nh_project/deploy.sh'
```

---

## 10. Troubleshooting

### Le site renvoie 500

```bash
# Voir les logs
tail -50 /var/www/nh_project/web/storage/logs/laravel.log
tail -50 /var/log/nginx/error.log

# Causes frequentes :
# - Permissions manquantes sur storage/ ou bootstrap/cache/
chown -R www-data:www-data /var/www/nh_project/web/storage /var/www/nh_project/web/bootstrap/cache

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
cat /var/www/nh_project/web/.env | grep DB_

# Vider le cache de config (souvent oublie)
php artisan config:clear
```

### Pipeline Python echoue

```bash
# Tester en standalone
cd /var/www/nh_project
python3 main.py raw/test.xml --output-base /tmp/test --json-output

# Voir les logs du Job
tail -f /var/log/nh-queue.log

# Verifier les permissions
ls -la /var/www/nh_project/data/
chown -R www-data:www-data /var/www/nh_project/data
```

### Liberer de l'espace disque

```bash
# Nettoyer les fichiers staging vieux de plus de 7 jours
find /var/www/nh_project/web/storage/app/staging -type f -mtime +7 -delete

# Vider les logs Laravel
truncate -s 0 /var/www/nh_project/web/storage/logs/laravel.log

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
