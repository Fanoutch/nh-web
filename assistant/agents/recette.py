"""Rejouer une recette validée PAR LE CODE, sans LLM : rapide, identique à chaque lancement, indépendant du modèle.

Seules les recettes dont les étapes prennent leurs données DANS LES FICHIERS se rejouent ainsi. Une étape qui contient
des valeurs écrites par le modèle (excel_creer, un « par » dans word_regenerer…) rejouerait les données du mois dernier :
ces recettes restent confiées au modèle, qui doit les recalculer (voir `est_rejouable`).
"""
from __future__ import annotations

from difflib import SequenceMatcher
from pathlib import Path
from typing import Callable, Iterator

from outils.registre import Contexte, executer

_EXCEL = {".xlsx", ".xlsm", ".csv"}
_LECTURE = {"excel_lire", "excel_selectionner", "excel_filtrer", "word_lire", "word_chercher", "word_tableau"}
_PRODUCTION_FIDELE = {"excel_extraire", "excel_depuis_word", "excel_colonne", "excel_rapprocher", "word_publipostage"}
SEUIL_RESSEMBLANCE = 0.5


def _donnees_figees(etape: dict) -> bool:
    """Vrai si l'étape contient des valeurs écrites par le modèle, que le prochain lancement rendrait fausses."""
    if etape.get("outil") != "word_regenerer":
        return False
    for m in (etape.get("arguments") or {}).get("modifications") or []:
        if not isinstance(m, dict):
            return True
        if "remplacer" in m and "valeur_excel" not in m:
            return True
        if "remplir_tableau" in m and "depuis_excel" not in m:
            return True
        if "remplacer_section" in m or "ajouter_apres" in m:
            return True
    return False


def est_rejouable(recette: list[dict] | None) -> bool:
    if not recette:
        return False
    for etape in recette:
        outil = etape.get("outil")
        if outil in _LECTURE or outil in _PRODUCTION_FIDELE:
            continue
        if outil == "word_regenerer" and not _donnees_figees(etape):
            continue
        return False
    return any(e.get("outil") not in _LECTURE for e in recette)     # une recette qui ne produit rien a besoin du modèle


def _noms_de_fichiers(valeur) -> Iterator[str]:
    """Noms de fichiers d'entrée dans les arguments : « fichier », « fichier_a », « fichier_modele »… à toute profondeur."""
    if isinstance(valeur, dict):
        for cle, v in valeur.items():
            if cle.startswith("fichier") and isinstance(v, str):
                yield v
            else:
                yield from _noms_de_fichiers(v)
    elif isinstance(valeur, list):
        for v in valeur:
            yield from _noms_de_fichiers(v)


def _renommer(valeur, table: dict[str, str]):
    if isinstance(valeur, dict):
        return {c: (table.get(v, v) if c.startswith("fichier") and isinstance(v, str) else _renommer(v, table))
                for c, v in valeur.items()}
    if isinstance(valeur, list):
        return [_renommer(v, table) for v in valeur]
    return valeur


def _meme_famille(a: str, b: str) -> bool:
    ea, eb = Path(a).suffix.lower(), Path(b).suffix.lower()
    return ea == eb or (ea in _EXCEL and eb in _EXCEL)


def associer_fichiers(recette: list[dict], disponibles: list[str]) -> tuple[dict[str, str], str | None]:
    """Quel document déposé prend la place de chaque fichier de la recette. Le même nom d'abord ; sinon le seul fichier du
    même genre restant, ou celui dont le nom ressemble nettement. Dans le doute : (table, message), jamais de devinette."""
    produits = {Path(a).stem for e in recette for a in [(e.get("arguments") or {}).get("nom_sortie")] if a}     # sans extension
    attendus: list[str] = []
    for e in recette:
        for nom in _noms_de_fichiers(e.get("arguments") or {}):
            if nom not in attendus and Path(nom).stem not in produits:
                attendus.append(nom)
    table = {n: n for n in attendus if n in disponibles}
    libres = [d for d in disponibles if d not in table.values()]
    for nom in [n for n in attendus if n not in table]:
        candidats = [d for d in libres if _meme_famille(nom, d)]
        if not candidats:
            return table, f"Il manque un document pour remplacer « {nom} » : déposez-le puis relancez."
        notes = sorted(((SequenceMatcher(None, Path(nom).stem.lower(), Path(d).stem.lower()).ratio(), d) for d in candidats), reverse=True)
        if len(candidats) > 1 and (notes[0][0] < SEUIL_RESSEMBLANCE or notes[0][0] - notes[1][0] < 0.1):
            return table, (f"Je ne sais pas quel document utiliser à la place de « {nom} » parmi : " + ", ".join(candidats)
                           + f". Gardez le nom « {nom} » pour ce document, ou retirez les autres, puis relancez.")
        table[nom] = notes[0][1]
        libres.remove(notes[0][1])
    return table, None


def _fin(statut: str, message: str, contexte: Contexte, tours: int) -> dict:
    return {"type": "fin", "statut": statut, "message": message, "tours": tours,
            "fichiers": list(contexte.fichiers_produits), "sans_llm": True}


def rejouer(recette: list[dict], contexte: Contexte, arreter: Callable[[], bool] = lambda: False) -> Iterator[dict]:
    """Mêmes événements que la boucle du modèle. La première étape en erreur arrête tout : pas de résultat à moitié juste."""
    table, probleme = associer_fichiers(recette, contexte.fichiers_autorises)
    if probleme:
        yield _fin("arret", probleme, contexte, 0)
        return
    resumes: list[str] = []
    remplaces = [f"« {a} » → « {n} »" for a, n in table.items() if a != n]
    for tour, etape in enumerate(recette, start=1):
        if arreter():
            yield _fin("arret", "Arrêt demandé.", contexte, tour - 1)
            return
        outil, arguments = etape["outil"], _renommer(etape.get("arguments") or {}, table)
        reponse = executer(outil, arguments, contexte)
        evenement = {"type": "etape", "tour": tour, "outil": outil, "arguments": arguments,
                     "statut": reponse.statut, "resume": reponse.resume}
        if reponse.artefacts:
            evenement["artefacts"] = reponse.artefacts
        yield evenement
        if reponse.statut != "ok":
            yield _fin("arret", f"L'étape {tour} de la recette (« {outil} ») n'a pas pu se faire : {reponse.resume} {reponse.suite}".strip()
                                + " Les documents ont peut-être changé : vérifiez-les, ou redemandez le travail dans le chat.", contexte, tour)
            return
        if outil not in _LECTURE:
            resumes.append(reponse.resume)
            sortie = (etape.get("arguments") or {}).get("nom_sortie")
            produit = (reponse.donnees or {}).get("fichier_produit")
            if sortie and produit:                              # nom déjà pris : les étapes suivantes suivent le vrai nom
                table[Path(sortie).stem + Path(produit).suffix] = produit
    yield _fin("termine", "Recette rejouée à l'identique, sans passer par le modèle. "
               + (("Documents utilisés : " + ", ".join(remplaces) + ". ") if remplaces else "") + " ".join(resumes),
               contexte, len(recette))
