# Frontend web Laravel — Design

**Date** : 2026-04-15
**Projet** : NH Project — interface web pour la pipeline de surveillance helicoptere
**Statut** : design valide, pret pour redaction du plan d'implementation

## 1. Objectif

Construire une interface web permettant a des techniciens et analystes de :

- Consulter les machines (helicopteres) et leurs vols.
- Telecharger et traiter de nouveaux fichiers XML HUMS via la pipeline Python existante.
- Inspecter les pannes conservees et isolees de chaque vol.
- Valider / rejeter les pannes detectees (roles technicien) et signaler des pannes manquantes.
- Consulter des dashboards hebdomadaires interactifs (pannes + heures de vol), version web du script `dashboard.py`.

Le site est developpe et teste en **local** ; l'hebergement final sera mis en place plus tard. Les tables de la base de donnees sont creees automatiquement via les migrations Laravel au deploiement.

## 2. Stack technique

- **Framework** : Laravel 12
- **Vue** : Blade + **Livewire 3** (interactivite sans build JS lourd)
- **Base de donnees** : PostgreSQL (colonnes `jsonb` pour les details de pannes)
- **Auth** : Laravel Breeze (email / mot de passe, un seul compte en local, multi-user pret)
- **Queue** : driver `database` (pas de Redis en local)
- **Pipeline Python** : inchangee, appelee via `Symfony\Process` depuis un Job Laravel
- **Graphiques** : ApexCharts (double axe Y, interactif)
- **Migrations** : `php artisan migrate` au deploiement cree l'ensemble du schema
- **Environnement local** : `php artisan serve` + `php artisan queue:work`

## 3. Architecture generale

```
+-----------------------------+
|          Browser            |
| Livewire + Blade + ApexCharts
+--------------+--------------+
               |
               v
+-----------------------------+
|       Laravel 12            |
|  Controllers / Livewire /   |
|  Routes / Breeze auth       |
+--------------+--------------+
               |
     +---------+---------+
     |                   |
     v                   v
+-----------+      +-------------+
| PostgreSQL|      |  Queue (DB) |
+-----------+      +------+------+
                          |
                          v
                 +---------------------+
                 | ProcessXmlJob       |
                 | Symfony\Process     |
                 +----------+----------+
                            |
                            v
                 +---------------------+
                 | Pipeline Python     |
                 | main.py / run.py    |
                 | (inchangee)         |
                 +----------+----------+
                            |
                            v
                 +---------------------+
                 | Fichiers sur disque |
                 | data/clean_xml/...  |
                 | data/xml_isole/...  |
                 +----------+----------+
                            |
                            v
                 Lecture JSON -> INSERT BDD
                 (dans le Job, transaction)
```

**Principe** : la pipeline Python reste autonome (on peut toujours la lancer en CLI). Laravel l'appelle via un Job, lit les JSON de sortie, et insere en BDD. Aucune modification du code Python, sauf l'ajout d'une option `--json-output` sur `main.py` qui imprime un resume JSON final sur stdout pour faciliter le parsing cote Laravel.

## 4. Schema base de donnees

Toutes les tables sont creees par migrations Laravel.

### 4.1 `users` (Breeze standard)

- `id`, `name`, `email`, `password`, `email_verified_at`, `remember_token`, timestamps.

### 4.2 `machines`

| Colonne    | Type           | Notes                    |
|------------|----------------|--------------------------|
| id         | bigint PK      |                          |
| hc_id      | string unique  | ex: "NH08"               |
| timestamps |                |                          |

### 4.3 `flights`

| Colonne               | Type                | Notes                                                |
|-----------------------|---------------------|------------------------------------------------------|
| id                    | bigint PK           |                                                      |
| machine_id            | FK machines         |                                                      |
| dsn                   | string              | identifiant DSN du vol                               |
| num                   | string              | numero unique XML (ex: "612")                        |
| start_datetime        | timestamp           | `<StartDateTime>`                                    |
| end_datetime          | timestamp           | `<EndDateTime>`                                      |
| flight_type           | string              | ex: "FLIGHT", "GROUND"                               |
| flight_hours          | decimal(10,4)       | converti en heures (XML en secondes / 3600)         |
| consumed_fuel         | decimal(10,2) null  |                                                      |
| is_non_vol            | bool                | auto via `flight_type != 'FLIGHT'`                   |
| flagged_as_error      | bool default false  | passe a true si user clique "C'est un vol"           |
| flagged_at            | timestamp null      |                                                      |
| flagged_by            | FK users null       |                                                      |
| xml_path              | string              | chemin relatif vers `xml_epure.xml`                  |
| processed_at          | timestamp           |                                                      |
| timestamps            |                     |                                                      |

**Unique** : `(machine_id, dsn, num)` (detection doublon cote BDD).
**Index** : `(machine_id, start_datetime)` pour tri chronologique.

### 4.4 `technical_events`

| Colonne              | Type               | Notes                                          |
|----------------------|--------------------|------------------------------------------------|
| id                   | bigint PK          |                                                |
| flight_id            | FK flights         |                                                |
| technical_event_id   | string             | ID du XML                                      |
| raise_datetime       | timestamp          | `RaiseDateTime`                                |
| status               | enum               | `conservee` / `isolee`                         |
| iso_week             | string             | ex: "2026-W05" (denormalise pour dashboards)   |
| nombre_occurrences   | int default 1      | pour pannes dedupliquees                       |
| details              | jsonb              | description, systeme, Lcn, FailureCode, etc.   |
| validation_status    | enum               | `pending` / `validated` / `rejected`           |
| validated_by         | FK users null      |                                                |
| validated_at         | timestamp null     |                                                |
| technician_comment   | text null          |                                                |
| timestamps           |                    |                                                |

**Index** : `(flight_id)`, `(iso_week)`.

### 4.5 `missing_pannes`

Pannes signalees manuellement par les techniciens comme absentes du vol.

| Colonne       | Type        | Notes                     |
|---------------|-------------|---------------------------|
| id            | bigint PK   |                           |
| flight_id     | FK flights  |                           |
| failure_code  | string      | obligatoire               |
| description   | text null   |                           |
| comment       | text null   |                           |
| reported_by   | FK users    |                           |
| reported_at   | timestamp   |                           |
| timestamps    |             |                           |

### 4.6 `weekly_aggregates`

Miroir BDD des CSV yearly generes par la pipeline (`data/reports/yearly/{HcId}/{HcId}_{year}.csv` et `data/FHreport/yearly/{HcId}/{HcId}_{year}.csv`). Source de verite pour les dashboards.

| Colonne              | Type                    | Notes                                          |
|----------------------|-------------------------|------------------------------------------------|
| id                   | bigint PK               |                                                |
| machine_id           | FK machines             |                                                |
| year                 | smallint                | ex: 2026                                       |
| iso_week             | string                  | ex: "2026-W05"                                 |
| total_pannes         | int default 0           | depuis `reports/yearly/{HcId}/*.csv`           |
| total_flight_hours   | decimal(10,4) default 0 | depuis `FHreport/yearly/{HcId}/*.csv`          |
| timestamps           |                         |                                                |

**Unique** : `(machine_id, iso_week)`.
**Index** : `(machine_id, year)`.

### 4.7 `imports`

Suivi des uploads et du traitement par la pipeline.

| Colonne      | Type          | Notes                                                                        |
|--------------|---------------|------------------------------------------------------------------------------|
| id           | bigint PK     |                                                                              |
| user_id      | FK users      |                                                                              |
| filename     | string        | nom original du XML                                                          |
| status       | enum          | `pending` / `processing` / `ok` / `already_processed` / `non_vol` / `error`  |
| result       | jsonb         | hc_id, dsn, num, message, pannes_conservees_count, flight_hours, ...         |
| flight_id    | FK flights null | vers le vol cree (si applicable)                                           |
| timestamps   |               |                                                                              |

## 5. Structure des pages et routes

Layout global : header (logo, utilisateur, logout), sidebar (Machines / Upload / Imports / Dashboards).

### 5.1 `/login` — Breeze standard

### 5.2 `/machines` — liste des machines

- Carte par machine : `hc_id`, nb vols, nb non-vols, nb erreurs, derniere date de vol.
- Clic sur carte -> `/machines/{hc_id}`.

### 5.3 `/machines/{hc_id}` — detail machine (3 onglets)

- **Vols** : table paginee (date, DSN, num, type, FH, nb pannes conservees). Clic ligne -> `/flights/{id}`.
- **Non vols** : table (date, DSN, num, type). Clic ligne -> `/flights/{id}/non-vol`.
- **Erreurs** : non-vols signales comme etant des vols par un utilisateur. Table (date, DSN, num, signale par, signale le). Clic ligne -> `/flights/{id}/non-vol`.

Filtrage des onglets :

- Vols = `is_non_vol = false`
- Non vols = `is_non_vol = true` AND `flagged_as_error = false`
- Erreurs = `flagged_as_error = true`

### 5.4 `/flights/{id}` — detail d'un vol

Header metadonnees : HcId, DSN, num, type, StartDateTime, EndDateTime, **duree de vol** (heures + minutes), carburant consomme.

Bouton "Telecharger XML epure" (via route Laravel qui stream le fichier depuis `xml_path`).

Rubrique **Pannes du vol** : 2 cartes cliquables :

- **Pannes conservees (N)** -> `/flights/{id}/pannes-conservees`
- **Pannes isolees (M)** -> `/flights/{id}/pannes-isolees`

### 5.5 `/flights/{id}/pannes-conservees` — vue interactive

Tableau Livewire (tri, recherche) avec colonnes :

- **Description** (`TechnicalEventDescription` ou `TEDescription`)
- **Failure Code** (`FailureCode`)
- **Heure du vol** (rappel du `start_datetime` -> `end_datetime` affiche en tete, pas dans chaque ligne)
- **Failure Start Time** (`RaiseDateTime`)
- **Occurrences** (si > 1)
- **Validation technicien** (3 etats) : `non traitee` / `validee` / `rejetee`, via boutons sur la ligne
- **Commentaire** (textarea optionnelle)

Clic sur une ligne -> panneau lateral avec **tous les champs** du JSON (SystemType, Lcn, StatusId, TypeDescription, AlarmDescription, FailureKind, etc.) pour inspection complete.

**Bouton "Signaler une panne manquante"** en haut :

- Modale Livewire avec champs :
  - Failure Code (obligatoire)
  - Description (optionnel)
  - Commentaire (optionnel)
- Soumission -> insert dans `missing_pannes`.
- Section dediee sous le tableau : liste des pannes manquantes signalees pour ce vol (Failure Code, date, auteur, bouton supprimer si auteur).

Note d'implementation : si `FailureStartTime` existe comme champ XML distinct de `RaiseDateTime` et n'est pas encore extrait par `date_filter.py`, l'ajouter a l'extraction pendant l'implementation. Sinon utiliser `RaiseDateTime`.

### 5.6 `/flights/{id}/pannes-isolees` — vue simple

Tableau : ID panne, `RaiseDateTime`, ecart au vol, raison d'isolement. Lecture seule.

### 5.7 `/flights/{id}/non-vol` — detail d'un non vol

- Metadonnees (HcId, DSN, num, type, dates, duree).
- Lien vers le XML (`xml_epure.xml`).
- Si `flagged_as_error = false` : bouton **"C'est un vol"**.
  - Clic -> `POST /flights/{id}/flag-as-error` : met `flagged_as_error = true`, `flagged_at = now()`, `flagged_by = user->id`. Le vol bascule dans l'onglet "Erreurs". **Aucun retraitement pipeline** : c'est un cas a analyser manuellement par l'OT.
- Si `flagged_as_error = true` : badge "Signale comme erreur par {user} le {date}", pas de bouton.

### 5.8 `/upload` — upload XML (staging + validation)

**Etape 1 — Staging (Livewire drag & drop)** :

- Utilisateur depose N fichiers XML.
- Chaque fichier est uploade en AJAX vers `storage/app/staging/{uuid}/{filename}.xml`.
- Barre de progression par fichier.
- Liste affichee : ✓ / ✗ / en cours, avec bouton "retirer".
- Utilisateur peut ajouter d'autres fichiers et en retirer librement.

**Etape 2 — Validation** :

- Bouton "Traiter N fichiers".
- Pour chaque fichier staging : creation d'une ligne `imports` (`status = pending`), dispatch d'un `ProcessXmlJob`.
- Redirection vers `/imports`.

### 5.9 `/imports` — suivi temps reel

- Table Livewire rafraichie par polling 2s.
- Colonnes : filename, status (badge colore), machine, DSN, num, message.
- Filtres : en cours / termines / erreurs.
- Toasts de notification :
  - ✓ "XML {hc_id} — DSN {dsn} — n°{num} traite ({N} pannes conservees, {FH}h)"
  - ⚠ "XML {hc_id} — DSN {dsn} — n°{num} detecte comme non vol"
  - ⚠ "XML {hc_id} — DSN {dsn} — n°{num} deja present en base"
  - ✗ "XML {filename} : erreur de traitement ({message})"

### 5.10 `/dashboards` — visualisation interactive (pas d'export)

Reproduit le rendu de `dashboard.py` mais avec filtre temporel libre.

**Etat initial** : a l'arrivee sur la page, aucun graphique affiche. Un formulaire demande :

- **Machine** (dropdown, liste des HcId en BDD)
- **Plage de temps** (date debut -> date fin, via deux date pickers)
- Bouton **"Afficher le dashboard"**

Tant que les trois champs ne sont pas remplis, le bouton est desactive et aucun graphique n'apparait.

**Apres validation** : le graphique ApexCharts s'affiche sous le formulaire. L'utilisateur peut modifier la machine ou la plage puis re-valider pour rafraichir.

**Graphique ApexCharts** :

- Axe X : semaines ISO entre la date debut et la date fin (converties en semaines ISO `YYYY-Www`).
- Axe Y gauche (bleu) : nombre de pannes par semaine (`total_pannes`).
- Axe Y droit (rouge) : heures de vol par semaine (`total_flight_hours`).
- Zones "NO DATA" hachurees sur les semaines sans donnees dans la plage choisie.
- Tooltip au survol, zoom/pan.
- Titre : "Pannes & Heures de vol — {HcId} — {date_debut} -> {date_fin}".

Source des donnees : une requete SQL sur `weekly_aggregates` filtree par `machine_id` et `iso_week BETWEEN :week_start AND :week_end`. Les chaines `YYYY-Www` sont lexicographiquement triables, le BETWEEN fonctionne directement.

## 6. Flow d'import detaille

### 6.1 Staging

1. Utilisateur depose N fichiers XML sur `/upload`.
2. Chaque fichier -> upload AJAX -> `storage/app/staging/{uuid}/{filename}.xml`.
3. Livewire suit la progression et met a jour la liste.

### 6.2 Validation

1. Bouton "Traiter N fichiers".
2. Pour chaque fichier :
   - INSERT ligne `imports` (`status = pending`).
   - Dispatch `ProcessXmlJob` avec id de l'import et chemin staging.
3. Redirection vers `/imports`.

### 6.3 `ProcessXmlJob`

```
1. imports.status = processing
2. exec : python3 main.py <staging_path> --output-base <storage/app/data> --json-output
3. Parse stdout -> JSON resume :
   { "status": "ok|already_processed|no_engine|error",
     "hc_id": "...", "dsn": "...", "num": "...",
     "output_dir": "...", "message": "..." }
4. Selon status :
   a) ok :
      - Lire {output_dir}/rapport_moteur.json -> extraire metadonnees vol
      - firstOrCreate machine (hc_id)
      - INSERT flight (is_non_vol calcule via flight_type != 'FLIGHT')
      - Lire pannes_conservees.json -> boucle INSERT technical_events (status=conservee, iso_week calcule)
      - Lire pannes_isolees.json -> boucle INSERT technical_events (status=isolee)
      - Ingestion agregats yearly (voir 6.5) :
          * Lire data/reports/yearly/{hc_id}/{hc_id}_{year}.csv -> UPSERT weekly_aggregates (total_pannes)
          * Lire data/FHreport/yearly/{hc_id}/{hc_id}_{year}.csv -> UPSERT weekly_aggregates (total_flight_hours)
            en ignorant la ligne "TOTAL {year}"
      - imports.status = ok, imports.flight_id = flight.id
      - imports.result = { hc_id, dsn, num, pannes_conservees_count, flight_hours }
   b) already_processed :
      - imports.status = already_processed
      - imports.result = { hc_id, dsn, num }
   c) no_engine (= non vol) :
      - Lire rapport_moteur.json dans xml_isole/...
      - firstOrCreate machine
      - INSERT flight (is_non_vol = true)
      - imports.status = non_vol, imports.flight_id = flight.id
      - imports.result = { hc_id, dsn, num }
   d) error :
      - imports.status = error
      - imports.result = { message: stderr }
5. Tout le bloc INSERT fait dans DB::transaction().
6. Broadcast d'un event Livewire pour rafraichir /imports.
```

### 6.4 Adaptation minimale de `main.py`

Ajout d'une option `--json-output` qui imprime sur stdout un JSON final :

```json
{"status":"ok","hc_id":"NH08","dsn":"...","num":"612","output_dir":"data/clean_xml/NH08/2026/02/W05/NH08_2026-02-01_612"}
```

Pour les autres statuts : `already_processed`, `no_engine`, `error` (avec `message` en cas d'erreur).

Cela evite que le Job PHP doive deviner la structure de sortie.

### 6.5 Ingestion des agregats yearly

Apres chaque vol traite avec succes :

1. Determiner l'annee ISO du vol (`year = EXTRACT(ISOYEAR FROM start_datetime)`).
2. Lire `data/reports/yearly/{hc_id}/{hc_id}_{year}.csv` :
   - Entetes : `semaine, total_pannes`.
   - Pour chaque ligne : UPSERT sur `weekly_aggregates` avec `(machine_id, iso_week)` comme cle, `total_pannes` mis a jour, `year` renseigne.
3. Lire `data/FHreport/yearly/{hc_id}/{hc_id}_{year}.csv` :
   - Entetes : `semaine, total_flight_hours`.
   - Ignorer la ligne `semaine = "TOTAL {year}"` (agregat annuel, pas une semaine ISO).
   - Pour chaque autre ligne : UPSERT sur `(machine_id, iso_week)`, mettre a jour `total_flight_hours`.
4. Les UPSERT preservent les colonnes non concernees (ex: UPSERT FH ne touche pas `total_pannes` et vice versa). Si une ligne n'existe pas encore dans `weekly_aggregates`, elle est creee avec la valeur par defaut pour l'autre colonne (0).

Raison du choix yearly plutot que weekly : les CSV yearly sont deja les agregats hebdomadaires pour l'annee entiere (1 ligne par semaine ISO). Pas besoin d'aller lire chaque dossier `weekly/{HcId}_{semaine}/` individuellement.

### 6.6 Gestion des doublons

Pas de pre-check cote Laravel : on envoie tous les XML a la pipeline. La detection de doublon est faite cote Python (logique `is_already_processed()` dans `run.py`). Le Job recoit `status = already_processed` et notifie l'utilisateur avec le HcId + DSN + num.

### 6.7 Action "C'est un vol" sur un non vol

- Route `POST /flights/{id}/flag-as-error`.
- Middleware auth.
- Met a jour `flagged_as_error = true`, `flagged_at = now()`, `flagged_by = user->id`.
- Pas de retraitement pipeline : le vol va dans l'onglet Erreurs pour analyse manuelle.

## 7. Dashboards — requetes SQL

### 7.1 Donnees hebdomadaires (pannes + FH) sur une plage libre

Une seule requete sur `weekly_aggregates` (miroir des CSV yearly de la pipeline). L'utilisateur choisit machine + plage (date debut / date fin), converties cote PHP en semaines ISO (`YYYY-Www`) :

```sql
SELECT iso_week, total_pannes, total_flight_hours
FROM weekly_aggregates
WHERE machine_id = :machine_id
  AND iso_week BETWEEN :week_start AND :week_end
ORDER BY iso_week
```

Les chaines `YYYY-Www` sont lexicographiquement triables, le BETWEEN fonctionne directement sans conversion de type.

Les donnees sont strictement identiques a celles lues par `dashboard.py` depuis les CSV yearly. Pas de recalcul, pas de risque de divergence. La plage libre permet zoom court (quelques semaines) ou long (plusieurs annees).

### 7.2 Librairie graphique

**ApexCharts vanilla** (CDN ou npm), pas de wrapper PHP/Livewire. Raisons :

- Double axe Y natif et flexible.
- Annotations custom (`annotations.xaxis`) pour les zones "NO DATA" hachurees.
- Zoom/pan/tooltip fournis nativement.
- Les wrappers (`livewire-charts`, `larapex-charts`) brident les annotations custom et sont moins maintenus.

Integration : un composant Livewire `DashboardChart` expose les donnees au format JSON via une propriete publique ; la Blade charge `apexcharts` et initialise le chart avec ces donnees au rendu et a chaque mise a jour Livewire.

### 7.3 Zones "NO DATA"

Cote PHP, apres recuperation des lignes de `weekly_aggregates` :

1. Identifier les semaines presentes (union : `total_pannes > 0` OR `total_flight_hours > 0`).
2. Generer toutes les semaines ISO entre la premiere et la derniere presentes.
3. Marquer les semaines absentes -> envoyees a ApexCharts comme `annotations.xaxis` avec fond rouge transparent et label "NO DATA" (meme comportement visuel que `dashboard.py`).

## 8. Decoupage en modules Laravel

Pour garder des fichiers focalises :

- **Controllers** : `MachineController`, `FlightController`, `NonVolController`, `DashboardController`, `XmlDownloadController`.
- **Livewire** :
  - `XmlUploader` (staging + validation sur `/upload`)
  - `ImportsTracker` (tableau temps reel sur `/imports`)
  - `PannesConserveesTable` (tableau interactif + validation + signalement panne manquante)
  - `DashboardChart` (chart ApexCharts avec controles)
- **Jobs** : `ProcessXmlJob` (avec sous-methodes privees par statut pour lisibilite).
- **Services** :
  - `XmlPipelineRunner` : encapsule l'appel `Symfony\Process` a `main.py`.
  - `FlightImporter` : lit les JSON et insere en BDD (transaction).
  - `WeeklyAggregatesIngestor` : lit les CSV yearly et UPSERT dans `weekly_aggregates`.
- **Models** : `User`, `Machine`, `Flight`, `TechnicalEvent`, `MissingPanne`, `Import`, `WeeklyAggregate`.

Chaque composant a une responsabilite unique, communique par interfaces claires, et peut etre teste independamment.

## 9. Ce qui est hors scope

- Roles (admin / viewer) : Breeze simple sans permissions pour l'instant. A ajouter plus tard quand plusieurs users seront necessaires avec des droits differencies.
- Export PNG des dashboards : retire explicitement, visualisation en ligne uniquement.
- Modification du schema de la pipeline Python au-dela de l'ajout de `--json-output`.
- Migration BDD directe depuis la pipeline Python (Approche 2 rejetee).
- Hebergement / deploiement : tests en local uniquement pour l'instant.
- Notification de `missing_pannes` dans les dashboards : ces pannes sont declaratives, pas comptees.

## 10. Points a verifier pendant l'implementation

- **`FailureStartTime`** : verifier si ce champ existe comme attribut XML distinct de `RaiseDateTime`. Si oui, l'extraire dans `date_filter.py` et l'exposer dans `pannes_conservees.json`.
- **Workers queue** : documenter la commande `php artisan queue:work` dans le README pour le lancement local.
- **Volumes de donnees** : avec beaucoup de XML, evaluer l'activation du mode `report_only` de la pipeline pour reduire l'empreinte disque (les JSON bruts ne sont plus necessaires apres insert en BDD).
