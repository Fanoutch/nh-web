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
    p.add_argument("demande", help="la demande, comme l'utilisateur l'ecrirait (\"\" avec --consignes)")
    p.add_argument("--sortie", type=Path)
    p.add_argument("--messages", action="store_true", help="affiche les textes (donnees reelles possibles) : lecture locale seulement")
    p.add_argument("--consignes", type=Path, help="fichier texte des consignes d'un agent du catalogue (ex. database/agents-catalogue/dacssi-certificats/consignes.txt) : "
                   "rejoue ce que le site envoie au lancement ; la demande peut alors etre \"\"")
    p.add_argument("--forme", action="store_true", help="affiche la FORME des appels d'outils (noms des champs, tailles), jamais une valeur : sans risque dans un rapport")
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

    demande = a.demande or "Executez la recette validee sur les documents disponibles."
    consignes = ""
    if a.consignes:
        consignes = a.consignes.read_text(encoding="utf-8").strip() + (
            "\n\nEXECUTION : appliquez ces consignes aux documents deposes par l'utilisateur, sans lui poser de question "
            "et sans improviser au-dela de ce qu'elles decrivent.")

    def forme(v):
        """Structure d'une valeur, sans son contenu."""
        if isinstance(v, dict):
            return "{" + ", ".join(f"{k}: {forme(x)}" for k, x in v.items()) + "}"
        if isinstance(v, list):
            return f"[{len(v)} x {forme(v[0])}]" if v else "[]"
        if isinstance(v, str):
            return f"texte({len(v)})"
        return type(v).__name__

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
        for ev in executer_boucle(modele, consignes, demande, ctx, historique=historique):
            if ev["type"] == "etape":
                print(f"  outil {ev['outil']} -> {ev['statut']}" + (f" : {ev['resume']}\n      arguments : {ev['arguments']}" if a.messages else ""))
                if a.forme:
                    print(f"      forme : {forme(ev['arguments'])}")
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
