"""Banc d'évaluation du RAG.

Usage (depuis assistant/) :
  .venv/bin/python -m banc.banc --reglages actuel,fusion --formats pdf,docx,txt [--reponses]
"""
from __future__ import annotations

import argparse
import sys
from datetime import datetime
from pathlib import Path

from banc.cache import envelopper_embedder
from banc.execution import DOSSIER_JEUX, executer, reglages_du_jeu
from banc.questions import charger_questions
from banc.rapport import ecrire_rapport
from rag import modeles
from rag.reglages import RACINE_NH_WEB

DOSSIER = Path(__file__).resolve().parent


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Compare des jeux de réglages de l'assistant sur le corpus du banc.")
    p.add_argument("--reglages", default="actuel", help="jeux séparés par des virgules (fichiers de banc/reglages/)")
    p.add_argument("--formats", default="pdf,docx,txt")
    p.add_argument("--reponses", action="store_true", help="fait aussi répondre le LLM et juger chaque réponse (plus lent)")
    p.add_argument("--llm-env", type=Path, default=RACINE_NH_WEB / "llm.env")
    args = p.parse_args(argv)

    jeux = [j.strip() for j in args.reglages.split(",") if j.strip()]
    inconnus = [j for j in jeux if not (DOSSIER_JEUX / f"{j}.env").exists()]
    if inconnus:
        print(f"Jeu(x) de réglages introuvable(s) : {', '.join(inconnus)} (voir banc/reglages/).")
        return 2
    questions = charger_questions(DOSSIER / "questions.csv")
    resultats = []
    for jeu in jeux:
        r = reglages_du_jeu(jeu, args.llm_env)
        if args.reponses and not r.llm_actif:
            print("Le LLM est désactivé (LLM_ACTIF=false ou absent dans llm.env) : impossible de mesurer "
                  "les réponses. Mettez LLM_ACTIF=true puis relancez.")
            return 2
        modeles.embedder = envelopper_embedder(ORIGINE_EMBEDDER, DOSSIER / "resultats" / "cache", r.embed_modele)
        for format_ in [f.strip() for f in args.formats.split(",") if f.strip()]:
            print(f"… {jeu} / {format_}", flush=True)
            resultats.append(executer(jeu, r, format_, questions, DOSSIER / "corpus", args.reponses))
    dossier = ecrire_rapport(resultats, DOSSIER / "resultats" / datetime.now().strftime("%Y-%m-%d_%H%M"))
    print(f"Rapport : {dossier / 'rapport.md'}")
    return 0


ORIGINE_EMBEDDER = modeles.embedder

if __name__ == "__main__":
    sys.exit(main())
