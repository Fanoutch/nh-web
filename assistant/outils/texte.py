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


_MOIS = {"janvier": 1, "janv": 1, "fevrier": 2, "fevr": 2, "fev": 2, "mars": 3, "avril": 4, "avr": 4, "mai": 5, "juin": 6,
         "juillet": 7, "juil": 7, "aout": 8, "septembre": 9, "sept": 9, "octobre": 10, "oct": 10, "novembre": 11, "nov": 11,
         "decembre": 12, "dec": 12}
_DATE_ISO = re.compile(r"(\d{4})-(\d{1,2})-(\d{1,2})(?:[t ].*)?")
_DATE_CHIFFRES = re.compile(r"(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4}|\d{2})")
_DATE_LETTRES = re.compile(r"(\d{1,2})(?:er)? ([a-z]+)\.? (\d{4})")


def en_date(valeur) -> date | None:
    """La date que représente une valeur, ou None : cellule date, « 2027-01-15 », « 15/01/2027 » (le jour d'abord, comme
    en France), « 15 janvier 2027 », « 1er mars 2027 »."""
    if isinstance(valeur, datetime):
        return valeur.date()
    if isinstance(valeur, date):
        return valeur
    if not isinstance(valeur, str):
        return None
    texte = normaliser(valeur)
    if m := _DATE_ISO.fullmatch(texte):
        annee, mois, jour = int(m[1]), int(m[2]), int(m[3])
    elif m := _DATE_CHIFFRES.fullmatch(texte):
        jour, mois, annee = int(m[1]), int(m[2]), int(m[3])
        annee += 2000 if annee < 100 else 0
    elif (m := _DATE_LETTRES.fullmatch(texte)) and m[2] in _MOIS:
        jour, mois, annee = int(m[1]), _MOIS[m[2]], int(m[3])
    else:
        return None
    try:
        return date(annee, mois, jour)
    except ValueError:
        return None


def pour_json(valeur):
    """Valeur de cellule présentable au LLM et sérialisable."""
    if isinstance(valeur, (datetime, date, time)):
        return valeur.isoformat()
    if isinstance(valeur, Decimal):
        return float(valeur)
    if isinstance(valeur, bytes):
        return valeur.decode("utf-8", errors="replace")
    return valeur
