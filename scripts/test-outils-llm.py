#!/usr/bin/env python3
"""Teste si le LLM configure sait appeler des outils, et sous quelle forme.

A lancer sur le poste du bureau, depuis la racine du projet :

    python3 scripts/test-outils-llm.py

Lit l'adresse, le modele et la cle dans llm.env. N'ecrit rien, n'installe rien,
n'affiche jamais la cle. Bibliotheque standard uniquement.

Trois resultats possibles :
  A. format standard  -> le serveur renvoie un champ tool_calls structure
  B. format texte     -> la decision arrive en texte brut, a decoder nous-memes
  C. aucun appel      -> le modele ignore l'outil : il faudra concevoir autrement
"""
from __future__ import annotations

import json
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path

RACINE = Path(__file__).resolve().parent.parent
FICHIER_ENV = RACINE / "llm.env"


def lire_env(chemin: Path) -> dict[str, str]:
    if not chemin.exists():
        sys.exit(f"Fichier introuvable : {chemin}\nLancez le script depuis la racine du projet.")
    valeurs = {}
    for ligne in chemin.read_text(encoding="utf-8", errors="replace").splitlines():
        ligne = ligne.strip()
        if not ligne or ligne.startswith("#") or "=" not in ligne:
            continue
        cle, _, valeur = ligne.partition("=")
        valeurs[cle.strip()] = valeur.strip().strip('"').strip("'")
    return valeurs


OUTIL = {
    "type": "function",
    "function": {
        "name": "lire_classeur",
        "description": "Lit un classeur Excel et renvoie le contenu d'une feuille.",
        "parameters": {
            "type": "object",
            "properties": {
                "fichier": {"type": "string", "description": "Nom du fichier Excel"},
                "feuille": {"type": "string", "description": "Nom de la feuille"},
            },
            "required": ["fichier"],
        },
    },
}

QUESTION = (
    "Combien de lignes contient la feuille Stock du fichier stock.xlsx ? "
    "Tu dois utiliser l'outil a ta disposition pour le savoir."
)


def appeler(url: str, cle: str, charge: dict) -> dict:
    requete = urllib.request.Request(
        url,
        data=json.dumps(charge).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            **({"Authorization": f"Bearer {cle}"} if cle else {}),
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(requete, timeout=120) as reponse:
            return json.loads(reponse.read().decode("utf-8"))
    except urllib.error.HTTPError as erreur:
        corps = erreur.read().decode("utf-8", "replace")[:500]
        sys.exit(f"Le serveur a repondu {erreur.code} :\n{corps}")
    except Exception as erreur:  # reseau, delai, certificat
        sys.exit(f"Appel impossible : {type(erreur).__name__} : {erreur}")


def main() -> None:
    env = lire_env(FICHIER_ENV)
    base = env.get("LLM_BASE_URL", "").rstrip("/")
    modele = env.get("LLM_MODELE", "")
    cle = env.get("LLM_CLE_API", "")
    if not base or not modele:
        sys.exit("LLM_BASE_URL ou LLM_MODELE manquant dans llm.env")

    print(f"Adresse : {base}")
    print(f"Modele  : {modele}")
    print(f"Cle     : {'renseignee' if cle else 'absente'}")
    print(f"Question: {QUESTION}\n")

    donnees = appeler(
        f"{base}/chat/completions",
        cle,
        {
            "model": modele,
            "messages": [{"role": "user", "content": QUESTION}],
            "tools": [OUTIL],
            "tool_choice": "auto",
            "temperature": 0,
        },
    )

    try:
        message = donnees["choices"][0]["message"]
    except (KeyError, IndexError):
        sys.exit("Reponse inattendue :\n" + json.dumps(donnees, ensure_ascii=False)[:800])

    appels = message.get("tool_calls")
    texte = message.get("content") or ""

    print("--- reponse brute du serveur (300 premiers caracteres) ---")
    print(json.dumps(message, ensure_ascii=False)[:300])
    print()

    if appels:
        print("RESULTAT A : format standard.")
        print("Le serveur renvoie un champ tool_calls structure :")
        print(json.dumps(appels, ensure_ascii=False, indent=2)[:600])
        print("\n=> Conception possible telle quelle : le modele choisit ses outils.")
        return

    motif = re.search(r"\[TOOL_CALLS\]\s*(\[.*\])", texte, re.S) or re.search(
        r'\{\s*"name"\s*:\s*"lire_classeur".*\}', texte, re.S
    )
    if motif:
        print("RESULTAT B : format texte.")
        print("Le modele decide bien d'appeler l'outil, mais la decision arrive en texte brut :")
        print(texte[:400])
        print("\n=> Utilisable, avec un decodage de ce format a ecrire de notre cote.")
        return

    print("RESULTAT C : aucun appel d'outil.")
    print("Le modele a repondu en texte, sans utiliser l'outil :")
    print(texte[:400])
    print("\n=> Les agents ne pourront pas choisir leurs outils : conception differente a prevoir.")


if __name__ == "__main__":
    main()
