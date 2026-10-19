"""Normalisation de textes et de nombres, partagée par les outils."""
from __future__ import annotations

import re
import unicodedata
from datetime import date, datetime, time
from decimal import Decimal


def normaliser(valeur) -> str:
    """Minuscules, sans accents, espaces réduits : pour comparer des libellés."""
    if valeur is None:
        return ""
    texte = unicodedata.normalize("NFKD", str(valeur))
    texte = "".join(c for c in texte if not unicodedata.combining(c))
    return re.sub(r"\s+", " ", texte).strip().lower()


def nombre(valeur) -> float | None:
    """Convertit en nombre si c'est raisonnable (« 12,5 » compris), sinon None. Les booléens n'en sont pas."""
    if valeur is None or isinstance(valeur, bool):
        return None
    if isinstance(valeur, (int, float, Decimal)):
        return float(valeur)
    texte = str(valeur).strip().replace(" ", "").replace(" ", "")
    if not texte or not re.fullmatch(r"[+-]?\d+([.,]\d+)?", texte):
        return None
    return float(texte.replace(",", "."))


def pour_json(valeur):
    """Valeur de cellule présentable au LLM et sérialisable."""
    if isinstance(valeur, (datetime, date, time)):
        return valeur.isoformat()
    if isinstance(valeur, Decimal):
        return float(valeur)
    if isinstance(valeur, bytes):
        return valeur.decode("utf-8", errors="replace")
    return valeur
