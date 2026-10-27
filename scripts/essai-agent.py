#!/usr/bin/env python3
"""Essai d'un agent sur de VRAIS documents, sans l'interface et sans rien divulguer.

    assistant/.venv/bin/python scripts/essai-agent.py <dossier-des-documents> "<demande>" [--sortie <dossier>] [--messages]

Prend les .xlsx / .docx du dossier (copies : les originaux ne sont jamais touches), lance l'agent avec le LLM de llm.env et
assistant/reglages.env, comme le fait le site. Affiche seulement : les outils appeles et leur statut, le nombre de fichiers
produits et leur nom. Le texte de la reponse et les resumes d'outils (qui peuvent contenir des donnees reelles) ne sont
affiches qu'avec --messages, pour LECTURE LOCALE par l'utilisateur : ne jamais les coller dans un rapport.
Les fichiers produits restent dans --sortie (defaut : essai-agent-sortie/ a cote du dossier) pour que l'utilisateur les ouvre.
"""
from __future__ import annotations

import argparse
import shutil
import sys
import tempfile
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(RACINE / "assistant"))

from agents.boucle import executer_boucle  # noqa: E402
from outils.registre import REGISTRE, Contexte  # noqa: E402
from rag import modeles  # noqa: E402
from rag.reglages import charger  # noqa: E402


def main() -> None:
    p = argparse.ArgumentParser()
    p.add_argument("dossier", type=Path)
    p.add_argument("demande")
    p.add_argument("--sortie", type=Path)
    p.add_argument("--messages", action="store_true", help="affiche les textes (donnees reelles possibles) : lecture locale seulement")
    p.add_argument("--historique-vide", action="store_true", help="simule 2 essais rates precedents (reponses vides)")
    a = p.parse_args()
    sources = sorted(f for f in a.dossier.iterdir() if f.suffix.lower() in (".xlsx", ".xlsm", ".csv", ".docx"))
    if not sources:
        sys.exit("Aucun .xlsx / .docx dans le dossier.")
    sortie = a.sortie or a.dossier.parent / "essai-agent-sortie"
    sortie.mkdir(parents=True, exist_ok=True)
    r = charger()
    historique = [m for _ in range(2) for m in ({"role": "user", "content": a.demande}, {"role": "assistant", "content": "​"})] \
        if a.historique_vide else None

    def modele(messages, outils, forcer=False):
        return modeles.discuter_avec_outils_robuste(messages, outils, r, 0, forcer=forcer)

    with tempfile.TemporaryDirectory() as tmp:
        entree = Path(tmp) / "entree"
        entree.mkdir()
        for f in sources:
            shutil.copy2(f, entree / f.name)
        ctx = Contexte(dossier_execution=entree, fichiers_autorises=[f.name for f in sources],
                       outils_autorises=set(REGISTRE), dossier_sortie=sortie)
        print(f"{len(sources)} document(s) lus. Demande envoyee.")
        fin = {}
        for ev in executer_boucle(modele, "", a.demande, ctx, historique=historique):
            if ev["type"] == "etape":
                print(f"  outil {ev['outil']} -> {ev['statut']}" + (f" : {ev['resume']}\n      arguments : {ev['arguments']}" if a.messages else ""))
            elif ev["type"] == "fin":
                fin = ev
    print(f"Fin : {fin.get('statut')} en {fin.get('tours')} tour(s). Fichiers produits : {len(ctx.fichiers_produits)}")
    for nom in ctx.fichiers_produits[:50]:
        print("  -", nom if a.messages else Path(nom).suffix)
    if a.messages:
        print("\nReponse de l'agent :\n" + str(fin.get("message", "")))
    print(f"\nFichiers dans : {sortie}")


if __name__ == "__main__":
    main()
