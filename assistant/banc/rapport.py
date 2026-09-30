"""Rapport du banc : tableau comparatif (rapport.md) et détail par question (detail.csv, ouvrable dans Excel)."""
from __future__ import annotations

import csv
from pathlib import Path

from banc.questions import TYPES

COLONNES = ["jeu", "format", "id", "type", "applicable", "trouve", "rang", "distance_min", "retrouves",
            "reponse", "juste", "fidele", "refus_correct", "note", "justification"]


def _pct(valeurs: list[bool]) -> str:
    return f"{round(100 * sum(valeurs) / len(valeurs))} %" if valeurs else "—"


def ecrire_rapport(resultats: list[dict], dossier: Path) -> Path:
    dossier = Path(dossier)
    dossier.mkdir(parents=True, exist_ok=True)
    md = ["# Rapport du banc RAG", "",
          "| Jeu | Format | Questions | Trouvé (hors absentes) | Rang moyen | Absentes rejetées | "
          "Seuil proposé | Morceaux | Taille médiane | Petits morceaux | Justes | Fidèles | Refus corrects |",
          "|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
    for res in resultats:
        app = [l for l in res["lignes"] if l["applicable"]]
        utiles = [l for l in app if l["type"] != "absente"]
        absentes = [l for l in app if l["type"] == "absente"]
        rangs = [l["rang"] for l in utiles if l["rang"]]
        notes = [l for l in app if l["note"] == "ok"]
        m = res["morceaux"]
        md.append(
            f"| {res['jeu']} | {res['format']} | {len(app)} | {_pct([bool(l['trouve']) for l in utiles])} | "
            f"{round(sum(rangs) / len(rangs), 1) if rangs else '—'} | {_pct([bool(l['trouve']) for l in absentes])} | "
            f"{res['seuil_propose'] if res['seuil_propose'] is not None else '—'} | {m['nombre']} | {m['mediane']} | "
            f"{m['petits']} | {_pct([l['juste'] for l in notes if l['juste'] is not None])} | "
            f"{_pct([l['fidele'] for l in notes if l['fidele'] is not None])} | "
            f"{_pct([l['refus_correct'] for l in notes if l['refus_correct'] is not None])} |")
    md += ["", "## Trouvé par type de question", "",
           "| Jeu | Format | " + " | ".join(TYPES) + " |", "|---|---|" + "---|" * len(TYPES)]
    for res in resultats:
        cellules = []
        for t in TYPES:
            lignes = [l for l in res["lignes"] if l["applicable"] and l["type"] == t]
            cellules.append(_pct([bool(l["trouve"]) for l in lignes]))
        md.append(f"| {res['jeu']} | {res['format']} | " + " | ".join(cellules) + " |")
    (dossier / "rapport.md").write_text("\n".join(md) + "\n", encoding="utf-8")

    with (dossier / "detail.csv").open("w", encoding="utf-8-sig", newline="") as f:
        ecrivain = csv.DictWriter(f, fieldnames=COLONNES, delimiter=";")
        ecrivain.writeheader()
        for res in resultats:
            for l in res["lignes"]:
                ecrivain.writerow({"jeu": res["jeu"], "format": res["format"], **{k: l.get(k, "") for k in COLONNES[2:]}})
    return dossier
