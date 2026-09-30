"""Mesures de la recherche : le passage attendu est-il parmi les morceaux transmis au LLM ?"""
from __future__ import annotations

import re
import unicodedata

from banc.questions import Question

_MOT = re.compile(r"[a-z]+|[0-9]+")


def normaliser(texte: str) -> list[str]:
    """Mots en minuscules, sans accents ni ponctuation ; lettres et chiffres collés sont séparés
    (le PDF colle parfois une cellule de tableau au mot précédent : « pales100 »)."""
    sans_accents = unicodedata.normalize("NFKD", texte.lower()).encode("ascii", "ignore").decode()
    return _MOT.findall(sans_accents)


def contient(texte: str, passage: str, part: float = 0.8) -> bool:
    """Vrai si au moins `part` des mots du passage apparaissent, dans l'ordre, dans une
    fenêtre du texte de même longueur (+ 25 %) : tolère les mots cassés du PDF."""
    mots_p, mots_t = normaliser(passage), normaliser(texte)
    if not mots_p:
        return False
    fenetre = max(len(mots_p), int(len(mots_p) * 1.25))
    requis = part * len(mots_p)
    ensemble_p = set(mots_p)
    for debut in range(0, max(1, len(mots_t) - len(mots_p) // 2)):
        tranche = mots_t[debut:debut + fenetre]
        if not tranche or tranche[0] not in ensemble_p:
            continue
        restants = list(mots_p)
        communs = 0
        for mot in tranche:
            if mot in restants:
                restants.remove(mot)
                communs += 1
        if communs >= requis:
            return True
    return False


def evaluer_recherche(q: Question, passages: list[dict], noms: dict[int, str]) -> dict:
    if q.type == "absente":
        return {"trouve": not passages, "rang": None}
    rangs = []
    for attendu in q.passages:
        rang = next((i for i, p in enumerate(passages, start=1) if contient(p["texte"], attendu)), None)
        if rang is None:
            return {"trouve": False, "rang": None}
        rangs.append(rang)
    if len(q.documents) > 1:
        presents = {noms.get(p["document_id"]) for p in passages}
        if not all(d in presents for d in q.documents):
            return {"trouve": False, "rang": None}
    return {"trouve": True, "rang": min(rangs)}


def seuil_propose(distances_utiles: list[float], distances_absentes: list[float]) -> float | None:
    """Seuil qui garde le plus de bonnes réponses tout en rejetant le plus de questions absentes."""
    if not distances_utiles or not distances_absentes:
        return None
    candidats = sorted(set(distances_utiles + distances_absentes))
    meilleur, score_max = None, -1
    for a, b in zip(candidats, candidats[1:]):
        seuil = (a + b) / 2
        score = sum(d <= seuil for d in distances_utiles) + sum(d > seuil for d in distances_absentes)
        if score > score_max:
            meilleur, score_max = seuil, score
    return round(meilleur, 3) if meilleur is not None else None
