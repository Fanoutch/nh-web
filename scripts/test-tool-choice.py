#!/usr/bin/env python3
"""Teste si le LLM configure accepte d'etre FORCE a appeler un outil (tool_choice = required).

A lancer a la racine du projet : python3 scripts/test-tool-choice.py
Lit llm.env, n'ecrit rien, n'affiche jamais la cle. Bibliotheque standard uniquement.

Resultats : ACCEPTE (le serveur renvoie un appel d'outil) / REFUSE (erreur du serveur : l'agent s'en passe tout seul,
c'est sans gravite) / IGNORE (le serveur repond sans appel d'outil). Ecrire le resultat au rapport, sans rien modifier.
"""
from __future__ import annotations

import importlib.util
import json
import sys
import urllib.error
import urllib.request
from pathlib import Path

ICI = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("test_outils_llm", ICI / "test-outils-llm.py")
base_test = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base_test)


def main() -> None:
    env = base_test.lire_env(base_test.FICHIER_ENV)
    base, modele, cle = env.get("LLM_BASE_URL", "").rstrip("/"), env.get("LLM_MODELE", ""), env.get("LLM_CLE_API", "")
    if not base or not modele:
        sys.exit("LLM_BASE_URL ou LLM_MODELE manquant dans llm.env")
    charge = {"model": modele, "messages": [{"role": "user", "content": "Combien de lignes contient stock.xlsx ?"}],
              "tools": [base_test.OUTIL], "tool_choice": "required", "temperature": 0}
    requete = urllib.request.Request(f"{base}/chat/completions", data=json.dumps(charge).encode("utf-8"),
                                     headers={"Content-Type": "application/json", **({"Authorization": f"Bearer {cle}"} if cle else {})},
                                     method="POST")
    try:
        with urllib.request.urlopen(requete, timeout=120, context=base_test.contexte_tls()) as reponse:
            message = json.loads(reponse.read().decode("utf-8"))["choices"][0]["message"]
    except urllib.error.HTTPError as erreur:
        print(f"REFUSE : le serveur a repondu {erreur.code} (l'agent s'en passe automatiquement).")
        return
    except Exception as erreur:  # reseau, delai, certificat
        sys.exit(f"Appel impossible : {type(erreur).__name__} : {erreur}")
    texte = message.get("content") or ""
    if message.get("tool_calls") or "[TOOL_CALLS]" in texte or texte.lstrip().startswith("[{"):
        print("ACCEPTE : le serveur renvoie bien un appel d'outil.")
    else:
        print("IGNORE : le serveur repond sans appel d'outil (la contrainte n'a pas d'effet ; sans gravite).")


if __name__ == "__main__":
    main()
