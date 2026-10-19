"""Formules arithmétiques calculées en Python, SANS eval ni exec.

Les colonnes s'écrivent entre crochets : `[Besoin] - [Stock]`. Autorisé : nombres, + - * /, parenthèses,
max() min() abs() round(). Tout le reste (appel, attribut, indice, texte…) est refusé avant tout calcul."""
from __future__ import annotations

import ast
import re
from typing import Callable

from .condition import ErreurCondition, trouver_colonne
from .texte import nombre

FONCTIONS = {"max": max, "min": min, "abs": abs, "round": round}


class ErreurFormule(Exception):
    """Formule refusée : message en français."""


class ErreurCalcul(Exception):
    """Erreur sur UNE ligne (valeur non numérique, division par zéro)."""


def compiler(formule: str, entetes: list[str]) -> Callable[[list], float]:
    if not isinstance(formule, str) or not formule.strip():
        raise ErreurFormule("La formule est vide. Exemple : [Besoin] - [Stock].")
    colonnes: list[int] = []

    def remplacer(m):
        try:
            colonnes.append(trouver_colonne(m.group(1), entetes))
        except ErreurCondition as exc:
            raise ErreurFormule(str(exc)) from exc
        return f"_c{len(colonnes) - 1}"

    texte = re.sub(r"\[([^\]]+)\]", remplacer, formule)
    try:
        arbre = ast.parse(texte.strip(), mode="eval")
    except SyntaxError as exc:
        raise ErreurFormule("La formule est mal écrite. Exemple : [Besoin] - [Stock].") from exc

    def verifier(noeud):
        if isinstance(noeud, ast.Expression):
            verifier(noeud.body)
        elif isinstance(noeud, ast.BinOp) and isinstance(noeud.op, (ast.Add, ast.Sub, ast.Mult, ast.Div)):
            verifier(noeud.left), verifier(noeud.right)
        elif isinstance(noeud, ast.UnaryOp) and isinstance(noeud.op, (ast.USub, ast.UAdd)):
            verifier(noeud.operand)
        elif isinstance(noeud, ast.Constant) and isinstance(noeud.value, (int, float)) \
                and not isinstance(noeud.value, bool):
            pass
        elif isinstance(noeud, ast.Name) and re.fullmatch(r"_c\d+", noeud.id):
            pass
        elif isinstance(noeud, ast.Call) and isinstance(noeud.func, ast.Name) and noeud.func.id in FONCTIONS \
                and not noeud.keywords:
            for a in noeud.args:
                verifier(a)
        else:
            raise ErreurFormule("La formule contient un élément non autorisé. Seuls les nombres, les colonnes "
                                "entre crochets, + - * /, les parenthèses et max, min, abs, round sont permis.")

    verifier(arbre)

    def evaluer(noeud, ligne):
        if isinstance(noeud, ast.Expression):
            return evaluer(noeud.body, ligne)
        if isinstance(noeud, ast.Constant):
            return noeud.value
        if isinstance(noeud, ast.Name):
            valeur = nombre(ligne[colonnes[int(noeud.id[2:])]])
            if valeur is None:
                raise ErreurCalcul("une valeur n'est pas un nombre")
            return valeur
        if isinstance(noeud, ast.UnaryOp):
            v = evaluer(noeud.operand, ligne)
            return -v if isinstance(noeud.op, ast.USub) else v
        if isinstance(noeud, ast.BinOp):
            a, b = evaluer(noeud.left, ligne), evaluer(noeud.right, ligne)
            if isinstance(noeud.op, ast.Add):
                return a + b
            if isinstance(noeud.op, ast.Sub):
                return a - b
            if isinstance(noeud.op, ast.Mult):
                return a * b
            if b == 0:
                raise ErreurCalcul("division par zéro")
            return a / b
        return FONCTIONS[noeud.func.id](*[evaluer(a, ligne) for a in noeud.args])

    return lambda ligne: evaluer(arbre, ligne)
