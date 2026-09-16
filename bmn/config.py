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
    """Colonnes CSV attendues, déduites des deux mappings.

    Les colonnes `llm.*` sont produites par le modèle (cf. LLM ci-dessous),
    elles ne sont donc pas attendues dans le CSV.
    """
    toutes = set(CELL_MAPPING) | set(TABLE_MAPPING["columns"])
    return {c for c in toutes if not str(c).startswith(LLM_PREFIXE)}


# ---------------------------------------------------------------------------
# Remplissage par blocs machine (template « dispo »)
# ---------------------------------------------------------------------------
# Le template de dispo ne se remplit pas comme un tableau plat : il répète un
# BLOC par machine (NH01, NH02, ...), toujours organisé pareil. On repère les
# blocs par le nom de machine écrit en colonne A, puis on écrit à des
# décalages fixes à l'intérieur du bloc.
#
# Deux sortes de cibles :
#   - « valeurs » : repérées par leur INTITULÉ dans le template (colonne AQ),
#     la valeur allant dans la colonne d'à côté (AU). Repérer par intitulé
#     plutôt que par position protège des écarts entre machines (FIAA, RTRBK,
#     Cable RHA... ne sont pas sur toutes) et d'une ligne insérée un jour.
#   - « listes » : n emplacements consécutifs (HIL, CIL), chaque ligne portant
#     plusieurs colonnes (date, équipement, référence).
#
# La source (CSV ou JSON) fournit, par machine : le nom de la machine, ses
# compteurs, et ses lignes HIL / CIL. Voir `SOURCE_*` ci-dessous.
BLOCS: dict = {
    "actif": False,              # True = remplissage par blocs au lieu du tableau plat
    "feuille": "DISPO",          # onglet contenant les blocs
    "colonne_machine": "A",      # colonne où est écrit NH01, NH02, ...
    "motif_machine": r"NH\s?\d+",

    # Champs de la source
    "champ_machine": "machine",  # colonne/clé portant NH01, NH02, ...
    "champ_zone": "zone",        # colonne/clé valant "hil" ou "cil" (vide = ligne de compteurs)

    # Valeurs repérées par intitulé : champ de la source -> intitulé du template
    "valeurs": {
        "colonne_libelle": "AQ",
        "colonne_valeur": "AU",
        "champs": {
            "fh": "FH",
            "att": "ATT",
            "treuil": "TREUI",
            "apu_oph": "APU OPH",
            "apu_opc": "APU OPC",
            # "fiaa": "FIAA",            # seulement 10 machines sur 26
            # "rtrbk": "RTRBK",          # absent de certaines machines
            # "cable_rha": "Cable RHA",
            # "drum_cable": "Drum & Cable",
        },
    },

    # Listes : decalage = première ligne du bloc, emplacements = capacité
    "listes": {
        # Pour HIL, le libellé d'équipement est produit par le LLM à partir des
        # textes « travail demandé / effectué » : on prend le champ de la source
        # s'il est renseigné, sinon la réponse du modèle (premier non vide).
        "hil": {"decalage": 6, "emplacements": 6,
                "colonnes": {
                    "date": "I",
                    "equipement": {"colonne": "L", "champs": ["equipement", "llm.equipement"]},
                    "ref": "R",
                }},
        "cil": {"decalage": 12, "emplacements": 5,
                "colonnes": {"date": "I", "equipement": "L", "ref": "R"}},
    },

    # Champs à convertir en vraies dates Excel (et non en texte).
    "champs_dates": ["date"],

    # Vider les emplacements d'une machine avant d'y écrire (évite qu'une
    # ancienne ligne subsiste sous les nouvelles).
    "vider_avant_ecriture": True,
}


# ---------------------------------------------------------------------------
# Extraction par un LLM (équipement incriminé)
# ---------------------------------------------------------------------------
# Les champs produits par le modèle deviennent des colonnes `llm.<champ>`,
# utilisables dans CELL_MAPPING / TABLE_MAPPING comme une colonne du CSV :
#     TABLE_MAPPING["columns"]["llm.equipement"] = "F"
#
# Tant que `actif` est False, aucun appel n'est fait et ces colonnes restent
# vides : le pipeline se comporte exactement comme avant.
LLM_PREFIXE = "llm."

LLM: dict = {
    "actif": False,          # True = interroger le modèle pour chaque ligne
    "hors_ligne": False,     # True = réponse simulée, pratique pour tester

    # Service : Ollama http://127.0.0.1:11434/v1 | LM Studio http://127.0.0.1:1234/v1
    "base_url": "http://127.0.0.1:11434/v1",
    "modele": "A_DEFINIR",   # nom exact du modèle tel que le service l'expose
    "cle_api": "",           # si le service en demande une
    "timeout": 120,          # secondes, par ligne
    "temperature": 0,        # 0 = réponse la plus stable

    # N'interroger le modèle que sur certaines lignes (économise les appels).
    # Ici : uniquement les lignes HIL, qui seules ont besoin d'un libellé.
    "filtre": {"champ": "zone", "valeurs": ["hil"]},

    # Champs de la source envoyés au modèle. Liste vide = la ligne entière.
    "champs_envoyes": ["travail_demande", "travail_effectue"],

    # Exemples de libellés tels qu'ils sont saisis à la main dans la dispo :
    # ils servent à caler le style de la réponse (longueur, ton, abréviations).
    "exemples": [
        "PDU (P)",
        "Engine Anti Icing",
        "ECS (PP)",
        "Tactical RADAR",
        "RHEAS",
    ],

    # Champs attendus en retour -> colonnes llm.equipement, llm.indice, ...
    # `equipement` est celui qui est écrit dans la colonne HIL du template.
    "champs_produits": ["equipement", "indice", "justification"],

    "prompt_systeme": (
        "Tu es technicien de maintenance aéronautique et tu remplis le tableau de "
        "disponibilité de la flottille. Tu reçois le « travail demandé » et le "
        "« travail effectué » d'une intervention. Ce sont des SAISIES HUMAINES "
        "brutes : style télégraphique, abréviations, fautes de frappe, majuscules "
        "aléatoires, et le plus souvent une mention du type « mise en HIL <pièce> ».\n"
        "Tu en extrais UNIQUEMENT LE NOM DE LA PIÈCE incriminée, celle qui justifie "
        "la mise en HIL, tel qu'il doit apparaître dans le tableau de dispo : "
        "quelques mots, sans phrase, sans verbe, sans « mise en HIL », en gardant "
        "les abréviations métier et la mention entre parenthèses quand elle existe. "
        "Exemples de noms de pièce tels qu'ils figurent dans le tableau : {exemples}.\n"
        "Tu réponds UNIQUEMENT par un objet JSON valide, sans texte autour, sans "
        "bloc de code, avec exactement ces clés :\n"
        '{{"equipement": "libellé court", "indice": "haut|moyen|faible", '
        '"justification": "une phrase courte"}}\n'
        'Si le texte ne permet pas d\'identifier l\'équipement, réponds '
        '{{"equipement": "indetermine", "indice": "faible", "justification": "..."}}.'
    ),
    "gabarit_utilisateur": (
        "Travail demandé et travail effectué :\n\n{enregistrement}\n\n"
        "Quel équipement a été mis en HIL ?"
    ),
}


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


# ---------------------------------------------------------------------------
# Réglages locaux : bmn/reglages.env (voir reglages.env.example)
# ---------------------------------------------------------------------------
# Tout ce qui change entre ce serveur et le bureau est lu dans UN fichier,
# ignoré par git : template réel, activation des blocs, connexion au LLM.
# Changer d'environnement ne touche donc jamais au code.
#
# Priorité : variables d'environnement > reglages.env > valeurs par défaut.
# La variable BMN_REGLAGES désigne un autre fichier, ou « aucun » pour ignorer
# tout réglage local (utilisé par les tests du site).
_BOOLEEN = lambda v: str(v).strip().lower() in ("1", "true", "oui", "yes", "vrai")

_CLES_LLM = {
    "LLM_ACTIF": ("actif", _BOOLEEN),
    "LLM_BASE_URL": ("base_url", str),
    "LLM_MODELE": ("modele", str),
    "LLM_CLE_API": ("cle_api", str),
    "LLM_TIMEOUT": ("timeout", lambda v: int(float(v))),
    "LLM_TEMPERATURE": ("temperature", float),
}

_CLES_DISPO = {
    "DISPO_TEMPLATE": ("template",
                       lambda v: Path(v) if Path(v).is_absolute() else BASE_DIR / v),
    "DISPO_BLOCS_ACTIF": ("blocs_actif", _BOOLEEN),
}


def fichier_reglages(environ=None) -> Path | None:
    """Fichier de réglages à lire : défaut, désigné par BMN_REGLAGES, ou aucun."""
    import os

    environ = os.environ if environ is None else environ
    choix = (environ.get("BMN_REGLAGES") or "").strip()
    if choix.lower() in ("aucun", "none", "0"):
        return None
    return Path(choix) if choix else BASE_DIR / "reglages.env"


def lire_reglages(chemin: Path | None, environ=None) -> tuple[dict, dict]:
    """Retourne (réglages LLM, réglages dispo) lus dans le fichier puis l'environnement."""
    import os

    environ = os.environ if environ is None else environ
    if chemin is None:
        return {}, {}

    brut: dict[str, str] = {}
    if chemin.exists():
        for ligne in chemin.read_text(encoding="utf-8-sig").splitlines():
            ligne = ligne.strip()
            if not ligne or ligne.startswith("#") or "=" not in ligne:
                continue
            cle, valeur = ligne.split("=", 1)
            valeur = valeur.split(" #", 1)[0]          # commentaire en fin de ligne
            brut[cle.strip()] = valeur.strip().strip('"').strip("'")
    for cle in (*_CLES_LLM, *_CLES_DISPO):
        if environ.get(cle):
            brut[cle] = environ[cle]

    def convertir(cles):
        return {champ: conv(brut[cle]) for cle, (champ, conv) in cles.items()
                if brut.get(cle, "") != ""}

    return convertir(_CLES_LLM), convertir(_CLES_DISPO)


REGLAGES_FILE = fichier_reglages()
_llm_local, _dispo_local = lire_reglages(REGLAGES_FILE)
LLM.update(_llm_local)
if "template" in _dispo_local:
    TEMPLATE_PATH = _dispo_local["template"]
if "blocs_actif" in _dispo_local:
    BLOCS["actif"] = _dispo_local["blocs_actif"]
