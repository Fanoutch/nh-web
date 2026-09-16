# %% [markdown]
# # Génération quotidienne Excel à partir d'un CSV
#
# Pipeline : repérer le CSV du jour -> charger le template (formules préservées)
# -> écrire uniquement les cellules d'input -> sauvegarder horodaté -> archiver le CSV.
#
# Exécution : `python daily_report.py` (voir `--help`).
# Les cellules `# %%` permettent de tester chaque étape dans VS Code.

# %%
from __future__ import annotations

import argparse
import json
import logging
import re
import shutil
import sys
from dataclasses import asdict, dataclass
from datetime import date, datetime
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import Any

import pandas as pd
from openpyxl import load_workbook
from openpyxl.cell.cell import MergedCell
from openpyxl.workbook.workbook import Workbook
from openpyxl.worksheet.formula import ArrayFormula, DataTableFormula
from openpyxl.worksheet.worksheet import Worksheet

import config

log = logging.getLogger("daily_report")


# ---------------------------------------------------------------------------
# Exceptions
# ---------------------------------------------------------------------------
class PipelineError(Exception):
    """Erreur fonctionnelle : le run doit s'arrêter sans produire de sortie."""


class FormulaOverwriteError(PipelineError):
    """Tentative d'écriture dans une cellule contenant une formule."""


class MissingColumnsError(PipelineError):
    """Le CSV ne contient pas toutes les colonnes attendues par le mapping."""


# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
def setup_logging(log_file: Path | None = None, level: int = logging.INFO,
                  console_stream=None) -> None:
    """Journalise dans un fichier rotatif + sur la console. Idempotent.

    `console_stream` : sys.stdout par défaut ; passer sys.stderr en mode
    --json-output pour garder stdout propre.
    """
    if getattr(setup_logging, "_configured", False):
        return
    log_file = log_file or config.LOG_FILE
    log_file.parent.mkdir(parents=True, exist_ok=True)
    fmt = logging.Formatter("%(asctime)s | %(levelname)-7s | %(message)s")

    fh = RotatingFileHandler(
        log_file, maxBytes=config.LOG_MAX_BYTES,
        backupCount=config.LOG_BACKUP_COUNT, encoding="utf-8",
    )
    fh.setFormatter(fmt)
    sh = logging.StreamHandler(console_stream or sys.stdout)
    sh.setFormatter(fmt)

    log.setLevel(level)
    log.addHandler(fh)
    log.addHandler(sh)
    setup_logging._configured = True  # type: ignore[attr-defined]


# ---------------------------------------------------------------------------
# 1. Repérage du CSV du jour
# ---------------------------------------------------------------------------
def extract_csv_date(path: Path) -> date | None:
    """Date lue dans le nom du fichier via config.CSV_DATE_REGEX, sinon None."""
    if not config.CSV_DATE_REGEX:
        return None
    m = re.search(config.CSV_DATE_REGEX, path.name)
    if not m:
        return None
    try:
        return datetime.strptime(m.group("date"), config.CSV_DATE_FORMAT).date()
    except ValueError:
        return None


def csv_sort_key(path: Path) -> tuple[date, float]:
    """Tri : date du nom de fichier en priorité, puis date de modification."""
    d = extract_csv_date(path) or date.fromtimestamp(path.stat().st_mtime)
    return (d, path.stat().st_mtime)


def find_latest_csv(incoming_dir: Path | None = None,
                    pattern: str | None = None) -> Path | None:
    """Retourne le CSV le plus récent du dossier entrant, ou None s'il n'y en a pas."""
    incoming_dir = incoming_dir or config.INCOMING_DIR
    pattern = pattern or config.CSV_GLOB
    candidates = [p for p in incoming_dir.glob(pattern) if p.is_file()]
    if not candidates:
        return None
    candidates.sort(key=csv_sort_key)
    if len(candidates) > 1:
        log.warning("%d CSV présents dans %s, le plus récent est retenu : %s",
                    len(candidates), incoming_dir, candidates[-1].name)
    return candidates[-1]


# ---------------------------------------------------------------------------
# 2. Chargement et validation du CSV
# ---------------------------------------------------------------------------
def load_csv(path: Path) -> pd.DataFrame:
    df = pd.read_csv(path, **config.CSV_READ_KWARGS)
    df.columns = [str(c).strip() for c in df.columns]
    log.info("CSV chargé : %s (%d lignes, %d colonnes)", path.name, len(df), len(df.columns))
    return df


def validate_columns(df: pd.DataFrame,
                     required: set[str] | None = None) -> None:
    required = required if required is not None else config.required_csv_columns()
    missing = sorted(required - set(df.columns))
    if missing:
        raise MissingColumnsError(
            f"Colonnes manquantes dans le CSV : {missing}. "
            f"Colonnes disponibles : {list(df.columns)}"
        )


# ---------------------------------------------------------------------------
# 3. Template Excel
# ---------------------------------------------------------------------------
def enrichir_avec_llm(df: pd.DataFrame) -> pd.DataFrame:
    """Ajoute les colonnes `llm.*` au tableau (vides si le LLM est inactif).

    Une ligne dont l'appel échoue reste vide : la dispo est générée quand même,
    l'incident est journalisé. Les colonnes existent toujours, pour qu'un
    mapping qui les référence ne casse pas quand le LLM est désactivé.
    """
    conf = config.LLM
    colonnes = [f"{config.LLM_PREFIXE}{champ}" for champ in conf["champs_produits"]]
    for colonne in colonnes:
        if colonne not in df.columns:
            df[colonne] = ""

    if not conf.get("actif"):
        return df

    import llm_client  # import tardif : le pipeline tourne sans lui si inactif

    echecs = 0
    for index, ligne in df.iterrows():
        try:
            reponse = llm_client.extraire_equipement(ligne.to_dict(), conf)
        except llm_client.LlmError as exc:
            echecs += 1
            if echecs == 1:
                log.warning("LLM injoignable, colonnes %s laissées vides : %s",
                            config.LLM_PREFIXE + "*", exc)
            continue
        for champ, valeur in reponse.items():
            df.at[index, f"{config.LLM_PREFIXE}{champ}"] = valeur

    traitees = len(df) - echecs
    log.info("LLM : %d ligne(s) enrichie(s), %d échec(s)", traitees, echecs)
    return df


def load_template(path: Path | None = None) -> Workbook:
    """Ouvre le template en conservant les formules (data_only=False, jamais True)."""
    path = path or config.TEMPLATE_PATH
    if not path.exists():
        raise PipelineError(f"Template introuvable : {path}")
    wb = load_workbook(path, data_only=False, keep_vba=path.suffix.lower() == ".xlsm")
    log.info("Template chargé : %s (onglets : %s)", path.name, wb.sheetnames)
    return wb


def get_target_sheet(wb: Workbook, name: str | None = None) -> Worksheet:
    name = name if name is not None else config.TARGET_SHEET
    if name is None:
        return wb.worksheets[0]
    if name not in wb.sheetnames:
        raise PipelineError(f"Onglet '{name}' absent du template. Onglets : {wb.sheetnames}")
    return wb[name]


def is_formula_cell(cell) -> bool:
    """Vrai si la cellule contient une formule (classique, matricielle ou table de données)."""
    if isinstance(cell, MergedCell):
        return False
    v = cell.value
    if cell.data_type == "f":
        return True
    if isinstance(v, (ArrayFormula, DataTableFormula)):
        return True
    return isinstance(v, str) and v.startswith("=")


def to_excel_value(value: Any) -> Any:
    """Convertit une valeur pandas/numpy en type natif accepté par openpyxl."""
    if value is None or (isinstance(value, float) and pd.isna(value)):
        return None
    try:
        if pd.isna(value):
            return None
    except (TypeError, ValueError):
        pass
    if isinstance(value, pd.Timestamp):
        return value.to_pydatetime()
    if hasattr(value, "item"):          # numpy scalar -> python natif
        return value.item()
    return value


def write_value(ws: Worksheet, coord: str, value: Any) -> None:
    """Écrit dans une cellule d'input. Refuse toute cellule à formule ou fusionnée."""
    cell = ws[coord]
    if isinstance(cell, MergedCell):
        raise FormulaOverwriteError(
            f"{ws.title}!{coord} fait partie d'une plage fusionnée : "
            "écrire dans la cellule d'ancrage de la fusion."
        )
    if is_formula_cell(cell):
        raise FormulaOverwriteError(
            f"{ws.title}!{coord} contient une formule ({cell.value!r}) : écriture refusée."
        )
    cell.value = to_excel_value(value)


# ---------------------------------------------------------------------------
# 4. Remplissage
# ---------------------------------------------------------------------------
_AGGREGATORS = {
    "first": lambda s: s.iloc[0] if len(s) else None,
    "sum": lambda s: s.sum(),
    "max": lambda s: s.max(),
    "min": lambda s: s.min(),
    "count": lambda s: int(s.count()),
}


@dataclass
class RunResult:
    """Résumé d'un run, sérialisable en JSON pour un appelant externe (site web)."""
    status: str                      # ok | error | no_csv
    output_path: str | None = None
    output_name: str | None = None
    csv_name: str | None = None
    rows_written: int = 0
    cells_written: int = 0
    archived_to: str | None = None
    message: str | None = None

    def to_json(self) -> str:
        return json.dumps(asdict(self), ensure_ascii=False)


def fill_cells(ws: Worksheet, df: pd.DataFrame,
               mapping: dict | None = None) -> int:
    """Applique CELL_MAPPING : une colonne CSV -> une cellule fixe."""
    mapping = mapping if mapping is not None else config.CELL_MAPPING
    written = 0
    for column, spec in mapping.items():
        if isinstance(spec, str):
            coord, agg = spec, "first"
        else:
            coord, agg = spec["cell"], spec.get("agg", "first")
        if agg not in _AGGREGATORS:
            raise PipelineError(f"Agrégat inconnu '{agg}' pour la colonne {column}")
        value = _AGGREGATORS[agg](df[column])
        write_value(ws, coord, value)
        written += 1
    log.info("Cellules uniques écrites : %d", written)
    return written


def fill_table(ws: Worksheet, df: pd.DataFrame,
               mapping: dict | None = None) -> int:
    """Applique TABLE_MAPPING : chaque ligne du CSV -> une ligne Excel."""
    mapping = mapping if mapping is not None else config.TABLE_MAPPING
    start_row: int = mapping["start_row"]
    columns: dict[str, str] = mapping["columns"]
    max_rows: int | None = mapping.get("max_rows")

    if not columns:
        return 0
    if max_rows is not None and len(df) > max_rows:
        raise PipelineError(
            f"Le CSV contient {len(df)} lignes, le template n'en accepte que {max_rows} "
            f"(TABLE_MAPPING['max_rows'])."
        )

    if config.CLEAR_TABLE_BEFORE_WRITE and max_rows is not None:
        for r in range(start_row, start_row + max_rows):
            for col_letter in columns.values():
                write_value(ws, f"{col_letter}{r}", None)

    for i, (_, row) in enumerate(df.iterrows()):
        r = start_row + i
        for csv_col, col_letter in columns.items():
            write_value(ws, f"{col_letter}{r}", row[csv_col])
    log.info("Lignes de tableau écrites : %d (à partir de la ligne %d)", len(df), start_row)
    return len(df)


def fill_workbook(wb: Workbook, df: pd.DataFrame) -> tuple[int, int]:
    """Remplit l'onglet cible. Retourne (cellules uniques écrites, lignes écrites)."""
    ws = get_target_sheet(wb)
    n_cells = fill_cells(ws, df)
    n_rows = fill_table(ws, df)
    if config.FORCE_FULL_CALC_ON_LOAD:
        wb.calculation.fullCalcOnLoad = True
    return n_cells, n_rows


# ---------------------------------------------------------------------------
# 5. Sortie et archivage
# ---------------------------------------------------------------------------
def build_output_path(csv_path: Path, run_ts: datetime,
                      output_dir: Path | None = None,
                      template_path: Path | None = None) -> Path:
    output_dir = output_dir or config.OUTPUT_DIR
    template_path = template_path or config.TEMPLATE_PATH
    csv_date = extract_csv_date(csv_path) or run_ts.date()
    name = config.OUTPUT_NAME_PATTERN.format(
        template_stem=template_path.stem,
        csv_stem=csv_path.stem,
        date=csv_date.isoformat(),
        run_ts=run_ts.strftime("%Y%m%d_%H%M%S"),
    )
    return output_dir / name


def save_workbook(wb: Workbook, out_path: Path) -> Path:
    out_path.parent.mkdir(parents=True, exist_ok=True)
    wb.save(out_path)
    log.info("Fichier généré : %s", out_path)
    return out_path


def archive_csv(csv_path: Path, run_ts: datetime,
                archive_dir: Path | None = None) -> Path:
    """Déplace le CSV traité vers archive/YYYY-MM-DD/<horodatage>_<nom>."""
    archive_dir = archive_dir or config.ARCHIVE_DIR
    day_dir = archive_dir / run_ts.strftime("%Y-%m-%d")
    day_dir.mkdir(parents=True, exist_ok=True)
    dest = day_dir / f"{run_ts.strftime('%Y%m%d_%H%M%S')}_{csv_path.name}"
    shutil.move(str(csv_path), str(dest))
    log.info("CSV archivé : %s", dest)
    return dest


# ---------------------------------------------------------------------------
# 6. Orchestration
# ---------------------------------------------------------------------------
def process(csv_path: Path, *, dry_run: bool = False,
            run_ts: datetime | None = None,
            output_dir: Path | None = None) -> RunResult:
    """Traite un CSV donné de bout en bout. Retourne le résumé du run (status='ok').

    `dry_run` : ne pas archiver le CSV (usage manuel ou appel depuis le site web).
    `output_dir` : dossier de sortie, sinon config.OUTPUT_DIR.
    """
    run_ts = run_ts or datetime.now()
    df = load_csv(csv_path)
    validate_columns(df)
    df = enrichir_avec_llm(df)
    wb = load_template()
    n_cells, n_rows = fill_workbook(wb, df)
    out_path = build_output_path(csv_path, run_ts, output_dir=output_dir)
    save_workbook(wb, out_path)
    archived = None
    if dry_run:
        log.info("CSV non archivé (dry-run / no-archive) : %s", csv_path)
    else:
        archived = archive_csv(csv_path, run_ts)
    return RunResult(
        status="ok", output_path=str(out_path), output_name=out_path.name,
        csv_name=csv_path.name, rows_written=n_rows, cells_written=n_cells,
        archived_to=str(archived) if archived else None,
    )


def run(csv_override: Path | None = None, dry_run: bool = False,
        output_dir: Path | None = None, json_output: bool = False) -> int:
    """Point d'entrée : renvoie le code de sortie destiné au scheduler.

    En mode `json_output`, la dernière ligne de stdout est un JSON RunResult
    et les logs console partent sur stderr (pattern utilisé par nh-web).
    """
    setup_logging(console_stream=sys.stderr if json_output else None)
    started = datetime.now()
    log.info("=== RUN START ===")
    result: RunResult
    code: int
    try:
        csv_path = csv_override or find_latest_csv()
        if csv_path is None:
            log.warning("Aucun CSV trouvé dans %s (pattern %s). Run ignoré.",
                        config.INCOMING_DIR, config.CSV_GLOB)
            log.info("=== RUN SKIPPED | no_csv | %.2fs ===", (datetime.now() - started).total_seconds())
            result = RunResult(status="no_csv", message=f"Aucun CSV dans {config.INCOMING_DIR}")
            code = config.EXIT_CODE_NO_CSV
        else:
            if not csv_path.exists():
                raise PipelineError(f"CSV introuvable : {csv_path}")
            result = process(csv_path, dry_run=dry_run, run_ts=started, output_dir=output_dir)
            log.info("=== RUN OK | csv=%s | out=%s | %.2fs ===",
                     csv_path.name, result.output_name, (datetime.now() - started).total_seconds())
            code = 0
    except PipelineError as e:
        log.error("=== RUN FAILED | %s ===", e)
        result = RunResult(status="error", message=str(e),
                           csv_name=csv_override.name if csv_override else None)
        code = config.EXIT_CODE_ERROR
    except Exception as e:  # noqa: BLE001
        log.exception("=== RUN FAILED | erreur inattendue ===")
        result = RunResult(status="error", message=f"Erreur inattendue : {type(e).__name__}: {e}",
                           csv_name=csv_override.name if csv_override else None)
        code = config.EXIT_CODE_ERROR

    if json_output:
        print(result.to_json(), flush=True)
    return code


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Génère l'Excel du jour à partir du CSV entrant.")
    parser.add_argument("--csv", type=Path, default=None,
                        help="Traiter ce CSV précis au lieu de scanner le dossier entrant.")
    parser.add_argument("--dry-run", "--no-archive", dest="dry_run", action="store_true",
                        help="Génère l'Excel mais laisse le CSV en place (pas d'archivage).")
    parser.add_argument("--output-dir", type=Path, default=None,
                        help="Dossier de sortie (défaut : config.OUTPUT_DIR).")
    parser.add_argument("--json-output", action="store_true",
                        help="Imprime un résumé JSON en dernière ligne de stdout (appel depuis nh-web).")
    args = parser.parse_args(argv)
    return run(csv_override=args.csv, dry_run=args.dry_run,
               output_dir=args.output_dir, json_output=args.json_output)


if __name__ == "__main__":
    sys.exit(main())

# %% ----------------------------------------------------------------------
# Zone de test interactive (VS Code : exécuter cellule par cellule)
# ----------------------------------------------------------------------
# setup_logging()
# csv_path = find_latest_csv(); print(csv_path)
# df = load_csv(csv_path); df.head()
# validate_columns(df)
# wb = load_template(); ws = get_target_sheet(wb)
# fill_workbook(wb, df)
# save_workbook(wb, build_output_path(csv_path, datetime.now()))
