"""Conditions de filtrage SANS eval : une grammaire fermée de clauses.

    {"colonne": "Quantité", "operateur": "<", "valeur": 5}
    {"logique": "ou", "clauses": [ {...}, {...} ]}      # « et » par défaut
"""
from __future__ import annotations

from typing import Callable

from .texte import nombre, normaliser

OPERATEURS = ("=", "!=", "<", "<=", ">", ">=", "contient", "vide", "non_vide")


class ErreurCondition(Exception):
    """Message en français, présentable au modèle."""


def trouver_colonne(nom, entetes: list[str]) -> int:
    if nom in entetes:
        return entetes.index(nom)
    cible = normaliser(nom)
    correspondances = [i for i, e in enumerate(entetes) if normaliser(e) == cible]
    if len(correspondances) == 1:
        return correspondances[0]
    raise ErreurCondition(f"La colonne « {nom} » n'existe pas. Colonnes disponibles : {', '.join(entetes)}.")


def _comparer(operateur: str, a, b) -> bool:
    na, nb = nombre(a), nombre(b)
    if na is not None and nb is not None:
        x, y = na, nb
    else:
        x, y = normaliser(a), normaliser(b)
    return {"=": x == y, "!=": x != y, "<": x < y, "<=": x <= y, ">": x > y, ">=": x >= y}[operateur]


def _clause(clause: dict, entetes: list[str]) -> Callable[[list], bool]:
    if not isinstance(clause, dict) or "colonne" not in clause or "operateur" not in clause:
        raise ErreurCondition("Une condition doit contenir « colonne » et « operateur ». "
                              'Exemple : {"colonne": "Quantité", "operateur": "<", "valeur": 5}.')
    operateur = clause["operateur"]
    if operateur not in OPERATEURS:
        raise ErreurCondition(f"L'opérateur « {operateur} » n'existe pas. Opérateurs possibles : "
                              f"{', '.join(OPERATEURS)}.")
    i = trouver_colonne(clause["colonne"], entetes)
    if operateur in ("vide", "non_vide"):
        def vide(ligne):
            v = ligne[i]
            return v is None or (isinstance(v, str) and not v.strip())
        return vide if operateur == "vide" else (lambda ligne: not vide(ligne))
    if "valeur" not in clause:
        raise ErreurCondition(f"L'opérateur « {operateur} » demande une « valeur ».")
    valeur = clause["valeur"]
    if operateur == "contient":
        morceau = normaliser(valeur)
        return lambda ligne: morceau in normaliser(ligne[i])
    return lambda ligne: ligne[i] is not None and _comparer(operateur, ligne[i], valeur)


def compiler(condition, entetes: list[str]) -> Callable[[list], bool]:
    """Fonction `ligne -> bool`. Condition absente ou vide : tout passe."""
    if condition in (None, {}, [], ""):
        return lambda ligne: True
    if isinstance(condition, dict) and "clauses" in condition:
        logique = condition.get("logique", "et")
        if logique not in ("et", "ou"):
            raise ErreurCondition(f"La logique « {logique} » n'existe pas : utilisez « et » ou « ou ».")
        fonctions = [compiler(c, entetes) for c in condition["clauses"]]
        return (lambda ligne: all(f(ligne) for f in fonctions)) if logique == "et" \
            else (lambda ligne: any(f(ligne) for f in fonctions))
    if isinstance(condition, list):
        fonctions = [compiler(c, entetes) for c in condition]
        return lambda ligne: all(f(ligne) for f in fonctions)
    return _clause(condition, entetes)
