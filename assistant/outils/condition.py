"""Conditions de filtrage SANS eval : une grammaire fermée de clauses.

    {"colonne": "Quantité", "operateur": "<", "valeur": 5}
    {"logique": "ou", "clauses": [ {...}, {...} ]}      # « et » par défaut
"""
from __future__ import annotations

from typing import Callable

from .texte import en_date, nombre, normaliser

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


def _genre(valeur) -> tuple[str, object]:
    """Ce qu'est une valeur pour une comparaison : une date, un nombre ou un texte."""
    d = en_date(valeur)
    if d is not None:
        return "date", d
    n = nombre(valeur)
    if n is not None:
        return "nombre", n
    return "texte", normaliser(valeur)


def _comparer(operateur: str, a, b) -> bool:
    (ga, x), (gb, y) = _genre(a), _genre(b)
    if ga != gb:                        # une date, un nombre et un texte ne se comparent pas : jamais d'ordre alphabétique
        return operateur == "!="        # entre « 15 janvier 2027 » et « 01/01/2028 » (voir `filtrer`, qui le signale)
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


def _clauses(condition) -> list[dict]:
    if isinstance(condition, list):
        return [c for sous in condition for c in _clauses(sous)]
    if isinstance(condition, dict):
        return _clauses(condition["clauses"]) if "clauses" in condition else [condition]
    return []


def filtrer(condition, entetes: list[str], lignes: list[list]) -> tuple[list[list], list[str]]:
    """Lignes qui vérifient la condition, et alertes pour celles qui n'ont pas pu être comparées : jamais de réponse fausse
    en silence. Si AUCUNE cellule de la colonne n'est comparable à la valeur demandée, la condition est refusée."""
    test = compiler(condition, entetes)
    alertes = []
    for clause in _clauses(condition):
        if clause.get("operateur") in ("vide", "non_vide", "contient") or "valeur" not in clause:
            continue
        i = trouver_colonne(clause["colonne"], entetes)
        attendu = _genre(clause["valeur"])[0]
        remplies = [l[i] for l in lignes if l[i] is not None and str(l[i]).strip()]
        autres = [v for v in remplies if _genre(v)[0] != attendu]
        if not autres:
            continue
        noms = {"date": "une date", "nombre": "un nombre", "texte": "un texte"}
        if len(autres) == len(remplies):
            raise ErreurCondition(f"La colonne « {entetes[i]} » contient {noms[_genre(autres[0])[0]]} (ex. « {autres[0]} ») et "
                                  f"la condition donne {noms[attendu]} (« {clause['valeur']} ») : ils ne se comparent pas. "
                                  "Donnez une valeur du même genre (une date s'écrit par exemple 31/12/2027).")
        if attendu != "texte":          # dans une colonne de textes, quelques nombres ne faussent rien
            alertes.append(f"Attention : {len(autres)} ligne(s) de la colonne « {entetes[i]} » ne sont pas {noms[attendu]} "
                           f"(ex. « {autres[0]} ») : elles n'ont pas pu être comparées et sont écartées.")
    return [l for l in lignes if test(l)], alertes
