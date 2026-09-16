# bmn — génération quotidienne de la dispo (Excel) à partir d'un CSV

Sous-dossier de `nh-web` : appelé par l'onglet « BMN » du site (voir `docs/commandes.md`, section 13).
Utilisable aussi seul, en ligne de commande ou par tâche planifiée.

Script Python pur (pandas + openpyxl). Le CSV est déposé manuellement dans
`data/incoming/`, le script remplit le template Excel **sans toucher aux
cellules à formule**, sauvegarde un fichier horodaté dans `data/output/` et
archive le CSV dans `data/archive/AAAA-MM-JJ/`.

## Installation

```
python -m venv .venv
.venv/bin/pip install -r requirements.txt        # Windows : .venv\Scripts\pip
```

## Fichiers

| Fichier | Rôle |
|---------|------|
| `config.py` | Chemins, pattern du CSV, onglet cible, **mapping CSV -> cellules** (placeholders `COLONNE_A_DEFINIR_*`) |
| `daily_report.py` | Pipeline complet en fonctions réutilisables, cellules `# %%` pour VS Code |
| `explore_template.py` | Inventaire du template : formules (protégées) vs inputs vs cellules vides |
| `make_fixtures.py` | Template factice avec formules + CSV factice pour tester |
| `tests/` | Tests pytest (bout en bout, refus d'écraser une formule, CSV absent, ...) |
| `llm_client.py` | Appel d'un LLM pour l'équipement incriminé -> colonnes `llm.*` (bloc `LLM` de `config.py`) |
| `check_setup.py` | Autodiagnostic : environnement, template, mapping vs formules, CSV. Ne modifie rien |
| `inspect_csv.py` | À lancer sur le vrai CSV : détecte séparateur/encodage, propose `CSV_READ_KWARGS` et un squelette de mapping |
| `scheduling/` | Scripts de lancement cron / Planificateur de tâches + notice |

## Test de bout en bout avec les fixtures

```
.venv/bin/python make_fixtures.py          # template/template.xlsx + data/incoming/rapport_<date>.csv
.venv/bin/python explore_template.py       # inventaire du template
.venv/bin/python daily_report.py           # génère data/output/..., archive le CSV
.venv/bin/python -m pytest tests -q
```

Options utiles : `daily_report.py --dry-run` (ne pas archiver le CSV),
`daily_report.py --csv chemin.csv` (traiter un fichier précis),
`make_release.py` (zip à transporter, sans venv ni sorties),
`explore_template.py --export inventaire.csv`.

## Mise en place sur le poste de travail (sans Claude Code)

1. Dézipper le projet, ouvrir le dossier dans VS Code.
2. Dans le terminal VS Code :
   ```
   python -m venv .venv
   .venv\Scripts\pip install -r requirements.txt      # Linux/macOS : .venv/bin/pip
   ```
   Choisir cet interpréteur dans VS Code (Ctrl+Maj+P > « Python: Select Interpreter » > `.venv`).
3. Vérifier que tout tourne sur les fixtures :
   ```
   python make_fixtures.py
   python check_setup.py
   python daily_report.py
   python -m pytest tests -q
   ```
4. Ouvrir dans Excel le fichier créé dans `data/output/` : les formules doivent
   se recalculer à l'ouverture (colonne Montant, totaux, onglet Synthese).

### Dépannage

| Symptôme | Cause probable | Correctif |
|----------|----------------|-----------|
| `[KO] Une seule colonne lue` | mauvais séparateur | `inspect_csv.py` donne le bon `sep` à mettre dans `CSV_READ_KWARGS` |
| `UnicodeDecodeError` | encodage Windows | `"encoding": "cp1252"` dans `CSV_READ_KWARGS` |
| `Colonnes manquantes dans le CSV` | nom de colonne différent (accent, espace) | copier le nom exact affiché par `inspect_csv.py` |
| `contient une formule : écriture refusée` | cellule du mapping mal choisie | `explore_template.py` liste les cellules protégées, décaler la cible |
| `Le CSV contient N lignes, le template n'en accepte que M` | tableau trop petit | agrandir le template ou ajuster `max_rows` |
| Nombres écrits en texte dans Excel | décimale non reconnue | `"decimal": ","` dans `CSV_READ_KWARGS` |
| Dates écrites en texte | colonne non parsée | `"parse_dates": ["colonne"]` (et `"dayfirst": True` si JJ/MM/AAAA) |

## Quand le vrai template et le vrai CSV arrivent

1. Copier le vrai template dans `template/template.xlsx` (le script factice ne
   l'écrase jamais sans `--force`). Supprimer les fixtures : `data/incoming/rapport_*.csv`.
2. Lancer `explore_template.py` : noter le nom de l'onglet, les cellules à
   formule et les cellules d'input.
3. Lancer `inspect_csv.py chemin/du/vrai.csv` : copier `CSV_READ_KWARGS` et
   partir du squelette de mapping proposé.
4. Dans `config.py` :
   - `TARGET_SHEET`, `CSV_READ_KWARGS` (séparateur, encodage, décimale) ;
   - `CELL_MAPPING` : colonne CSV -> cellule fixe (avec agrégat `sum`, `first`, ...) ;
   - `TABLE_MAPPING` : `start_row`, colonnes CSV -> lettres de colonne, `max_rows` ;
   - `CSV_GLOB` / `CSV_DATE_REGEX` selon le nom réel du fichier.
5. Lancer `check_setup.py` jusqu'à n'avoir plus aucun `[KO]`.
6. Déposer le CSV dans `data/incoming/` et lancer `daily_report.py --dry-run`,
   puis ouvrir le résultat dans Excel.

## Garanties sur les formules

- Le template est ouvert avec `data_only=False` (jamais `True`).
- Chaque écriture passe par `write_value`, qui refuse toute cellule contenant
  une formule (classique, matricielle ou table de données) ou une cellule
  fusionnée non ancre : le run échoue **avant** sauvegarde et sans archiver le CSV.
- openpyxl ne recalcule pas : `fullCalcOnLoad` force Excel à recalculer à l'ouverture.

## Appel depuis le site cAIman (nh-web)

La page « Rapport Excel » de nh-web appelle ce script pour chaque CSV déposé :

```
python daily_report.py --csv <fichier.csv> --output-dir <dossier> --no-archive --json-output
```

- `--output-dir` : dossier de sortie imposé par l'appelant.
- `--no-archive` (alias de `--dry-run`) : le CSV reste en place, l'appelant gère son nettoyage.
- `--json-output` : la dernière ligne de stdout est un JSON (`status`, `output_path`,
  `output_name`, `rows_written`, `message`) et les logs console partent sur stderr.

Côté nh-web, renseigner `EXCEL_PIPELINE_PYTHON` (interpréteur de `bmn/.venv`) dans `.env`.
`EXCEL_PIPELINE_PATH` n'est utile que si ce dossier est déplacé hors du repo. Voir `docs/commandes.md`, section 13.

## Codes de sortie et logs

`0` succès, `2` aucun CSV (run ignoré), `1` erreur. Chaque run trace
`RUN START` / `RUN OK` / `RUN SKIPPED` / `RUN FAILED` dans `logs/daily_report.log`
(rotation automatique). Voir `scheduling/README.md`.

## Extraction par un LLM (optionnelle)

Le bloc `LLM` de `config.py` permet de faire remplir certains champs par un
modèle : chaque ligne du CSV lui est envoyée, et les champs de sa réponse
deviennent des colonnes `llm.equipement`, `llm.indice`, `llm.justification`,
utilisables dans `CELL_MAPPING` / `TABLE_MAPPING` comme une colonne du CSV.

- `actif: False` par défaut : aucun appel, colonnes vides, pipeline inchangé.
- Vérifier le service : `python llm_client.py --test` (ou `--test --hors-ligne`).
- API attendue : format OpenAI `/v1/chat/completions` (Ollama, LM Studio, vLLM,
  llama.cpp). **Si le service du bureau parle autrement, seule la fonction
  `appeler_modele` de `llm_client.py` est à réécrire.**
- Un service injoignable ne bloque pas la génération : les colonnes `llm.*`
  restent vides et l'incident est journalisé.

## Réglages locaux : `reglages.env`

Tout ce qui change entre le serveur de dev et le bureau est dans **un seul
fichier**, `bmn/reglages.env`, ignoré par git (copier `reglages.env.example`) :

- `DISPO_TEMPLATE` : template à remplir (le template réel, qui contient des
  données, se dépose dans `bmn/template/`, dossier ignoré par git) ;
- `DISPO_BLOCS_ACTIF` : remplissage par blocs machine (vrai template) ;
- `LLM_ACTIF`, `LLM_BASE_URL`, `LLM_MODELE`, `LLM_CLE_API`… : connexion au modèle.

Priorité : variables d'environnement > `reglages.env` > valeurs de `config.py`.
`BMN_REGLAGES=aucun` ignore tout réglage local ; côté site, la variable
`EXCEL_PIPELINE_REGLAGES` fait de même.
