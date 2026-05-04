# Design — Intégration des pannes occurrentes dans le front Laravel

**Date** : 2026-05-01
**Repo** : `nh-web` (Laravel)
**Repo source** : `nh-pipeline` (module Python `recurrent_failures.py`)

## 1. Contexte

La pipeline Python `nh-pipeline` produit déjà, après chaque XML traité, un JSON `occurrentes.json` par machine listant les pannes considérées comme « occurrentes actives » (apparues dans ≥2 des 3 derniers vols chronologiques d'une machine, fenêtre cap 150 jours). Côté Laravel, **rien** n'est encore branché : pas de table, pas de service d'ingestion, pas d'affichage UI.

L'objectif est d'intégrer ces données dans la page `/machines` (la liste des machines), sous forme de deux widgets par ligne machine.

## 2. Objectifs

- Ingérer en BDD l'état courant des pannes occurrentes actives de chaque machine.
- Afficher pour chaque machine, sur la page `/machines`, deux widgets à droite de la ligne :
  1. **Pannes occurrentes actives** : reflète l'état courant calculé par le module Python.
  2. **Pannes du dernier vol** : liste des pannes conservées du dernier vol importé pour cette machine, avec leur nombre d'occurrences intra-vol.
- Conserver le design system existant (Tailwind slate/blue, rounded-xl, typographies actuelles).

**Non-objectifs** :
- L'archive des occurrentes (`archive.json`) — schéma prévu mais ingestion repoussée.
- Modifications de `/machines/{HcId}` (page détail).
- Mise à jour live des widgets via Livewire.

## 3. Architecture / flow de données

```
[Upload XML]  →  ProcessXmlJob  →  python3 main.py
                                       ├─ pannes_conservees.json  (existant)
                                       └─ reports/occurrentes/{HcId}/
                                            ├─ occurrentes.json   (existant)
                                            └─ archive.json       (non utilisé pour l'instant)

ProcessXmlJob (après pipeline)  ──┬──>  FlightImporter           (existant) → technical_events
                                  └──>  RecurrentFailuresIngestor (NOUVEAU) → recurrent_failures

UI : /machines (Blade)
  ┌── HcId (col-span-2)        cliquable → /machines/{HcId}
  ├── Compteurs Vols/Non-Vols/Erreurs (col-span-3)
  └── 2 widgets côte à côte (col-span-7)
        ├── Widget 1 : recurrent_failures WHERE machine_id=X AND status='active'
        └── Widget 2 : technical_events du dernier flight de la machine WHERE status='conservee'
                       Modal "voir plus" via composant <x-modal> Breeze existant
```

## 4. Modèle de données

### Nouvelle table `recurrent_failures`

```php
Schema::create('recurrent_failures', function (Blueprint $table) {
    $table->id();
    $table->foreignId('machine_id')->constrained('machines')->cascadeOnDelete();
    $table->string('technical_event_id');             // ex "FFP1000011A"
    $table->enum('status', ['active', 'archived'])->default('active');

    // descriptifs (depuis le JSON occurrentes active[*])
    $table->text('te_description')->nullable();        // TechnicalEventDescription
    $table->text('description')->nullable();            // Description (system-level)
    $table->string('system_description')->nullable();   // SystemDescription
    $table->string('type_description')->nullable();     // TypeDescription
    $table->string('failure_code')->nullable();

    // historique d'activation
    $table->string('active_depuis_vol')->nullable();    // folder_name du vol activateur
    $table->date('active_depuis_date')->nullable();
    $table->timestamp('first_apparition')->nullable();

    // score courant (1-3 sur les 3 derniers vols)
    $table->unsignedTinyInteger('score')->default(1);

    // détails bruts du JSON pour évolutions futures
    $table->jsonb('details')->nullable();

    $table->timestamps();
    $table->index(['machine_id', 'status']);
});

DB::statement("CREATE UNIQUE INDEX recurrent_failures_active_unique
               ON recurrent_failures (machine_id, technical_event_id)
               WHERE status = 'active'");
```

**Justification de l'index partiel** : une panne ne peut être active qu'une fois par machine. En revanche, une panne archivée peut avoir plusieurs entrées (épisodes désactivés/réactivés). Le partial index Postgres ne contraint que les lignes `active`, ce qui laisse l'archive libre quand elle sera branchée plus tard.

### Modèle Eloquent `RecurrentFailure`

`app/Models/RecurrentFailure.php` avec :
- `protected $casts = ['active_depuis_date' => 'date', 'first_apparition' => 'datetime', 'details' => 'array'];`
- relation `machine()` belongsTo `Machine`.

### Relations à ajouter sur `Machine`

```php
public function recurrentFailures()
{
    return $this->hasMany(RecurrentFailure::class);
}
```

### Pas de nouvelle table pour le widget 2

Les pannes du dernier vol sont déjà stockées dans `technical_events` (via `FlightImporter`). On lit cette table avec un join sur `flights` pour récupérer le dernier flight de la machine.

## 5. Backend — ingestion

### Service `RecurrentFailuresIngestor`

Fichier : `app/Services/RecurrentFailuresIngestor.php`

API publique :

```php
public function ingest(string $hcId): array  // ['synced' => N, 'removed' => M]
```

Algorithme :

1. Construire le chemin `storage_path("app/data/reports/occurrentes/{$hcId}/occurrentes.json")`.
2. Si le fichier n'existe pas → retourner `['synced' => 0, 'removed' => 0]` (pas d'erreur).
3. Charger et décoder le JSON. Récupérer la liste `active` et `vols_history`.
4. Trouver la `Machine` correspondant à `hc_id` (firstOrCreate).
5. Calculer pour chaque te_id actif son `score` courant : nombre d'occurrences dans les 3 derniers vols de `vols_history` (entre 1 et 3).
6. Dans une transaction DB :
   - **DELETE** des actives qui ne sont plus dans le JSON (= archivées par le module Python) : `DELETE FROM recurrent_failures WHERE machine_id = ? AND status = 'active' AND technical_event_id NOT IN (?)`.
   - **UPSERT** chaque entrée active : `INSERT ... ON CONFLICT (machine_id, technical_event_id) WHERE status='active' DO UPDATE SET ...` (via `Machine::recurrentFailures()->updateOrCreate(['technical_event_id' => $id, 'status' => 'active'], $data)`).
7. Retourner les compteurs.

### Intégration dans `ProcessXmlJob`

Après l'appel à `FlightImporter::import()` (ou `importNonVol()`), si `$result['status'] === 'ok'` :

```php
app(\App\Services\RecurrentFailuresIngestor::class)->ingest($result['hc_id']);
```

Pas d'appel pour `no_engine` ou `error` (le module Python ne s'exécute que sur les vrais vols).

### Tests Pest

`tests/Feature/RecurrentFailuresIngestionTest.php` :

- **fixture** : un fichier `occurrentes.json` minimal posé dans `storage/app/data/reports/occurrentes/NH99/`.
- **test 1** : ingest sur machine vide → la table contient les N entrées du JSON, status=active.
- **test 2** : ingest une 2e fois avec un JSON où une panne a disparu de `active` → la ligne correspondante est supprimée.
- **test 3** : ingest avec une panne dont le score change → la ligne existante est mise à jour (pas dupliquée).
- **test 4** : si le JSON n'existe pas → ingest retourne `[synced=0, removed=0]` sans exception.

## 6. UI — page `/machines`

### Layout d'une ligne (refonte)

Grid `grid-cols-12 gap-4` :

| Cols | Contenu |
|---|---|
| `col-span-2` | Icône + HcId + nb enregistrements (cliquable → `/machines/{HcId}`) |
| `col-span-3` | Compteurs Vols / Non-Vols / Erreurs (3 mini-blocs côte à côte) |
| `col-span-7` | 2 widgets côte à côte (`grid grid-cols-2 gap-3`) |

La ligne **n'est plus** un `<a>` global. La navigation vers le détail machine se fait par clic sur la zone HcId (col-span-2), avec l'icône chevron déplacée à droite de la zone HcId (avant les compteurs). Les widgets et leurs boutons "voir plus" restent indépendants du clic de navigation.

### Anatomie d'un widget

Container : `bg-slate-50 rounded-lg border border-slate-200 p-3` (cohérent avec la page).

Header :
- Titre court (text-xs uppercase tracking-wide text-slate-500).
- Compteur total (text-sm font-semibold text-slate-700).
- Widget 2 : date du dernier vol (text-xs text-slate-500) à droite.

Body : top 3 pannes max. Chaque panne :
```
SONAR 1 LOSS (FCS EQPT: AFCC2; Fault Code: 11)        [×2]   ← widget 2 only
ROTORS FLIGHT CONTROL · Flight Control System · Fault Code
```
- Ligne 1 : `TechnicalEventDescription` (truncate avec ellipsis si trop long), badge `×nombre_occurrences` à droite (widget 2 uniquement).
- Ligne 2 : `Description · SystemDescription · TypeDescription` en text-xs text-slate-500.

Footer (si total > 3) : bouton `voir plus (N)` text-xs text-blue-600.

### Modals "voir plus"

Réutilise le composant Blade existant `<x-modal>` (Breeze).

- **Modal widget 1** : titre `Pannes occurrentes actives — {HcId}`, body = liste complète avec mêmes champs que dans le widget. Plus le `score` (1/3, 2/3, 3/3) à côté.
- **Modal widget 2** : titre `Pannes du dernier vol — {HcId} — {date}`, body = liste complète avec `nombre_occurrences`.

Les modals sont contrôlés en Alpine.js (utilisé déjà par `<x-modal>`), pas de Livewire.

### Empty states

- Pas de panne active : widget 1 affiche `— Aucune panne occurrente active —` (text-sm text-slate-400).
- Pas de pannes sur le dernier vol (ou pas de vol encore) : widget 2 affiche `— Aucune panne sur le dernier vol —`.

### Modifications du contrôleur `MachineController@index`

```php
$machines = Machine::query()
    ->withCount([
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
```

Toutes les données nécessaires aux widgets sont eager-loadées en une seule passe (pas de N+1). Le tri `orderByDesc('score')` puis `orderByDesc('nombre_occurrences')` met les pannes les plus significatives en haut.

## 7. Design system

Les widgets reprennent strictement le langage visuel existant de `/machines` :

- Containers : `bg-white rounded-xl border border-slate-200` pour la ligne ; sous-blocs widgets en `bg-slate-50 rounded-lg border border-slate-200`.
- Couleurs : palette slate (`slate-50/100/200/400/500/700/900`) ; bleu (`blue-50/100/600/700`) pour les éléments interactifs ; rouge (`red-600`) pour les compteurs d'erreurs > 0.
- Typographies : labels en `text-xs uppercase tracking-wide font-medium` ; nombres en `tabular-nums font-bold` ; corps en `text-sm`.
- Pas de nouvelle dépendance front, pas de nouveau composant générique.

## 8. Plan de tests

1. **Backend** : tests Pest feature pour `RecurrentFailuresIngestor` (4 cas listés en §5).
2. **Front** : test feature qui charge `/machines` avec une machine ayant N pannes actives + un flight avec M pannes conservées, et asserte la présence des bons textes et compteurs dans la réponse.
3. **Smoke manuel** : upload d'un XML de test → vérifier que widget 1 reflète l'état du JSON `occurrentes.json` et widget 2 reflète les pannes du flight le plus récent.

## 9. Hors-scope (à faire plus tard)

- Ingestion de l'archive (`archive.json`) — schéma prêt, juste à brancher.
- Mise à jour live de `/machines` via Livewire.
- Refonte de `/machines/{HcId}` (page détail).
- Filtres / tri sur les widgets.
