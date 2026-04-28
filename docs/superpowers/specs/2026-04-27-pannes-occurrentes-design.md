# Detection des pannes occurrentes par machine — Design

**Date** : 2026-04-27
**Status** : design valide, en attente de plan d'implementation

## Contexte

Le pipeline NH Project filtre des donnees XML de surveillance d'helicopteres. Chaque XML correspond a un vol (ou une session Bff sans demarrage moteur). Le module date_filter conserve deja les TechnicalEvents pertinents et deduplique les pannes au sein d'un meme XML (`nombre_occurrences`).

Aujourd'hui il manque la **detection inter-vols** : reperer qu'une meme panne (meme `TechnicalEventId`) revient sur plusieurs vols consecutifs d'une meme machine, et la signaler "occurrente" dans le front. Quand elle disparait pendant 3 vols, elle doit etre archivee.

C'est ce nouveau module qui est specifie ici.

## Objectifs

- Detecter en temps reel, par machine, les pannes qui reviennent sur 2 ou 3 des 3 derniers vols
- Desactiver automatiquement les pannes absentes des 3 derniers vols
- Conserver l'historique des episodes desactives (jamais de suppression definitive de cette donnee)
- Exposer 3 informations au front Laravel :
  1. Compteur des occurrentes actives sur la bulle de chaque machine (page liste)
  2. Onglet detaille des occurrentes actives sur la page machine
  3. Onglet historique des episodes archives sur la page machine

## Non-objectifs

- **Pas** de detection intra-vol (deja faite par `_deduplicate_pannes()` dans `date_filter.py`)
- **Pas** de WebSocket / push temps reel (le front relit les JSON a chaque rafraichissement)
- **Pas** de tracking des pannes pour les sessions Bff (sans demarrage moteur)
- **Pas** d'analyse au-dela de 5 mois (cap operationnel)

---

## Section 1 — Architecture

### Nouveau module

`src/reporting/recurrent_failures.py` — une seule responsabilite : maintenir l'etat des pannes occurrentes par machine et produire la liste active pour le front.

### Hook dans le pipeline

Appel ajoute dans `process_file()` de `main.py`, **apres Phase 1** (date_filter), **avant Phase 3** (weekly_report). Cf. `main.py:119-121` actuel.

```python
# Phase 1 (existant)
filter_stats = filter_by_flight_date(...)
result["phase1"] = filter_stats

# NOUVEAU - Detection des pannes occurrentes
from src.reporting.recurrent_failures import update_recurrent_failures
recurrent_result = update_recurrent_failures(
    hc_id=hc_id,
    vol_source=folder_name,
    end_datetime=end_dt,
    pannes_conservees=filter_stats["pannes_conservees"],
    output_base=output_base,
)
result["phase_recurrent"] = recurrent_result

# Phase 3 (existant)
report = update_weekly_report(...)
```

### Pas d'impact sur les modules existants

Aucune modification de `date_filter.py`, `engine_filter.py`, `weekly_report.py`, `fh_report.py`, `aggregate_report.py`. Module purement additif.

### Compatibilite avec `report_only`

L'entree du module est la liste `pannes_conservees` deja produite **en memoire** par `date_filter.py` (`main.py:115`). Aucune dependance disque sur les `pannes_conservees.json`. Le module fonctionne en mode complet **et** en mode `report_only`.

### Sessions Bff

Quand `is_flight()` retourne false (`main.py:101`), le hook n'est pas atteint (la fonction `process_file()` retourne avant). Comportement coherent avec Phase 1, 3, FH qui sont aussi sautees pour les Bff.

---

## Section 2 — Structure des fichiers JSON

### Layout par machine

```
data/reports/occurrentes/{HcId}/
├── occurrentes.json   # working state (vols_history + active)
└── archive.json       # historique permanent des episodes desactives
```

### `occurrentes.json` — working state

```json
{
  "hc_id": "NH08",
  "last_updated": "2026-04-27T14:30:00",
  "vols_history": [
    {
      "vol": "NH08_2026-02-01_612",
      "end_datetime": "2026-02-01T18:04:00",
      "te_ids": ["TE-1234", "TE-5678"]
    },
    {
      "vol": "NH08_2026-02-02_613",
      "end_datetime": "2026-02-02T10:13:00",
      "te_ids": ["TE-1234"]
    },
    {
      "vol": "NH08_2026-02-03_614",
      "end_datetime": "2026-02-03T09:30:00",
      "te_ids": ["TE-1234", "TE-5678"]
    }
  ],
  "active": [
    {
      "id": "TE-1234",
      "description": "Hydraulic pressure low",
      "system": "HYDRAULIC SYSTEM",
      "failure_code": "HYD-001",
      "type": "WARNING",
      "score": "3/3",
      "active_depuis_vol": "NH08_2026-02-01_612",
      "active_depuis_date": "2026-02-01"
    }
  ]
}
```

**Regles** :
- `vols_history` toujours trie par `end_datetime` ascendant. Tiebreaker en cas d'egalite : `vol` (folder_name) ascendant.
- `vols_history` purge a chaque update : aucune entree avec `end_datetime < today - 5 mois`.
- `active` lu par le front pour afficher l'onglet "Occurrentes" et le compteur sur la bulle machine.
- `last_updated` ISO 8601, pour debug/affichage.

### `archive.json` — historique permanent

```json
{
  "hc_id": "NH08",
  "pannes_archivees": [
    {
      "id": "TE-1234",
      "description": "Hydraulic pressure low",
      "system": "HYDRAULIC SYSTEM",
      "failure_code": "HYD-001",
      "type": "WARNING",
      "active_depuis_vol": "NH08_2026-02-01_612",
      "active_depuis_date": "2026-02-01",
      "desactivee_au_vol": "NH08_2026-02-10_625",
      "desactivee_le": "2026-02-10",
      "duree_occurrence_jours": 9,
      "nombre_vols_actifs": 7
    }
  ]
}
```

**Regles** :
- Append-only : aucune ecriture sur les entrees existantes, aucune purge.
- Reapparition d'une panne deja archivee : nouvelle entree separee (un episode = une entree). Aucune fusion ni mise a jour de l'entree precedente.
- Le front lit ce fichier pour afficher l'onglet historique.

### Identifiants utilises

| Cle | Exemple | Role |
|---|---|---|
| `vol` (= folder_name) | `NH08_2026-02-01_612` | Identite unique d'un vol |
| `end_datetime` | `2026-02-01T18:04:00` | Ordre chronologique (precision a la seconde) |
| DSN | (extrait de `<Header>/<DSN>`) | Identifiant XML brut, deja trace dans `main.py` |

DSN est unique par XML. `vol` est unique grace au num d'XML extrait du nom de fichier (`main.py:85-88`). Deux vols dans la meme journee ont des `vol` differents (ex: `NH08_2026-02-01_612` vs `NH08_2026-02-01_613`).

---

## Section 3 — Algorithme de detection

### Fenetre glissante : 3 derniers vols, ratio 2/3

- **Activation** : panne presente dans **>= 2** des 3 derniers vols chronologiques
- **Desactivation** : panne presente dans **0** des 3 derniers vols chronologiques
- **Zone tampon** : score 1/3 ne change rien (ni activation ni desactivation)

Si la fenetre est incomplete (1 ou 2 vols seulement au demarrage de la machine), la regle d'activation devient ">= 2 sur N derniers vols" :
- 1 vol : score max 1/1, jamais d'activation
- 2 vols : si meme TE dans les 2 -> score 2/2 -> activation

### Fonction `update_recurrent_failures(hc_id, vol_source, end_datetime, pannes_conservees, output_base)`

#### Etape 0 — Verification cap 5 mois

```python
pivot = datetime.now() - timedelta(days=150)
if end_datetime < pivot:
    return { "status": "skipped", "reason": "vol > 5 mois" }
```

Aucun fichier n'est lu ni ecrit dans ce cas. Phase 1, 2, 3, FH continuent normalement pour cet XML.

#### Etape 1 — Charger l'etat existant

- Lit `data/reports/occurrentes/{hc_id}/occurrentes.json` s'il existe.
- Sinon, initialise `{ "hc_id": hc_id, "vols_history": [], "active": [] }`.
- En cas de JSON corrompu : sauvegarde sous `occurrentes.json.corrupted.{timestamp}` et repart d'un etat vide.

#### Etape 2 — Purger `vols_history` du contenu > 5 mois

```python
pivot = datetime.now() - timedelta(days=150)
vols_history = [v for v in vols_history if parse(v["end_datetime"]) >= pivot]
```

Garantit que la taille du fichier reste bornee dans le temps. Le `pivot` calcule a l'etape 0 est reutilise.

#### Etape 3 — Inserer le vol courant

- Construit l'entree : `{ "vol": vol_source, "end_datetime": end_datetime.isoformat(), "te_ids": list(set(te_ids))}` (te_ids extraits de `pannes_conservees`, dedupliques).
- Si une entree avec le meme `vol` existe deja : **remplacement** (idempotence).
- Sinon : insertion a la position chronologique correcte, tri stable par `(end_datetime, vol)` ascendant.

#### Etape 4 — Identifier les 3 vols les plus recents

```python
last_3 = vols_history[-3:]
```

Peut contenir 1, 2 ou 3 entrees au demarrage.

#### Etape 5 — Calculer les scores

Pour chaque `te_id` apparaissant dans au moins un vol de `last_3` :
```python
score_n = sum(1 for v in last_3 if te_id in v["te_ids"])
score_d = len(last_3)
```

#### Etape 6 — Detecter activations / desactivations

**Activations** : pour chaque `te_id` avec `score_n >= 2` et **pas deja** dans `active` :
- Recupere description, system, failure_code, type depuis `pannes_conservees` du vol courant si present, sinon depuis n'importe quel vol de `last_3` qui contient le TE (a defaut champs a `null`)
- Ajoute a `active` avec `active_depuis_vol = vol_source` et `active_depuis_date = end_datetime.strftime("%Y-%m-%d")`

**Desactivations** : pour chaque `te_id` actuellement dans `active` avec `score_n == 0` :
- Calcule `nombre_vols_actifs` en comptant les entrees de `vols_history` entre `active_depuis_date` et `end_datetime` qui contiennent ce `te_id`
- Calcule `duree_occurrence_jours` = `(end_datetime.date() - active_depuis_date).days`
- Append a `archive.json` avec `desactivee_au_vol = vol_source` et `desactivee_le = end_datetime.strftime("%Y-%m-%d")`
- Retire de `active`

**Convention pour `desactivee_au_vol` / `active_depuis_vol` (option A)** : ces champs referencent **le vol en cours de traitement** au moment ou l'evenement se produit. Sur ingestion chronologique (cas normal) cela coincide avec le vol chronologiquement le plus recent du window. En ingestion hors-ordre, ces champs peuvent legerement varier selon l'ordre — c'est accepte pour l'MVP, le SET d'episodes archives reste coherent.

#### Etape 7 — Sauvegarder

- Met a jour `last_updated`
- Ecrit `occurrentes.json.tmp` puis `os.replace()` vers `occurrentes.json` (atomicite)
- Si activations ou desactivations : ecrit `archive.json` de la meme facon atomique

#### Cas particulier : XML hors-window courante

Si le `end_datetime` du vol courant est anterieur au 3e plus recent vol de `vols_history` apres insertion -> il s'insere dans `vols_history` mais **`last_3` ne change pas**. Aucune activation/desactivation n'est declenchee. Comportement automatique du fait du tri chronologique a l'etape 3.

### Retour de la fonction

```python
{
    "hc_id": "NH08",
    "status": "ok" | "skipped",
    "reason": str | None,
    "vols_in_history": 42,
    "active_count": 3,
    "activations": ["TE-1234"],          # nouvelles activations ce vol
    "deactivations": ["TE-9999"],         # archivees ce vol
    "occurrentes_path": ".../occurrentes.json",
    "archive_path": ".../archive.json"
}
```

---

## Section 4 — Integration dans le pipeline

### Comportement par cas

| Situation | Comportement |
|---|---|
| Vol normal avec pannes | Update `vols_history`, recalcul scores, possibles activations/desactivations |
| Vol normal **sans** pannes (te_ids vide) | Update `vols_history` avec `te_ids: []`, slot rempli avec absence — peut declencher des desactivations |
| Bff (pas de moteur) | Hook non appele (sortie de `process_file()` a `main.py:101`) |
| XML hors-ordre (end_datetime ancien mais < 5 mois) | Insere chronologiquement, `last_3` peut ou non changer selon l'anciennete |
| XML > 5 mois | Module saute (status "skipped"), Phase 1/2/3/FH continuent |
| Exception inattendue dans le module | Capturee par le `try/except` global de `process_file()` (`main.py:165`) |

### Affichage dans le log batch

`run.py` affiche par vol traite, en plus de l'existant, un compteur d'occurrentes :

```
→ OK | 2026 | 2026-W05 | TE conserves: 13/276 | Pannes ajoutees: 12 | FH: 1.45h | Occurrentes actives: 3 (+1/-0)
```

- `Occurrentes actives: N` = total apres traitement
- `(+A/-D)` = delta sur ce vol (A nouvelles activations, D desactivations vers archive)

Recap final dans `run.py` : compteurs `active` et `archived` par machine.

---

## Section 5 — Integration frontend Laravel

3 touchpoints front, 2 fichiers JSON sources.

### Touchpoint 1 — Bulle machine sur `/machines` (vue liste)

Modification de `MachineController::index()` :
- Pour chaque machine, lit `data/reports/occurrentes/{hc_id}/occurrentes.json`
- Expose `$m->occurrentes_count` (= `len(active)` ou 0 si fichier absent)
- Expose `$m->occurrentes_descriptions` = liste des descriptions des entrees actives (vide si aucune)

Modification de `machines/index.blade.php` :
- Ajoute un 4e compteur dans le grid existant a cote de Vols / Non-vols / Erreurs : "Occurrentes"
- Style : couleur ambre/rouge si `> 0` pour attirer l'oeil
- Tooltip au survol : liste des descriptions tronquees a 60 caracteres chacune (max 5 affichees, puis "…et N autres")

But : reperage rapide des machines a problemes en cours, sans avoir a cliquer sur chaque machine.

### Touchpoint 2 — Onglet "Occurrentes" sur `/machines/{hcId}?tab=occurrentes`

Modification de `MachineController::show()` :
- Ajout du tab `occurrentes` qui charge `occurrentes.json` et expose `$active = $data['active'] ?? []`

Modification de `machines/show.blade.php` :
- Ajoute le tab dans la barre des tabs (apres Erreurs)
- Tableau avec colonnes : ID, Description, Systeme, Code panne, Type, Score, Active depuis (date)
- Si vide : "Aucune panne occurrente actuellement sur cette machine."

### Touchpoint 3 — Onglet "Historique d'occurrences" sur `/machines/{hcId}?tab=occurrentes-archive`

Modification de `MachineController::show()` :
- Ajout du tab `occurrentes-archive` qui charge `archive.json` et expose `$archive = $data['pannes_archivees'] ?? []`

Modification de `machines/show.blade.php` :
- Tableau avec colonnes : ID, Description, Systeme, Active depuis, Desactivee le, Duree (jours), Nombre vols actifs
- Tri par defaut : `desactivee_le` desc (recents en haut)
- Filtres / tri ascendant-descendant pour consultation par periode
- Si vide : "Aucun episode d'occurrence archive pour cette machine."

### Temps reel

Pas de WebSocket. La page Laravel relit le JSON a chaque rafraichissement. Comme le pipeline Python met a jour ces fichiers a chaque XML traite, c'est "temps reel" au sens "a jour a la derniere ingestion".

### Recap fichiers JSON ↔ touchpoints

| Touchpoint | Fichier source | Champ utilise |
|---|---|---|
| Bulle machine (liste) | `occurrentes.json` | `len(active)` + descriptions courtes |
| Onglet "Occurrentes" (detail) | `occurrentes.json` | `active[]` complet |
| Onglet "Historique" (detail) | `archive.json` | `pannes_archivees[]` |

---

## Section 6 — Gestion d'erreurs et cas limites

### Erreurs gerees explicitement

| Cas | Comportement |
|---|---|
| `occurrentes.json` corrompu (JSON invalide) | Log warning, sauvegarde sous `occurrentes.json.corrupted.{timestamp}`, repart d'etat vide |
| `archive.json` corrompu | Idem : sauvegarde `.corrupted.{timestamp}`, repart d'archive vide. Episodes precedents preserves dans le fichier corrompu pour analyse manuelle |
| Dossier `data/reports/occurrentes/{hc_id}/` inexistant | Creation automatique avec `os.makedirs(..., exist_ok=True)` |
| `pannes_conservees` vide (vol sans panne) | Slot avec `te_ids: []`, peut declencher des desactivations |
| Vol deja dans `vols_history` (retraitement) | Entree existante remplacee (cle = `vol`), pas de doublon |
| `end_datetime` manquant ou non parsable | Retour immediat `{"status": "skipped", "reason": "no_end_datetime"}`, aucun fichier touche. Cas extremement rare car Phase 2 a deja valide l'XML |
| Exception inattendue dans le module | Capturee par `try/except` global de `process_file()` (`main.py:165`) — Phase 3 et FH ne sont pas affectees |

### Atomicite des ecritures

Pour eviter qu'une interruption (Ctrl+C, kill) ne laisse un JSON tronque :
- Ecriture dans `<file>.tmp` puis `os.replace()` vers le fichier final
- Standard Python, pas de dependance externe

### Concurrence

Le pipeline est mono-thread (un XML a la fois dans `run.py`). Aucun verrou necessaire pour le batch.

Limite documentee : si dans le futur Laravel appelle `main.py` en parallele pour plusieurs XMLs de la meme machine, il y aurait un risque de race condition. Pour l'instant non-couvert ; `process_file()` est suppose appele sequentiellement par machine.

### Edge case : peu de vols

| Situation | Comportement |
|---|---|
| 1er vol d'une machine | `vols_history` = [vol1], `last_3` = [vol1], score max = 1/1, jamais d'activation |
| 2e vol | Si meme TE qu'au 1er -> score 2/2 -> activation immediate |
| 3e vol et au-dela | Comportement nominal (fenetre = 3) |

---

## Section 7 — Limite operationnelle 5 mois

### Objectif

Borner la taille des JSON et le cout de calcul, eviter qu'une ingestion massive de XMLs anciens (annees 2023-2024) ne pollue la detection courante.

### Reference : aujourd'hui

Date pivot calculee a chaque update : `pivot = datetime.now() - timedelta(days=150)`. On approxime "5 mois" a **150 jours fixes** pour eviter d'introduire la dependance `dateutil.relativedelta`. Cas limite (4 mois 27 jours vs 5 mois calendaires) acceptable pour un cap operationnel approximatif.

### Regles

1. **XML entrant > 5 mois** → skip total du module. Phase 1/2/3/FH continuent. Pas de read ni write des fichiers occurrentes.
2. **Cleanup `vols_history`** → toute entree `end_datetime < pivot` est supprimee a chaque update.
3. **Pas de cleanup de `archive.json`** → croissance lente naturellement bornee par la regle 1 (les nouveaux episodes ne peuvent venir que de vols < 5 mois).

### Consequences

- `vols_history` ne grandit jamais au-dela de ~5 mois de vols.
- `active` est implicitement borne (calcule depuis `vols_history`).
- `archive.json` grossit lentement mais conserve l'historique long terme pour le front (consultation des occurrentes passees, analyse ML future).
- Si une machine ne vole pas pendant 6 mois, `vols_history` se vide naturellement et le compteur repart de zero a la reprise.

### Constante

Le seuil de 150 jours est expose comme constante en tete de module pour facilite de modification :

```python
VOLS_HISTORY_RETENTION_DAYS = 150
```

---

## Section 8 — Comportement a l'echelle

### Hypothese : 1000 XMLs ingerés

- ~400 vols normaux, ~600 Bff (sautes par le module)
- Repartis sur 5-10 machines

### Performance

- Surcout par XML : ~1-5 ms (lecture JSON + insertion triee + recalcul score sur `last_3` + ecriture JSON)
- Surcout total batch : ~1-5 secondes pour 1000 XMLs
- Negligeable face au cout de Phase 1 (parsing lxml) qui domine

### Tailles attendues

Par machine moyenne (~50 vols sur 5 mois, ~10 episodes archives) :
- `occurrentes.json` : ~10-12 KB
- `archive.json` : ~8 KB
- **Total par machine : ~20 KB**

Pour 10 machines : ~200 KB total. Pour 5 ans d'ingestion continue (~5000 vols/machine au total) : ~1 MB max sur tout `data/reports/occurrentes/`.

### Tolerance a l'ordre d'injection

- XML plus vieux que `last_3` actuel -> insere dans `vols_history`, `last_3` inchange, aucun effet sur `active`. Silencieux.
- XML qui s'insere **dans** le `last_3` chronologique -> recalcul scores, possibles activations/desactivations.
- XML plus recent que tout -> flux normal.

### Determinisme

Apres ingestion des 1000 XMLs, peu importe l'ordre :
- ✅ `active` est identique (calcule sur les 3 derniers chronologiques uniquement)
- ✅ `vols_history` est identique (tri par end_datetime)
- ⚠️ `archive.json` peut differer legerement sur les champs `active_depuis_vol` / `desactivee_au_vol` (option A retenue : vol courant au moment du traitement)

### Idempotence pour rejouer un batch

L'idempotence est garantie sur `vols_history` (cle = `vol`). Pour un re-run propre, l'utilisateur supprime manuellement `data/reports/occurrentes/{hc_id}/` avant le batch ; pas d'auto-purge.

---

## Section 9 — Tests

### Approche

Suite pytest dans `tests/reporting/test_recurrent_failures.py` (a creer).
Generateur d'XMLs factices comme fixture partagee dans `tests/conftest.py`.

### Generateur d'XMLs factices

Helper Python `make_fake_xml(hc_id, end_datetime, te_ids=None, start_datetime=None, type_vol="FLIGHT")` qui produit un XML minimal valide passant Phase 1 et Phase 2. Genere dans un dossier temporaire (`tmp_path` pytest, cleanup automatique).

Permet de creer des scenarios sans versionner des fichiers XML, juste avec quelques lignes Python par test.

### Scenarios couverts (obligatoires)

| Test | Scenario | Assertion |
|---|---|---|
| `test_premiere_panne_pas_active` | 1 vol, 1 TE | `active` vide |
| `test_activation_sur_2_2` | 2 vols, meme TE | `active` contient le TE, score "2/2" |
| `test_activation_sur_2_3` | 3 vols, TE dans 2 d'entre eux | `active` contient le TE, score "2/3" |
| `test_pas_activation_a_1_3` | 3 vols, TE dans 1 seul | `active` vide (zone tampon) |
| `test_zone_tampon_apres_activation` | active, puis 1 vol sans TE | TE reste active (score 2/3) |
| `test_desactivation_apres_3_absences` | active, puis 3 vols sans TE | TE archive, retire de active |
| `test_reapparition_cree_nouvel_episode` | active -> archive -> retour -> archive | 2 entrees separees dans archive |
| `test_xml_hors_ordre_silencieux` | inject vol vieux apres vol recent | `last_3` inchange, `active` inchange |
| `test_xml_trop_vieux_skip` | XML avec end_datetime > 5 mois | Module ne touche aucun fichier, status "skipped" |
| `test_purge_vols_history` | vols_history a entrees > 5 mois | Entrees supprimees a l'update suivant |
| `test_idempotence_meme_xml` | Retraiter le meme XML 2 fois | Pas de doublon dans vols_history ni archive |
| `test_bff_ignore` | XML Bff (engine_filter dit non-vol) | Module pas appele du tout |
| `test_xml_corrompu_recovery` | occurrentes.json invalide JSON | Sauvegarde `.corrupted.{ts}`, repart d'etat vide |
| `test_batch_400_vols_multi_machines` | Generation programmee de 400 vols sur 5 machines | Tous les JSON conformes, tailles attendues, activations/desactivations coherentes |

### Pas dans le scope de cette spec

- Tests E2E Laravel (UI front)
- Tests de performance / charge au-dela des 400 vols simules

---

## Synthese des decisions retenues

| Decision | Choix |
|---|---|
| Algorithme | Fenetre glissante 3 vols, ratio 2/3 |
| Activation | Score >= 2 sur 3 derniers (ou >= 2 sur N si N < 3) |
| Desactivation | Score == 0 sur 3 derniers |
| Architecture | Nouveau module `recurrent_failures.py`, hook dans `process_file()` |
| Stockage | 1 dossier par machine : `occurrentes.json` + `archive.json` |
| Tri chronologique | `end_datetime` ascendant, tiebreaker `vol` |
| Cap operationnel | 5 mois (XMLs anciens skip, vols_history purge, archive non purgee) |
| Reapparition | Nouvel episode = nouvelle entree dans archive |
| Convention archivage | Vol courant en `desactivee_au_vol` / `active_depuis_vol` (option A) |
| Atomicite | Ecriture `.tmp` + `os.replace()` |
| Tests | Pytest + generateur d'XMLs factices, 14 scenarios obligatoires |

## Fichiers crees / modifies

**Crees :**
- `src/reporting/recurrent_failures.py`
- `tests/conftest.py` (generateur XMLs factices)
- `tests/reporting/test_recurrent_failures.py`
- `data/reports/occurrentes/{HcId}/occurrentes.json` (genere a l'execution)
- `data/reports/occurrentes/{HcId}/archive.json` (genere a l'execution)

**Modifies :**
- `main.py` (ajout du hook dans `process_file()`)
- `run.py` (ajout du log "Occurrentes actives: N (+A/-D)")
- `web/app/Http/Controllers/MachineController.php` (lecture des JSON pour `index()` et `show()`)
- `web/resources/views/machines/index.blade.php` (4e compteur dans la bulle)
- `web/resources/views/machines/show.blade.php` (2 nouveaux tabs)
- `CLAUDE.md` (documentation de la nouvelle phase)
