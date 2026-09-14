# %% [markdown]
# # Inspection d'un CSV réel
#
# À lancer sur le vrai fichier dès réception : détecte séparateur et
# encodage, affiche colonnes, types et premières lignes, puis imprime un
# squelette de mapping à coller dans `config.py`.
#
# Usage : `python inspect_csv.py chemin/vers/fichier.csv [--rows 5]`

# %%
from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path

import pandas as pd

ENCODINGS = ("utf-8-sig", "utf-8", "cp1252", "latin-1")
SEPARATORS = (";", ",", "\t", "|")


def detect_encoding(path: Path) -> str:
    raw = path.read_bytes()[:200_000]
    for enc in ENCODINGS:
        try:
            raw.decode(enc)
            return enc
        except UnicodeDecodeError:
            continue
    return "latin-1"


def detect_separator(path: Path, encoding: str) -> str:
    sample = path.read_text(encoding=encoding)[:20_000]
    try:
        return csv.Sniffer().sniff(sample, delimiters="".join(SEPARATORS)).delimiter
    except csv.Error:
        first = sample.splitlines()[0] if sample else ""
        return max(SEPARATORS, key=first.count)


def text_columns(df: pd.DataFrame) -> list[str]:
    """Colonnes texte, compatible pandas 2 (object) et pandas 3 (str)."""
    return [c for c in df.columns if pd.api.types.is_string_dtype(df[c])
            or pd.api.types.is_object_dtype(df[c])]


def detect_decimal(df: pd.DataFrame) -> str:
    """',' si des colonnes texte ressemblent à des nombres à virgule."""
    for col in text_columns(df):
        s = df[col].dropna().astype(str).head(50)
        if len(s) and s.str.fullmatch(r"-?\d+,\d+").mean() > 0.8:
            return ","
    return "."


def print_mapping_skeleton(df: pd.DataFrame) -> None:
    cols = list(df.columns)
    print("\n== Squelette de mapping à adapter dans config.py ==")
    print("# Colonnes uniques (en-tête) : choisir celles qui vont dans une cellule fixe")
    print("CELL_MAPPING = {")
    for c in cols[:3]:
        print(f'    "{c}": "B2",   # A_DEFINIR : cellule cible')
    print("}")
    print("\n# Tableau : une ligne CSV -> une ligne Excel")
    print("TABLE_MAPPING = {")
    print('    "start_row": 7,   # A_DEFINIR : première ligne de données du template')
    print('    "columns": {')
    for c, letter in zip(cols, "ABCDEFGHIJKLMNOPQRSTUVWXYZ"):
        print(f'        "{c}": "{letter}",')
    print("    },")
    print('    "max_rows": 50,   # A_DEFINIR : capacité du tableau dans le template')
    print("}")


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Inspecte un CSV et propose un mapping.")
    p.add_argument("path", type=Path)
    p.add_argument("--rows", type=int, default=5)
    a = p.parse_args(argv)
    if not a.path.exists():
        print(f"Fichier introuvable : {a.path}", file=sys.stderr)
        return 1

    enc = detect_encoding(a.path)
    sep = detect_separator(a.path, enc)
    df = pd.read_csv(a.path, sep=sep, encoding=enc)
    df.columns = [str(c).strip() for c in df.columns]
    dec = detect_decimal(df)
    if dec == ",":
        df = pd.read_csv(a.path, sep=sep, encoding=enc, decimal=",")
        df.columns = [str(c).strip() for c in df.columns]

    print(f"Fichier   : {a.path}  ({a.path.stat().st_size} octets)")
    print(f"Encodage  : {enc}")
    print(f"Séparateur: {sep!r}   Décimale: {dec!r}")
    print(f"Lignes    : {len(df)}   Colonnes : {len(df.columns)}")

    print("\n== Colonnes ==")
    for c in df.columns:
        s = df[c]
        ex = s.dropna().astype(str).head(2).tolist()
        print(f"  {c!r:40} {str(s.dtype):10} nuls={int(s.isna().sum()):4}  ex={ex}")

    print(f"\n== {a.rows} premières lignes ==")
    with pd.option_context("display.max_columns", 50, "display.width", 200):
        print(df.head(a.rows).to_string())

    print("\n== À coller dans config.py ==")
    print("CSV_READ_KWARGS = {")
    print(f'    "sep": {sep!r},')
    print(f'    "encoding": {enc!r},')
    print(f'    "decimal": {dec!r},')
    date_like = [c for c in text_columns(df)
                 if df[c].dropna().astype(str).head(20).str.match(r"\d{2}/\d{2}/\d{4}|\d{4}-\d{2}-\d{2}").mean() > 0.8]
    if date_like:
        print(f'    "parse_dates": {date_like},   # colonnes détectées comme dates')
        print('    "dayfirst": True,   # à retirer si format AAAA-MM-JJ')
    print("}")
    print_mapping_skeleton(df)
    return 0


if __name__ == "__main__":
    sys.exit(main())
