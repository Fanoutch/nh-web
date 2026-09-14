"""
Configuration centralisée du pipeline CSV -> Excel.

Tout ce qui dépend du CSV réel est marqué `A_DEFINIR` : à compléter dès
réception du vrai fichier. Le reste (chemins, patterns, logs) est prêt.
"""
from __future__ import annotations

from pathlib import Path

# ---------------------------------------------------------------------------
# Chemins
# ---------------------------------------------------------------------------
BASE_DIR = Path(__file__).resolve().parent

INCOMING_DIR = BASE_DIR / "data" / "incoming"   # dossier surveillé (dépôt manuel du CSV)
OUTPUT_DIR = BASE_DIR / "data" / "output"       # fichiers Excel générés
ARCHIVE_DIR = BASE_DIR / "data" / "archive"     # CSV traités, classés par date
LOG_DIR = BASE_DIR / "logs"

TEMPLATE_PATH = BASE_DIR / "template" / "template.xlsx"

# ---------------------------------------------------------------------------
# Repérage du CSV du jour
# ---------------------------------------------------------------------------
# Motif glob des fichiers candidats dans INCOMING_DIR.
CSV_GLOB = "*.csv"
# Regex optionnelle pour extraire la date du nom de fichier (groupe nommé `date`).
# Si elle matche, on trie par cette date ; sinon on retombe sur la date de
# modification du fichier. Exemple : "rapport_2026-09-03.csv".
CSV_DATE_REGEX = r"(?P<date>\d{4}-\d{2}-\d{2})"
CSV_DATE_FORMAT = "%Y-%m-%d"

# Lecture pandas (à ajuster selon le vrai CSV : ";" est courant en export FR).
CSV_READ_KWARGS = {
    "sep": ";",
    "encoding": "utf-8-sig",
    "decimal": ",",
}

# ---------------------------------------------------------------------------
# Template Excel
# ---------------------------------------------------------------------------
# Nom de l'onglet à remplir. `None` = premier onglet du classeur.
# A_DEFINIR après exploration du template (voir explore_template.py).
TARGET_SHEET: str | None = "Saisie"

# Colonnes du CSV obligatoires : si l'une manque, le run échoue avant d'écrire.
# Alimenté automatiquement depuis les mappings ci-dessous.

# ---------------------------------------------------------------------------
# Mapping CSV -> cellules Excel
# ---------------------------------------------------------------------------
# 1) Valeurs uniques : une colonne CSV -> une cellule fixe.
#    On prend la valeur de la première ligne du CSV (ou d'un agrégat, cf.
#    `agg`). Utile pour un en-tête : date du rapport, site, total...
#    Formes acceptées :
#        "COLONNE": "B2"
#        "COLONNE": {"cell": "B2", "agg": "sum"}   # agg: first|sum|max|min|count
CELL_MAPPING: dict[str, str | dict] = {
    "COLONNE_A_DEFINIR_1": "B2",                       # ex. date du rapport
    "COLONNE_A_DEFINIR_2": "B3",                       # ex. nom du site
    "COLONNE_A_DEFINIR_5": {"cell": "B4", "agg": "sum"},  # ex. total global
}

# 2) Tableau : chaque ligne du CSV -> une ligne Excel, à partir de `start_row`.
#    `columns` associe une colonne CSV à une lettre de colonne Excel.
#    Les colonnes Excel absentes de ce dict ne sont jamais touchées (formules).
TABLE_MAPPING: dict = {
    "start_row": 7,
    "columns": {
        "COLONNE_A_DEFINIR_3": "A",   # ex. référence
        "COLONNE_A_DEFINIR_4": "B",   # ex. libellé
        "COLONNE_A_DEFINIR_5": "C",   # ex. quantité
        "COLONNE_A_DEFINIR_6": "D",   # ex. prix unitaire
        # "E" contient une formule (=C*D) dans le template : on n'y touche pas.
    },
    # Nombre max de lignes que le template peut accueillir (None = illimité).
    # Si le CSV en contient plus, le run échoue pour ne pas écraser le pied de tableau.
    "max_rows": 50,
}

# Vider les anciennes valeurs du tableau (start_row..start_row+max_rows) avant
# d'écrire ? Utile si le template contient des données d'exemple.
CLEAR_TABLE_BEFORE_WRITE = True


def required_csv_columns() -> set[str]:
    """Colonnes CSV attendues, déduites des deux mappings."""
    return set(CELL_MAPPING) | set(TABLE_MAPPING["columns"])


# ---------------------------------------------------------------------------
# Sortie
# ---------------------------------------------------------------------------
# Champs disponibles : {template_stem}, {csv_stem}, {date} (date du CSV,
# YYYY-MM-DD), {run_ts} (horodatage du run, YYYYMMDD_HHMMSS).
OUTPUT_NAME_PATTERN = "dispo_{date}_{run_ts}.xlsx"

# Forcer Excel à recalculer toutes les formules à l'ouverture.
# openpyxl ne recalcule pas : sans ce flag, les cellules formule affichent
# leur ancienne valeur en cache jusqu'au premier recalcul.
FORCE_FULL_CALC_ON_LOAD = True

# ---------------------------------------------------------------------------
# Comportement
# ---------------------------------------------------------------------------
# CSV absent : code de sortie renvoyé au scheduler (0 = silencieux, 2 = alerte).
EXIT_CODE_NO_CSV = 2
EXIT_CODE_ERROR = 1

LOG_FILE = LOG_DIR / "daily_report.log"
LOG_MAX_BYTES = 2_000_000
LOG_BACKUP_COUNT = 5
