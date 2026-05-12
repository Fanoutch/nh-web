# Guide de déploiement — NH Project

Ce document décrit les étapes pour déployer **nh-web** (Laravel) + **nh-pipeline** (Python) sur un nouveau serveur.

> **Important** : ce repo contient uniquement le **code applicatif**. Aucune donnée métier (XMLs réels, hélicos, vols) n'est versionnée. Les XMLs de test présents en développement (sur le poste actuel) ne sont pas migrés.

---

## 1. Architecture

Le projet est composé de **2 repos séparés** qui doivent tous deux être déployés :

| Repo | Rôle | Tech |
|------|------|------|
| **nh-web** | Front + back Laravel (UI, auth, BDD, queue) | PHP 8.3, Laravel 12, Livewire 3, Tailwind, Vite |
| **nh-pipeline** | Pipeline de traitement XML (filtres, agrégats, recurrent failures) | Python 3.10+ |

Le worker Laravel (`queue:work`) appelle la pipeline Python en sous-processus via `XmlPipelineRunner`. Les deux repos partagent le serveur de fichiers (les CSV/JSON produits par la pipeline sont lus par Laravel depuis `nh-pipeline/data/`).

---

## 2. Prérequis serveur

| Composant | Version min |
|---|---|
| PHP | ≥ 8.3 (avec extensions : pdo_pgsql, mbstring, xml, gd, zip, bcmath) |
| Composer | ≥ 2.6 |
| Node.js | ≥ 20 |
| Python | ≥ 3.10 |
| PostgreSQL | ≥ 14 (ou Supabase cloud) |
| Nginx | ≥ 1.18 (ou Apache) |
| Supervisord | pour maintenir le queue worker actif |

---

## 3. Cloner les 2 repos

```bash
# Frontend Laravel
git clone https://github.com/Fanoutch/nh-web.git /var/www/nh-web

# Pipeline Python (URL à confirmer selon où tu pushes nh-pipeline)
git clone https://github.com/Fanoutch/nh-pipeline.git /var/www/nh-pipeline
```

Garder les **2 repos au même niveau** (`/var/www/nh-web` et `/var/www/nh-pipeline`) ou ajuster `services.pipeline.path` dans le `.env` (voir étape 5).

---

## 4. Installer les dépendances

### Côté nh-web

```bash
cd /var/www/nh-web
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

### Côté nh-pipeline

```bash
cd /var/www/nh-pipeline
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
deactivate
```

Vérifier que `python3` (ou `python`) est dans le PATH système — Laravel l'appelle via `Symfony\Process`.

---

## 5. Configurer `.env`

```bash
cd /var/www/nh-web
cp .env.example .env
php artisan key:generate
```

Éditer `.env` avec les credentials prod :

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ton-domaine.fr

DB_CONNECTION=pgsql
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
DB_SSLMODE=require   # si Supabase

QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Chemin vers le repo nh-pipeline cloné
PIPELINE_PATH=/var/www/nh-pipeline
```

---

## 6. Migrations BDD

```bash
cd /var/www/nh-web
php artisan migrate --force
php artisan storage:link
```

Cela recrée toutes les tables : `users`, `machines`, `flights`, `technical_events`, `imports`, `weekly_aggregates`, `recurrent_failures`, `missing_pannes`, `activity_log`, `sessions`, `cache`, `jobs`, etc. Le `storage:link` recrée le lien symbolique `public/storage → storage/app/public` (gitignored, doit être recréé à chaque clone).

**La base est maintenant vide.** Aucune donnée métier — les premiers vols seront injectés via `/upload` après mise en service.

---

## 7. Créer le premier super admin via tinker

```bash
php artisan tinker
```

```php
$user = \App\Models\User::create([
    'name' => 'Admin Principal',
    'email' => 'admin@ton-domaine.fr',
    'password' => bcrypt('PASSWORD_FORT_ICI'),
    'is_admin' => true,
    'is_super_admin' => true,
]);
exit
```

Cet utilisateur peut ensuite promouvoir d'autres comptes via `/admin/users`.

---

## 8. Permissions du dossier storage

```bash
chown -R www-data:www-data /var/www/nh-web/storage /var/www/nh-web/bootstrap/cache
chmod -R 775 /var/www/nh-web/storage /var/www/nh-web/bootstrap/cache
```

---

## 9. Vhost nginx

Exemple `/etc/nginx/sites-available/nh-web` :

```nginx
server {
    listen 80;
    server_name ton-domaine.fr;
    root /var/www/nh-web/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }
}
```

```bash
ln -s /etc/nginx/sites-available/nh-web /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

Pour HTTPS : utiliser certbot ou cloudflare proxy.

---

## 10. Worker de queue (Supervisord)

Le pipeline est lancé en arrière-plan via la queue Laravel. Sans worker, les uploads XML restent bloqués en `pending` indéfiniment.

`/etc/supervisor/conf.d/nh-web-worker.conf` :

```ini
[program:nh-web-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/nh-web/artisan queue:work --sleep=3 --tries=1 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/nh-web-worker.log
stopwaitsecs=3600
```

```bash
supervisorctl reread
supervisorctl update
supervisorctl start nh-web-queue:*
```

---

## 11. Test de fumée

1. Accéder à `https://ton-domaine.fr/login`
2. Login avec le super admin créé en étape 7 → `/machines` doit afficher "Aucune machine en base"
3. Aller sur `/upload`, déposer un XML hélicoptère réel
4. Aller sur `/imports` → la ligne doit passer en `Processing` puis `OK`
5. Retour sur `/machines` → le HcId apparaît avec ses compteurs
6. `/admin/audit-log` → vérifie qu'aucune erreur pipeline n'est listée

Si `Processing` reste bloqué : le worker queue ne tourne pas. Vérifier `supervisorctl status`.

Si erreur visible dans `/admin/audit-log` (module `pipeline`) : les détails de l'erreur sont dans la colonne "Changements". Causes courantes :
- Python introuvable → ajuster le PATH
- Permissions sur `nh-pipeline/data/` → `chmod -R 775`
- XML mal formé → vérifier le fichier source

---

## 12. Mise à jour applicative (pull du repo)

```bash
cd /var/www/nh-web
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
supervisorctl restart nh-web-queue:*
```

---

## 13. Pendant le développement (rappel)

Les fichiers suivants sont **gitignored** et **ne seront pas présents** sur le serveur de prod :

- `.env` — secrets, généré per-machine via `.env.example`
- `vendor/`, `node_modules/` — recréés par `composer install` / `npm ci`
- `public/build/` — recréé par `npm run build`
- `storage/app/data/`, `storage/app/staging_*` — XMLs traités en local, fausses données
- `storage/app/private/`, `storage/app/public/` — uploads runtime
- `.playwright-mcp/`, `/*.png` à la racine — outputs des tests visuels Playwright
- `/logo/` — assets sources de design (le PNG final est dans `public/images/`)

---

## 14. Données métier après mise en service

La BDD est vide après `php artisan migrate`. Les **données réelles** seront créées dans cet ordre :

1. **Users** : super admin via tinker (étape 7), puis promotions via `/admin/users`.
2. **Machines** : créées automatiquement par le pipeline lors du 1er XML uploadé pour chaque hélico (`Machine::firstOrCreate(['hc_id' => ...])`).
3. **Flights, technical_events** : créés par `FlightImporter` à chaque XML traité.
4. **Weekly aggregates, recurrent failures** : créés par les ingestors associés.

Les XMLs de test présents sur le poste de développement (`storage/app/staging_*`, etc.) ne sont **pas** migrés vers la prod.

---

## Annexes

- Architecture détaillée : `docs/ARCHITECTURE.md`
- Specs/plans du redesign : `docs/superpowers/`
- Commandes utiles dev : `commandes.md`
