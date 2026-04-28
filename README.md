# nh-web — Frontend Laravel pour le monitoring helicoptere

Application web Laravel 12 + Livewire 3 + Blade pour visualiser, valider et reporter
les pannes detectees par la pipeline Python [`nh-pipeline`](../nh-pipeline).

## Fonctionnalites

- Upload XML drag & drop multi-fichiers
- Suivi en direct des imports (statut, erreurs)
- Liste des machines (helicopteres) avec compteurs vols / non-vols / erreurs
- Detail d'un vol avec pannes conservees / isolees
- Validation des pannes par technicien (validee / rejetee)
- Signalement de pannes manquantes
- Dashboards interactifs (ApexCharts double Y-axis : pannes / heures de vol par semaine ISO)
- Re-classement non-vol → vol (gestion des erreurs de detection)
- Auth Laravel Breeze (multi-utilisateur ready)

## Stack

- Laravel 12.56 / PHP 8.3+
- Livewire 3 (rendering server-side avec AJAX)
- Tailwind CSS + Alpine.js
- PostgreSQL (compatible Supabase)
- Pest 3 (tests)
- ApexCharts (CDN)

## Architecture

L'app appelle la pipeline Python via un Job Laravel (`ProcessXmlJob`) qui execute
`main.py --json-output` via `Symfony\Process`. Les resultats sont parses,
les vols et pannes sont insérés en base, et les agregats hebdomadaires sont
mis a jour depuis les CSV yearly produits par la pipeline.

```
Browser  ──HTTP──▶  Laravel  ──Job/queue──▶  Python pipeline (nh-pipeline)
                       │                              │
                       │                              ▼
                       │                       data/reports/yearly/*.csv
                       ▼                              │
                  PostgreSQL  ◀───────ingest─────────┘
```

Voir `docs/ARCHITECTURE.md` pour le detail.

## Prerequis

- PHP 8.3+ avec extensions : `pdo_pgsql`, `pgsql`, `mbstring`, `xml`, `zip`, `bcmath`
- Composer 2+
- Node.js 20+ avec npm
- PostgreSQL 14+ (ou Supabase)
- **Le repo `nh-pipeline` clone sur la meme machine**, avec Python 3.12+ et ses dependances

## Installation

```bash
git clone <url-nh-web> nh-web
cd nh-web

composer install --no-dev --optimize-autoloader
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

Editer `.env` :

```env
APP_URL=https://ton-domaine.com
APP_ENV=production
APP_DEBUG=false

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD="..."
DB_SSLMODE=require    # pour Supabase

# Chemin absolu vers le repo nh-pipeline
PIPELINE_PATH=/var/www/nh-pipeline

QUEUE_CONNECTION=database
```

```bash
php artisan migrate --force
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## Lancement

### Dev

```bash
php artisan serve            # http://127.0.0.1:8000
php artisan queue:work       # dans un autre terminal
npm run dev                  # dans un troisieme terminal (HMR)
```

### Prod

Voir `commandes.md` (Nginx + systemd + Let's Encrypt).

## Tests

```bash
./vendor/bin/pest
```

49 tests couvrent les Services (XmlPipelineRunner, FlightImporter, WeeklyAggregatesIngestor),
les Jobs (ProcessXmlJob), les composants Livewire (XmlUploader, ImportsTracker,
PannesConserveesTable) et les routes auth.

## Documentation

- `commandes.md` — toutes les commandes utiles (dev, deploiement, debug)
- `docs/ARCHITECTURE.md` — vue d'ensemble du systeme (web + pipeline + DB + queue)
