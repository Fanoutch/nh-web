"""Rapport du banc : tableau comparatif (rapport.md) et détail par question (detail.csv, ouvrable dans Excel)."""
from __future__ import annotations

import csv
from pathlib import Path

from banc.questions import TYPES

COLONNES = ["jeu", "format", "id", "type", "applicable", "trouve", "rang", "trouve_par_mots_cles", "distance_min", "retrouves",
            "reponse", "juste", "fidele", "refus_correct", "note", "justification"]


def _pct(valeurs: list[bool]) -> str:
    return f"{round(100 * sum(valeurs) / len(valeurs))} %" if valeurs else "—"


def _etat(resultats: list[dict]) -> str:
    lignes = [l for r in resultats for l in r["lignes"] if l["applicable"]]
    if not lignes:
        return "**État : ERREUR** — aucune question applicable. Prochaine action : vérifier banc/corpus et questions.csv."
    erreurs = sum(1 for l in lignes if l["note"] == "erreur")
    refus = [l["refus_correct"] for l in lignes if l["note"] == "ok" and l["refus_correct"] is not None]
    if erreurs or (refus and not all(refus)):
        return (f"**État : AVERTISSEMENT** — {erreurs} erreur(s), refus corrects {_pct(refus)}. "
                "Prochaine action : renvoyer rapport.md.")
    return ("**État : OK** — mesures complètes. Prochaine action : comparer aux chiffres de référence "
            "(banc/A-LIRE.md).")


def ecrire_rapport(resultats: list[dict], dossier: Path) -> Path:
    dossier = Path(dossier)
    dossier.mkdir(parents=True, exist_ok=True)
    md = ["# Rapport du banc RAG", "", _etat(resultats), "",
          "| Jeu | Format | Questions | Trouvé (hors absentes) | Rang moyen | Trouvé grâce aux mots-clés | Absentes rejetées | "
          "Seuil proposé | Morceaux | Taille médiane | Petits morceaux | Justes | Fidèles | Refus corrects | "
          "Erreurs | Illisibles |",
          "|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
    for res in resultats:
        app = [l for l in res["lignes"] if l["applicable"]]
        utiles = [l for l in app if l["type"] != "absente"]
        absentes = [l for l in app if l["type"] == "absente"]
        rangs = [l["rang"] for l in utiles if l["rang"]]
        notes = [l for l in app if l["note"] == "ok"]
        m = res["morceaux"]
        md.append(
            f"| {res['jeu']} | {res['format']} | {len(app)} | {_pct([bool(l['trouve']) for l in utiles])} | "
            f"{round(sum(rangs) / len(rangs), 1) if rangs else '—'} | "
            f"{sum(1 for l in utiles if l.get('trouve_par_mots_cles') is True)} | {_pct([bool(l['trouve']) for l in absentes])} | "
            f"{res['seuil_propose'] if res['seuil_propose'] is not None else '—'} | {m['nombre']} | {m['mediane']} | "
            f"{m['petits']} | {_pct([l['juste'] for l in notes if l['juste'] is not None])} | "
            f"{_pct([l['fidele'] for l in notes if l['fidele'] is not None])} | "
            f"{_pct([l['refus_correct'] for l in notes if l['refus_correct'] is not None])} | "
            f"{sum(1 for l in app if l['note'] == 'erreur')} | {sum(1 for l in app if l['note'] == 'illisible')} |")
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
