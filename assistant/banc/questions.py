"""Lecture de questions.csv (modifiable dans Excel : séparateur ; ou , et BOM acceptés)."""
from __future__ import annotations

import csv
from dataclasses import dataclass
from pathlib import Path

TYPES = ("simple", "coupee", "deux_pages", "tableau", "multi_documents", "reformulee", "absente")
SEPARATEUR_VALEURS = " | "


@dataclass(frozen=True)
class Question:
    id: str
    type: str
    question: str
    attendus: list[str]
    passages: list[str]
    documents: list[str]


def _valeurs(champ: str | None) -> list[str]:
    return [v.strip() for v in (champ or "").split(SEPARATEUR_VALEURS) if v.strip()]


def charger_questions(chemin: Path) -> list[Question]:
    texte = Path(chemin).read_text(encoding="utf-8-sig")
    separateur = ";" if texte.splitlines()[0].count(";") >= texte.splitlines()[0].count(",") else ","
    questions = []
    for ligne in csv.DictReader(texte.splitlines(), delimiter=separateur):
        if not (ligne.get("id") or "").strip():
            continue
        q = Question(
            id=ligne["id"].strip(), type=(ligne["type"] or "").strip(), question=(ligne["question"] or "").strip(),
            attendus=_valeurs(ligne["reponse_attendue"]), passages=_valeurs(ligne["passage"]),
            documents=_valeurs(ligne["documents"]),
        )
        if q.type not in TYPES:
            raise ValueError(f"Question {q.id} : type « {q.type} » inconnu (attendu : {', '.join(TYPES)}).")
        if q.type != "absente" and not q.passages:
            raise ValueError(f"Question {q.id} : il faut au moins un passage (sauf pour le type absente).")
        questions.append(q)
    return questions
