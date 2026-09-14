"""Crée l'archive à transporter sur le poste de travail (sans venv, sorties, logs, caches).

Usage : python make_release.py
"""
from __future__ import annotations

import sys
import zipfile
from datetime import date
from pathlib import Path

BASE = Path(__file__).resolve().parent
EXCLUDE_DIRS = {".venv", "__pycache__", ".pytest_cache", ".git"}
EXCLUDE_UNDER = {"data/output", "data/archive", "logs"}   # on garde .gitkeep, rien d'autre


def wanted(p: Path) -> bool:
    rel = p.relative_to(BASE)
    if any(part in EXCLUDE_DIRS for part in rel.parts):
        return False
    if p.suffix == ".zip":
        return False
    rel_s = rel.as_posix()
    if any(rel_s.startswith(d + "/") for d in EXCLUDE_UNDER) and p.name != ".gitkeep":
        return False
    if rel_s.startswith("data/incoming/") and p.suffix == ".csv":
        return False
    return True


def main() -> int:
    out = BASE / f"BMN_{date.today():%Y%m%d}.zip"
    files = sorted(p for p in BASE.rglob("*") if p.is_file() and wanted(p))
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for p in files:
            z.write(p, f"BMN/{p.relative_to(BASE).as_posix()}")
    print(f"Archive créée : {out} ({len(files)} fichiers)")
    for p in files:
        print("  ", p.relative_to(BASE).as_posix())
    return 0


if __name__ == "__main__":
    sys.exit(main())
