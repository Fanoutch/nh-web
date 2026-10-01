"""Juge des réponses : un appel au LLM, température 0, verdict JSON strict."""
from __future__ import annotations

import json
import re

from banc.questions import Question
from rag import modeles
from rag.reglages import Reglages

DEBUT_REFUS = "Je ne trouve pas cette information"

PROMPT_JUGE = """Tu évalues la réponse d'un assistant documentaire. Sois strict et factuel.
QUESTION : {question}
ÉLÉMENTS ATTENDUS (tous indispensables) : {attendus}
EXTRAITS FOURNIS À L'ASSISTANT :
{extraits}
RÉPONSE DE L'ASSISTANT :
{reponse}

Réponds UNIQUEMENT par un objet JSON :
{{"juste": true|false, "fidele": true|false, "justification": "une phrase"}}
- juste : la réponse contient chacun des éléments attendus (mêmes valeurs, formulation libre).
- fidele : la réponse n'affirme rien qui soit absent des extraits."""

PROMPT_JUGE_ABSENTE = """Tu évalues la réponse d'un assistant documentaire. L'information demandée N'EXISTE PAS dans les documents.
QUESTION : {question}
EXTRAITS FOURNIS À L'ASSISTANT :
{extraits}
RÉPONSE DE L'ASSISTANT :
{reponse}

Réponds UNIQUEMENT par un objet JSON :
{{"refus_correct": true|false, "fidele": true|false, "justification": "une phrase"}}
- refus_correct : l'assistant dit clairement qu'il ne trouve pas l'information, sans en inventer.
- fidele : la réponse n'affirme rien qui soit absent des extraits."""

_JSON = re.compile(r"\{.*\}", re.DOTALL)


def lire_verdict(texte: str) -> dict | None:
    m = _JSON.search(texte or "")
    if not m:
        return None
    try:
        verdict = json.loads(m.group(0))
    except ValueError:
        return None
    return verdict if isinstance(verdict, dict) else None


def juger(q: Question, reponse: str, extraits: str, r: Reglages) -> dict:
    resultat = {"juste": None, "fidele": None, "refus_correct": None, "note": "ok", "justification": ""}
    if q.type == "absente" and reponse.strip().startswith(DEBUT_REFUS):
        return {**resultat, "refus_correct": True, "fidele": True, "justification": "refus exact"}
    gabarit = PROMPT_JUGE_ABSENTE if q.type == "absente" else PROMPT_JUGE
    prompt = gabarit.format(question=q.question, attendus=" ; ".join(q.attendus),
                            extraits=extraits or "(aucun)", reponse=reponse)
    try:
        brut = modeles.discuter([{"role": "user", "content": prompt}], r, 0)
    except modeles.ModeleIndisponible as exc:
        return {**resultat, "note": "erreur", "justification": str(exc)}
    verdict = lire_verdict(brut)
    if verdict is None:
        return {**resultat, "note": "illisible", "justification": (brut or "")[:200]}
    for cle in ("juste", "fidele", "refus_correct"):
        if isinstance(verdict.get(cle), bool):
            resultat[cle] = verdict[cle]
    resultat["justification"] = str(verdict.get("justification", ""))[:300]
    return resultat
