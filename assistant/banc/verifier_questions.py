"""Vérifie que chaque passage de questions.csv existe bien dans son document, format par format.

Usage (depuis assistant/) : .venv/bin/python -m banc.verifier_questions
"""
from __future__ import annotations

import sys
from pathlib import Path

from banc.mesures import contient
from banc.questions import charger_questions
from rag.lecteurs import lire

DOSSIER = Path(__file__).resolve().parent


def main() -> int:
    erreurs = 0
    for q in charger_questions(DOSSIER / "questions.csv"):
        for format_ in ("pdf", "docx", "txt"):
            fichiers = [DOSSIER / "corpus" / format_ / f"{d}.{format_}" for d in q.documents]
            if not fichiers or not all(f.exists() for f in fichiers):
                continue
            texte = "\n".join(p["texte"] for f in fichiers for p in lire(f))
            for passage in q.passages:
                if not contient(texte, passage):
                    erreurs += 1
                    print(f"{q.id} ({format_}) : passage introuvable → {passage[:70]}")
    print("Tout est cohérent." if not erreurs else f"{erreurs} passage(s) à corriger.")
    return 1 if erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
