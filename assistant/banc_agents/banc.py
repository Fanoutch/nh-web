"""Banc des agents : fait tourner la vraie boucle d'agent avec le vrai LLM sur des documents fabriqués.

Usage (depuis assistant/) :
  .venv/bin/python -m banc_agents.banc [--seulement E01,W03] [--repetitions 3] [--famille word-lecture]

Mesure : le modèle choisit-il les bons outils d'après la demande ? Il ne dit rien de la qualité du texte."""
from __future__ import annotations

import argparse
import csv
import hashlib
import shutil
import sys
import tempfile
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Callable

from agents.boucle import executer_boucle
from banc_agents import fixtures
from banc_agents.scenarios import SCENARIOS, Resultat, Scenario
import outils  # noqa: F401 — enregistre les outils
from outils.registre import REGISTRE, Contexte
from rag import modeles
from rag.reglages import RACINE_NH_WEB, Reglages, charger

DOSSIER = Path(__file__).resolve().parent
Modele = Callable[[list, list], dict]


@dataclass
class Verdict:
    scenario: Scenario
    essai: int
    reussi: bool
    raisons: list[str]
    outils: list[str]
    message: str
    statut: str


def _empreintes(dossier: Path) -> dict[str, str]:
    return {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in dossier.iterdir() if p.is_file()}


def evaluer(s: Scenario, r: Resultat) -> list[str]:
    """Liste des raisons d'échec (vide = réussi)."""
    raisons = []
    appeles = set(r.outils)
    hors = sorted(appeles - s.permis)
    if hors:
        raisons.append("outil(s) non permis : " + ", ".join(hors))
    inter = sorted(appeles & s.interdit)
    if inter:
        raisons.append("outil(s) interdit(s) : " + ", ".join(inter))
    for groupe in s.exige:
        if not appeles & groupe:
            raisons.append("aucun de ces outils appelé : " + ", ".join(sorted(groupe)))
    if not r.sources_intactes:
        raisons.append("un document d'origine a été modifié")
    if r.statut != "termine":
        raisons.append(f"l'agent s'est arrêté : {r.message[:120]}")
    elif s.verifier is not None and not s.verifier(r):
        raisons.append("la réponse ou le fichier produit n'est pas celui attendu")
    return raisons


def lancer(s: Scenario, modele: Modele, essai: int = 1) -> Verdict:
    with tempfile.TemporaryDirectory() as tmp:
        tmp = Path(tmp)
        entree, sortie = tmp / "entree", tmp / "sortie"
        noms = fixtures.fabriquer(entree)
        fichiers = [n for n in noms if s.fichiers is None or n in s.fichiers]
        for n in noms:
            if n not in fichiers:
                (entree / n).unlink()
        avant = _empreintes(entree)
        ctx = Contexte(dossier_execution=entree, fichiers_autorises=fichiers,
                       outils_autorises=set(s.outils_autorises or REGISTRE), dossier_sortie=sortie)
        appels, message, statut = [], "", "arret"
        for ev in executer_boucle(modele, "", s.demande, ctx):
            if ev["type"] == "etape":
                appels.append(ev["outil"])
            elif ev["type"] == "fin":
                message, statut = ev["message"], ev["statut"]
        resultat = Resultat(message, statut, appels, list(ctx.fichiers_produits), _empreintes(entree) == avant)
    raisons = evaluer(s, resultat)
    return Verdict(s, essai, not raisons, raisons, appels, message, statut)


def ecrire_rapport(verdicts: list[Verdict], dossier: Path, modele_nom: str) -> Path:
    dossier.mkdir(parents=True, exist_ok=True)
    total = len(verdicts)
    bons = sum(v.reussi for v in verdicts)
    familles: dict[str, list[Verdict]] = {}
    for v in verdicts:
        familles.setdefault(v.scenario.famille, []).append(v)
    lignes = [f"# Banc des agents — {datetime.now():%Y-%m-%d %H:%M}", "",
              f"Modèle : {modele_nom} · {total} essai(s) · **{bons} réussi(s) ({100 * bons // max(total, 1)} %)**", "",
              "| Famille | Réussis |", "|---|---|"]
    for f, vs in sorted(familles.items()):
        lignes.append(f"| {f} | {sum(v.reussi for v in vs)} / {len(vs)} |")
    echecs = [v for v in verdicts if not v.reussi]
    lignes += ["", f"## Échecs ({len(echecs)})", ""]
    for v in echecs:
        lignes += [f"### {v.scenario.id} (essai {v.essai}) — {v.scenario.demande}",
                   f"- Outils appelés : {', '.join(v.outils) or '(aucun)'}",
                   *[f"- {r}" for r in v.raisons], f"- Réponse : {v.message[:300]}", ""]
    (dossier / "rapport.md").write_text("\n".join(lignes), encoding="utf-8")
    with (dossier / "detail.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["id", "famille", "essai", "reussi", "outils", "raisons", "reponse"])
        for v in verdicts:
            w.writerow([v.scenario.id, v.scenario.famille, v.essai, v.reussi, " ".join(v.outils),
                        " | ".join(v.raisons), v.message[:300]])
    return dossier


def modele_reel(r: Reglages) -> Modele:
    return lambda messages, declares: modeles.discuter_avec_outils(messages, declares, r, 0)


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Le LLM choisit-il les bons outils d'après la demande ?")
    p.add_argument("--seulement", default="", help="identifiants séparés par des virgules (E01,W03…)")
    p.add_argument("--famille", default="")
    p.add_argument("--repetitions", type=int, default=1)
    args = p.parse_args(argv)
    r = charger()
    if not r.llm_actif:
        print("Le LLM est désactivé (LLM_ACTIF=false ou absent dans llm.env) : impossible de lancer le banc.")
        return 2
    voulus = {x.strip() for x in args.seulement.split(",") if x.strip()}
    choisis = [s for s in SCENARIOS if (not voulus or s.id in voulus) and (not args.famille or s.famille == args.famille)]
    if not choisis:
        print("Aucun scénario ne correspond.")
        return 2
    verdicts = []
    for s in choisis:
        for essai in range(1, args.repetitions + 1):
            try:
                v = lancer(s, modele_reel(r), essai)
            except modeles.ModeleIndisponible as exc:
                v = Verdict(s, essai, False, [f"LLM indisponible : {str(exc)[:150]}"], [], "", "erreur")
            verdicts.append(v)
            print(f"{'OK ' if v.reussi else 'KO '} {s.id} #{essai}  {' > '.join(v.outils) or '(aucun outil)'}", flush=True)
    dossier = ecrire_rapport(verdicts, DOSSIER / "resultats" / datetime.now().strftime("%Y-%m-%d_%H%M"), r.llm_modele)
    print(f"Rapport : {dossier / 'rapport.md'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
