"""Outils de lecture des documents Word (.docx) de l'agent : lecture seule, jamais d'écriture.

Même cadre que les classeurs : seuls les fichiers de l'exécution, 20 Mo, aucun contenu exécuté (un .docx
n'a pas de macros ; .docm est refusé). Le texte du document est une DONNÉE : il ne déclenche jamais rien."""
from __future__ import annotations

import copy
import functools
import re
import shutil
import zipfile
from dataclasses import dataclass, field
from pathlib import Path

from docx import Document as DocumentWord
from docx.table import Table
from docx.text.paragraph import Paragraph

from .classeur import ErreurClasseur, charger, resoudre
from .condition import ErreurCondition, compiler as compiler_condition, trouver_colonne
from .excel import _nom_sortie
from .registre import Contexte, Reponse, erreur, ok, outil
from .texte import nombre, normaliser, pour_json

EXTENSIONS_WORD = (".docx",)
MAX_BLOCS_MODELE = 200        # blocs (paragraphes, titres, marqueurs de tableau) renvoyés par appel
MAX_CARACTERES_MODELE = 10_000  # texte renvoyé par appel, comme le plafond de l'assistant
MAX_PARAGRAPHE = 2_000        # un paragraphe plus long est coupé, avec une marque
MAX_LIGNES_TABLEAU = 200
MAX_RESULTATS = 50
MAX_BLOCS_CREES = 1_000
MAX_LIGNES_CREEES = 2_000
LIMITE_DEZIPPE = 200 * 1024 * 1024   # garde-fou contre un fichier « bombe » (zip qui gonfle)


@dataclass
class Bloc:
    genre: str                      # "titre" | "paragraphe" | "tableau"
    texte: str = ""
    niveau: int = 0                 # niveau d'un titre
    numero: int = 0                 # numéro d'un tableau (à partir de 1)
    lignes: list[list[str]] = field(default_factory=list)


def _niveau_titre(paragraphe: Paragraph) -> int:
    nom = (paragraphe.style.name if paragraphe.style is not None else "") or ""
    m = re.fullmatch(r"(?:heading|titre)\s*(\d)", nom.strip(), re.IGNORECASE)
    return int(m.group(1)) if m else 0


def _resoudre_word(ctx: Contexte, nom: str) -> Path:
    chemin = resoudre(ctx, nom, EXTENSIONS_WORD)
    try:
        with zipfile.ZipFile(chemin) as z:
            if sum(i.file_size for i in z.infolist()) > LIMITE_DEZIPPE:
                raise ErreurClasseur(f"Le document « {nom} » est trop volumineux une fois ouvert : il est refusé.")
    except zipfile.BadZipFile as exc:
        raise ErreurClasseur(f"« {nom} » n'est pas un document Word valide.") from exc
    return chemin


def charger_blocs(ctx: Contexte, nom: str) -> list[Bloc]:
    chemin = _resoudre_word(ctx, nom)
    try:
        document = DocumentWord(str(chemin))
    except Exception as exc:  # fichier abîmé : message clair plutôt qu'une trace
        raise ErreurClasseur(f"Le document « {nom} » est illisible ({type(exc).__name__}).") from exc
    blocs, numero = [], 0
    for element in document.element.body.iterchildren():
        balise = element.tag.rsplit("}", 1)[-1]
        if balise == "p":
            p = Paragraph(element, document)
            texte = p.text.strip()
            if not texte:
                continue
            niveau = _niveau_titre(p)
            blocs.append(Bloc("titre" if niveau else "paragraphe", texte, niveau=niveau))
        elif balise == "tbl":
            numero += 1
            lignes = [[c.text.strip() for c in ligne.cells] for ligne in Table(element, document).rows]
            blocs.append(Bloc("tableau", numero=numero, lignes=lignes))
    return blocs


def _garde(fonction):
    @functools.wraps(fonction)
    def enveloppe(ctx, **kw):
        try:
            return fonction(ctx, **kw)
        except ErreurClasseur as exc:
            return erreur(str(exc), "Utilisez word_decrire pour voir les documents Word disponibles.")
    return enveloppe


def _entier(valeur, defaut: int, mini: int, maxi: int | None = None) -> int:
    try:
        v = int(valeur)
    except (TypeError, ValueError):
        return defaut
    v = max(mini, v)
    return min(v, maxi) if maxi is not None else v


def _court(texte: str) -> str:
    return texte if len(texte) <= MAX_PARAGRAPHE else texte[:MAX_PARAGRAPHE] + " […coupé]"


def _forme(b: Bloc, indice: int) -> dict:
    if b.genre == "tableau":
        colonnes = max((len(l) for l in b.lignes), default=0)
        return {"indice": indice, "genre": "tableau", "numero": b.numero,
                "lignes": len(b.lignes), "colonnes": colonnes,
                "en_tete": [_court(c) for c in (b.lignes[0] if b.lignes else [])]}
    resultat = {"indice": indice, "genre": b.genre, "texte": _court(b.texte)}
    if b.genre == "titre":
        resultat["niveau"] = b.niveau
    return resultat


@outil("word_decrire",
       "Sans argument : liste les documents Word (.docx) disponibles. Avec « fichier » : plan (titres), nombre de "
       "paragraphes, tableaux (taille et en-tête).",
       {"type": "object", "properties": {"fichier": {"type": "string", "description": "Nom du document Word"}}})
@_garde
def word_decrire(ctx: Contexte, fichier: str | None = None) -> Reponse:
    if not fichier:
        liste = []
        for nom in ctx.fichiers_autorises + ctx.fichiers_produits:
            try:
                liste.append({"nom": nom, "taille_octets": _resoudre_word(ctx, nom).stat().st_size})
            except ErreurClasseur:
                continue
        return ok(f"{len(liste)} document(s) Word disponible(s).", {"documents": liste},
                  "Décrivez un document avec word_decrire(fichier=...).")
    blocs = charger_blocs(ctx, fichier)
    titres = [{"indice": i, "niveau": b.niveau, "texte": _court(b.texte)} for i, b in enumerate(blocs) if b.genre == "titre"]
    tableaux = [_forme(b, i) for i, b in enumerate(blocs) if b.genre == "tableau"]
    paragraphes = sum(1 for b in blocs if b.genre == "paragraphe")
    marqueurs = sorted({m for b in blocs for m in _MARQUEUR.findall(b.texte if b.genre != "tableau" else " ".join(c for l in b.lignes for c in l))})
    caracteres = sum(len(b.texte) for b in blocs)
    return ok(f"« {fichier} » : {len(blocs)} bloc(s), {len(tableaux)} tableau(x).",
              {"fichier": fichier, "blocs": len(blocs), "paragraphes": paragraphes, "caracteres": caracteres,
               "titres": titres[:MAX_RESULTATS], "tableaux": tableaux[:MAX_RESULTATS],
               "marqueurs": marqueurs[:MAX_RESULTATS]},
              "Lisez le texte avec word_lire, cherchez un mot avec word_chercher, lisez un tableau avec word_tableau. "
              "Les marqueurs {{...}} se remplissent avec word_regenerer.")


@outil("word_lire",
       f"Lit le contenu d'un document Word dans l'ordre ({MAX_BLOCS_MODELE} blocs ou {MAX_CARACTERES_MODELE} "
       "caractères au plus par appel). Les tableaux apparaissent comme des repères : lisez-les avec word_tableau. "
       "Paginez avec « depuis ».",
       {"type": "object", "properties": {
           "fichier": {"type": "string"},
           "depuis": {"type": "integer", "description": "Indice du premier bloc (0 par défaut)"},
           "limite": {"type": "integer"}}, "required": ["fichier"]})
@_garde
def word_lire(ctx: Contexte, fichier: str, depuis: int = 0, limite: int = MAX_BLOCS_MODELE) -> Reponse:
    blocs = charger_blocs(ctx, fichier)
    depuis = _entier(depuis, 0, 0)
    limite = _entier(limite, MAX_BLOCS_MODELE, 1, MAX_BLOCS_MODELE)
    page, total = [], 0
    for i in range(depuis, min(len(blocs), depuis + limite)):
        forme = _forme(blocs[i], i)
        taille = len(forme.get("texte", ""))
        if page and total + taille > MAX_CARACTERES_MODELE:
            break
        total += taille
        page.append(forme)
    suivant = depuis + len(page)
    reste = suivant < len(blocs)
    return ok(f"Blocs {depuis} à {suivant - 1} sur {len(blocs)}." if page else "Aucun bloc à cet endroit.",
              {"fichier": fichier, "blocs": page, "total_blocs": len(blocs), "suivant": suivant if reste else None},
              f"Suite : word_lire(fichier=..., depuis={suivant})." if reste else "Fin du document.")


@outil("word_chercher",
       "Cherche un mot ou une expression (sans tenir compte des majuscules ni des accents) dans les paragraphes, "
       "les titres et les cellules des tableaux d'un document Word.",
       {"type": "object", "properties": {"fichier": {"type": "string"}, "texte": {"type": "string"}},
        "required": ["fichier", "texte"]})
@_garde
def word_chercher(ctx: Contexte, fichier: str, texte: str) -> Reponse:
    cherche = normaliser(texte)
    if not cherche:
        return erreur("Le texte à chercher est vide.", "Donnez un mot ou une expression à chercher.")
    blocs = charger_blocs(ctx, fichier)
    trouves, titre_courant = [], ""
    for i, b in enumerate(blocs):
        if b.genre == "titre":
            titre_courant = b.texte
        if b.genre == "tableau":
            for n_ligne, ligne in enumerate(b.lignes):
                if any(cherche in normaliser(c) for c in ligne):
                    trouves.append({"indice": i, "genre": "tableau", "numero": b.numero, "ligne": n_ligne,
                                    "cellules": [_court(c) for c in ligne], "section": titre_courant})
        elif cherche in normaliser(b.texte):
            trouves.append({"indice": i, "genre": b.genre, "texte": _court(b.texte), "section": titre_courant})
    return ok(f"{len(trouves)} résultat(s) pour « {texte} ».",
              {"fichier": fichier, "resultats": trouves[:MAX_RESULTATS], "total": len(trouves),
               "tronque": len(trouves) > MAX_RESULTATS},
              "Affinez l'expression si la liste est tronquée." if len(trouves) > MAX_RESULTATS else "")


@outil("word_tableau",
       f"Lit un tableau d'un document Word ({MAX_LIGNES_TABLEAU} lignes au plus par appel). La première ligne est "
       "renvoyée comme en-tête. Numéros de tableaux : voir word_decrire.",
       {"type": "object", "properties": {
           "fichier": {"type": "string"}, "numero": {"type": "integer", "description": "Numéro du tableau (à partir de 1)"},
           "depuis": {"type": "integer"}, "limite": {"type": "integer"}}, "required": ["fichier", "numero"]})
@_garde
def word_tableau(ctx: Contexte, fichier: str, numero: int, depuis: int = 0, limite: int = MAX_LIGNES_TABLEAU) -> Reponse:
    tableaux = [b for b in charger_blocs(ctx, fichier) if b.genre == "tableau"]
    numero = _entier(numero, 0, 0)
    if not 1 <= numero <= len(tableaux):
        return erreur(f"Le tableau n° {numero} n'existe pas dans « {fichier} » ({len(tableaux)} tableau(x)).",
                      "Utilisez word_decrire pour voir les numéros de tableaux.")
    t = tableaux[numero - 1]
    entete, corps = (t.lignes[0] if t.lignes else []), t.lignes[1:]
    depuis = _entier(depuis, 0, 0)
    limite = _entier(limite, MAX_LIGNES_TABLEAU, 1, MAX_LIGNES_TABLEAU)
    page = corps[depuis:depuis + limite]
    suivant = depuis + len(page)
    return ok(f"Tableau {numero} : lignes {depuis} à {suivant - 1} sur {len(corps)}." if page else f"Tableau {numero} : aucune ligne à cet endroit.",
              {"fichier": fichier, "numero": numero, "en_tete": [_court(c) for c in entete],
               "lignes": [[_court(c) for c in l] for l in page], "total_lignes": len(corps),
               "suivant": suivant if suivant < len(corps) else None},
              f"Suite : word_tableau(fichier=..., numero={numero}, depuis={suivant})." if suivant < len(corps) else "Fin du tableau.")


_CARACTERES_INTERDITS = re.compile(r"[\x00-\x08\x0b\x0c\x0e-\x1f]")   # refusés par le format Word (XML)


def _propre(valeur) -> str:
    return _CARACTERES_INTERDITS.sub("", "" if valeur is None else str(valeur))


class _Refus(Exception):
    """Une demande de contenu est invalide : porte la réponse d'erreur à renvoyer au modèle."""

    def __init__(self, reponse: Reponse):
        super().__init__(reponse.resume)
        self.reponse = reponse


def _ajouter_blocs(document, contenu: list) -> list:
    """Ajoute les blocs à la fin du document et renvoie leurs éléments XML (pour les déplacer ailleurs)."""
    if not isinstance(contenu, list) or not contenu:
        raise _Refus(erreur("« contenu » doit être une liste non vide de blocs.",
                            "Exemple : [{\"type\": \"titre\", \"niveau\": 1, \"texte\": \"Compte rendu\"}, "
                            "{\"type\": \"paragraphe\", \"texte\": \"...\"}]."))
    if len(contenu) > MAX_BLOCS_CREES:
        raise _Refus(erreur(f"Le document dépasse {MAX_BLOCS_CREES} blocs.", "Découpez le contenu en plusieurs documents."))
    elements = []
    for i, bloc in enumerate(contenu, start=1):
        if not isinstance(bloc, dict):
            raise _Refus(erreur(f"Le bloc {i} n'est pas un objet.", "Chaque bloc a un « type » : titre, paragraphe, liste ou tableau."))
        genre = bloc.get("type")
        if genre == "titre":
            elements.append(document.add_heading(_propre(bloc.get("texte")), level=_entier(bloc.get("niveau"), 1, 1, 4))._p)
        elif genre == "paragraphe":
            elements.append(document.add_paragraph(_propre(bloc.get("texte")))._p)
        elif genre == "liste":
            lignes = bloc.get("elements")
            if not isinstance(lignes, list) or not lignes:
                raise _Refus(erreur(f"Le bloc {i} (liste) n'a pas d'« elements ».", "Donnez une liste de textes."))
            style = "List Number" if bloc.get("numerotee") else "List Bullet"
            elements += [document.add_paragraph(_propre(e), style=style)._p for e in lignes]
        elif genre == "tableau":
            colonnes, lignes = bloc.get("colonnes"), bloc.get("lignes", [])
            if not isinstance(colonnes, list) or not colonnes:
                raise _Refus(erreur(f"Le bloc {i} (tableau) n'a pas de « colonnes ».", "Donnez la liste des titres de colonnes."))
            if not isinstance(lignes, list) or not all(isinstance(l, list) for l in lignes):
                raise _Refus(erreur(f"Les « lignes » du bloc {i} doivent être une liste de lignes.", ""))
            if len(lignes) > MAX_LIGNES_CREEES:
                raise _Refus(erreur(f"Le tableau du bloc {i} dépasse {MAX_LIGNES_CREEES} lignes.", "Découpez-le en plusieurs tableaux."))
            if any(len(l) > len(colonnes) for l in lignes):
                raise _Refus(erreur(f"Une ligne du bloc {i} a plus de valeurs que de colonnes ({len(colonnes)}).",
                                    "Corrigez les lignes et relancez l'outil."))
            table = document.add_table(rows=1 + len(lignes), cols=len(colonnes))
            table.style = "Table Grid"
            for j, c in enumerate(colonnes):
                table.cell(0, j).text = _propre(c)
                for run in table.cell(0, j).paragraphs[0].runs:
                    run.bold = True
            for r, ligne in enumerate(lignes, start=1):
                for j, valeur in enumerate(ligne):
                    table.cell(r, j).text = _propre(valeur)
            elements.append(table._tbl)
        else:
            raise _Refus(erreur(f"Le bloc {i} a un type inconnu : « {genre} ».", "Types possibles : titre, paragraphe, liste, tableau."))
    return elements


_DESCRIPTION_BLOCS = ("{type: \"titre\", niveau: 1 à 4, texte}, {type: \"paragraphe\", texte}, "
                      "{type: \"liste\", elements: [...], numerotee?: true}, {type: \"tableau\", colonnes: [...], lignes: [[...]]}")


@outil("word_creer",
       "Crée un document Word (.docx) NEUF. « contenu » est une liste de blocs dans l'ordre : " + _DESCRIPTION_BLOCS + ". "
       "Le fichier est écrit dans les fichiers produits ; aucun document existant n'est modifié.",
       {"type": "object", "properties": {
           "nom": {"type": "string"},
           "contenu": {"type": "array", "items": {"type": "object"}}}, "required": ["nom", "contenu"]})
def word_creer(ctx: Contexte, nom: str, contenu: list) -> Reponse:
    document = DocumentWord()
    try:
        _ajouter_blocs(document, contenu)
    except _Refus as refus:
        return refus.reponse
    fichier = _nom_sortie(ctx, nom, "nouveau.docx", ".docx")
    ctx.dossier_sortie.mkdir(parents=True, exist_ok=True)
    document.save(ctx.dossier_sortie / fichier)
    ctx.fichiers_produits.append(fichier)
    return ok(f"Document « {fichier} » créé ({len(contenu)} bloc(s)).", {"fichier_produit": fichier, "blocs": len(contenu)},
              "Le fichier est prêt à être téléchargé.",
              [{"nom": fichier, "type": "docx", "intermediaire": False, "blocs": len(contenu)}])


# --- Remplir un document existant (modèle) : toujours dans une COPIE ----------------------------------------------
MAX_MODIFICATIONS = 200
MAX_TEXTE_REMPLACEMENT = 20_000
_MARQUEUR = re.compile(r"\{\{[^{}]{1,60}\}\}")


def _paragraphes(document):
    """Tous les paragraphes du corps, des tableaux (y compris imbriqués), des en-têtes et des pieds de page."""
    def dans_tables(tables):
        for t in tables:
            for ligne in t.rows:
                for cellule in ligne.cells:
                    yield from cellule.paragraphs
                    yield from dans_tables(cellule.tables)
    yield from document.paragraphs
    yield from dans_tables(document.tables)
    for section in document.sections:
        for zone in (section.header, section.footer):
            yield from zone.paragraphs
            yield from dans_tables(zone.tables)


def _remplacer_dans_paragraphe(p: Paragraph, chercher: str, par: str) -> int:
    """Remplace toutes les occurrences, même coupées entre plusieurs « runs » ; garde la mise en forme du début."""
    n, depart = 0, 0
    while True:
        runs = p.runs
        texte = "".join(r.text for r in runs)
        i = texte.find(chercher, depart)
        if i < 0:
            return n
        fin_i = i + len(chercher)
        pos, debut, fin = 0, None, None
        for k, r in enumerate(runs):
            longueur = len(r.text)
            if debut is None and i < pos + longueur:
                debut = (k, i - pos)
            if debut is not None and fin_i <= pos + longueur:
                fin = (k, fin_i - pos)
                break
            pos += longueur
        d_k, d_o = debut
        f_k, f_o = fin
        if d_k == f_k:
            runs[d_k].text = runs[d_k].text[:d_o] + par + runs[d_k].text[f_o:]
        else:
            runs[d_k].text = runs[d_k].text[:d_o] + par
            for k in range(d_k + 1, f_k):
                runs[k].text = ""
            runs[f_k].text = runs[f_k].text[f_o:]
        n += 1
        depart = i + len(par)


def _poser_texte_cellule(cellule, texte: str) -> None:
    premier, *autres = cellule.paragraphs
    if premier.runs:
        premier.runs[0].text = texte
        for r in premier.runs[1:]:
            r.text = ""
    else:
        premier.add_run(texte)
    for p in autres:
        for r in p.runs:
            r.text = ""


def _titre_courant(document, texte: str):
    """Paragraphe de titre dont le texte (sans accents ni majuscules) correspond, ou None."""
    voulu = normaliser(texte)
    for p in document.paragraphs:
        if _niveau_titre(p) and normaliser(p.text) == voulu:
            return p
    return None


def _titres_disponibles(document) -> str:
    return ", ".join(f"« {p.text.strip()} »" for p in document.paragraphs if _niveau_titre(p)) or "aucun titre"


def _deplacer_apres(ancre, elements: list) -> None:
    for el in elements:
        ancre.addnext(el)
        ancre = el


_CALCULS = ("compter", "somme", "moyenne", "min", "max")
_DATE_ISO = re.compile(r"^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$")


def _texte_cellule(valeur) -> str:
    """Valeur de cellule écrite dans un document : entiers sans « .0 », décimales à la française, dates en JJ/MM/AAAA."""
    v = pour_json(valeur)
    if v is None:
        return ""
    if isinstance(v, bool):
        return "oui" if v else "non"
    if isinstance(v, float):
        return str(int(v)) if v == int(v) else f"{v:.6f}".rstrip("0").rstrip(".").replace(".", ",")
    if isinstance(v, str) and (m := _DATE_ISO.match(v)):
        return f"{m.group(3)}/{m.group(2)}/{m.group(1)}"
    return _propre(v)


def _source_excel(ctx: Contexte, i: int, src) -> tuple:
    """Classeur chargé et lignes retenues par la condition ; les erreurs deviennent une réponse lisible."""
    if not isinstance(src, dict) or not src.get("fichier"):
        raise _Refus(erreur(f"La modification {i} : la source Excel doit indiquer un « fichier ».",
                            "Exemple : {\"fichier\": \"stock.xlsx\", \"condition\": {\"colonne\": \"Stock\", \"operateur\": \"<\", \"valeur\": 5}}."))
    try:
        c = charger(ctx, src["fichier"], src.get("feuille"))
        test = compiler_condition(src.get("condition"), c.entetes)
        return c, [l for l in c.lignes if test(l)]
    except ErreurClasseur as exc:
        raise _Refus(erreur(f"Modification {i} : {exc}", "Utilisez excel_decrire pour voir les fichiers, feuilles et colonnes.")) from exc
    except ErreurCondition as exc:
        raise _Refus(erreur(f"Modification {i} : {exc}", "Corrigez la condition (colonne, opérateur, valeur).")) from exc


def _valeur_excel(ctx: Contexte, i: int, src) -> str:
    """Une valeur calculée PAR LE CODE depuis un classeur : un calcul sur la colonne, ou la cellule de l'UNIQUE ligne retenue."""
    c, retenues = _source_excel(ctx, i, src)
    calcul, colonne = src.get("calcul"), src.get("colonne")
    try:
        if calcul is None:
            if not colonne:
                raise _Refus(erreur(f"Modification {i} : indiquez soit « calcul » (compter, somme, moyenne, min, max), soit « colonne ».", ""))
            if len(retenues) != 1:
                raise _Refus(erreur(f"Modification {i} : {len(retenues)} ligne(s) correspondent à la condition, il en faut exactement une.",
                                    "Précisez la condition, ou donnez un « calcul »."))
            return _texte_cellule(retenues[0][trouver_colonne(colonne, c.entetes)])
        if calcul not in _CALCULS:
            raise _Refus(erreur(f"Modification {i} : le calcul « {calcul} » n'existe pas.", "Choisissez : " + ", ".join(_CALCULS) + "."))
        if calcul == "compter":
            return str(len(retenues))
        if not colonne:
            raise _Refus(erreur(f"Modification {i} : le calcul « {calcul} » demande une « colonne » numérique.", ""))
        indice = trouver_colonne(colonne, c.entetes)
    except ErreurCondition as exc:
        raise _Refus(erreur(f"Modification {i} : {exc}", "Vérifiez le nom de la colonne avec excel_decrire.")) from exc
    nombres = [n for n in (nombre(l[indice]) for l in retenues) if n is not None]
    if not nombres:
        raise _Refus(erreur(f"Modification {i} : aucune valeur numérique dans « {colonne} » pour les lignes retenues.", ""))
    resultat = {"somme": sum(nombres), "moyenne": sum(nombres) / len(nombres), "min": min(nombres), "max": max(nombres)}[calcul]
    return _texte_cellule(round(resultat, 6))


def _lignes_excel(ctx: Contexte, i: int, src) -> list[list[str]]:
    """Les lignes d'un classeur (filtrées, colonnes au choix), prêtes à entrer dans un tableau Word."""
    c, retenues = _source_excel(ctx, i, src)
    try:
        indices = [trouver_colonne(n, c.entetes) for n in src["colonnes"]] if src.get("colonnes") else list(range(len(c.entetes)))
    except (ErreurCondition, TypeError) as exc:
        raise _Refus(erreur(f"Modification {i} : {exc}", "« colonnes » est une liste de noms de colonnes (voir excel_decrire).")) from exc
    if not retenues:
        raise _Refus(erreur(f"Modification {i} : aucune ligne de « {src['fichier']} » ne correspond : le tableau n'est pas modifié.",
                            "Vérifiez la condition avec excel_selectionner."))
    if len(retenues) > MAX_LIGNES_CREEES:
        raise _Refus(erreur(f"Modification {i} : {len(retenues)} lignes, au plus {MAX_LIGNES_CREEES} par tableau.", "Ajoutez une condition."))
    return [[_texte_cellule(l[k]) for k in indices] for l in retenues]


def _appliquer(ctx: Contexte, document, i: int, m: dict) -> str:
    """Applique UNE modification ; renvoie une phrase de compte rendu ou lève _Refus."""
    if not isinstance(m, dict):
        raise _Refus(erreur(f"La modification {i} n'est pas un objet.", "Exemple : {\"remplacer\": \"{{DATE}}\", \"par\": \"20/10/2026\"}."))
    if "remplacer" in m:
        chercher = m.get("remplacer")
        if not isinstance(chercher, str) or not chercher:
            raise _Refus(erreur(f"La modification {i} : « remplacer » doit être un texte non vide.", ""))
        depuis_excel = "valeur_excel" in m
        if depuis_excel:
            par = _valeur_excel(ctx, i, m["valeur_excel"])          # calculée par le code : le modèle ne recopie jamais un chiffre
        elif "par" in m:
            par = _propre(m.get("par"))
        else:
            raise _Refus(erreur(f"La modification {i} : indiquez « par » (un texte) ou « valeur_excel » (une valeur prise dans un classeur).", ""))
        if len(par) > MAX_TEXTE_REMPLACEMENT:
            raise _Refus(erreur(f"La modification {i} : le texte de remplacement dépasse {MAX_TEXTE_REMPLACEMENT} caractères.", ""))
        n = sum(_remplacer_dans_paragraphe(p, chercher, par) for p in _paragraphes(document))
        if n == 0:
            marqueurs = sorted({mm for p in _paragraphes(document) for mm in _MARQUEUR.findall(p.text)})
            raise _Refus(erreur(f"Modification {i} : « {chercher} » est introuvable dans le document.",
                                "Marqueurs du document : " + (", ".join(marqueurs) or "aucun") + ". Utilisez word_chercher pour retrouver le texte exact."))
        return f"« {chercher} » remplacé ({n} fois)" + (f" par {par} (pris dans {m['valeur_excel'].get('fichier')})" if depuis_excel else "")
    if "remplir_tableau" in m:
        tableaux = document.tables
        numero = _entier(m.get("remplir_tableau"), 0, 0)
        if not 1 <= numero <= len(tableaux):
            raise _Refus(erreur(f"Modification {i} : le tableau n° {numero} n'existe pas ({len(tableaux)} tableau(x)).",
                                "Utilisez word_decrire pour voir les numéros de tableaux."))
        if "depuis_excel" in m:
            lignes = _lignes_excel(ctx, i, m["depuis_excel"])      # copiées par le code : aucune donnée n'est retapée par le modèle
        else:
            lignes = m.get("lignes")
        if not isinstance(lignes, list) or not all(isinstance(l, list) for l in lignes) or len(lignes) > MAX_LIGNES_CREEES:
            raise _Refus(erreur(f"Modification {i} : « lignes » doit être une liste de lignes (au plus {MAX_LIGNES_CREEES}).", ""))
        table = tableaux[numero - 1]
        colonnes = len(table.columns)
        if any(len(l) > colonnes for l in lignes):
            raise _Refus(erreur(f"Modification {i} : une ligne a plus de valeurs que le tableau n'a de colonnes ({colonnes}).", ""))
        mode = m.get("mode", "ajouter")
        if mode not in ("ajouter", "remplacer"):
            raise _Refus(erreur(f"Modification {i} : mode « {mode} » inconnu.", "Modes : ajouter (à la suite) ou remplacer (les lignes sous l'en-tête)."))
        if len(table.rows) == 0:
            raise _Refus(erreur(f"Modification {i} : le tableau {numero} est vide.", ""))
        modele = copy.deepcopy(table.rows[-1]._tr)          # nouvelles lignes : même mise en forme que la dernière
        if mode == "remplacer":
            for ligne in list(table.rows)[1:]:
                table._tbl.remove(ligne._tr)
        for valeurs in lignes:
            tr = copy.deepcopy(modele)
            table._tbl.append(tr)
            ligne = table.rows[-1]
            for j, cellule in enumerate(ligne.cells):
                _poser_texte_cellule(cellule, _propre(valeurs[j]) if j < len(valeurs) else "")
        return (f"tableau {numero} : {len(lignes)} ligne(s) {'ajoutée(s)' if mode == 'ajouter' else 'écrite(s)'}"
                + (f" (prises dans {m['depuis_excel'].get('fichier')})" if "depuis_excel" in m else ""))
    if "remplacer_section" in m or "ajouter_apres" in m:
        remplace = "remplacer_section" in m
        titre = m.get("remplacer_section" if remplace else "ajouter_apres")
        ancre = _titre_courant(document, titre) if isinstance(titre, str) else None
        if ancre is None:
            raise _Refus(erreur(f"Modification {i} : le titre « {titre} » est introuvable.", "Titres du document : " + _titres_disponibles(document) + "."))
        nouveaux = _ajouter_blocs(document, m.get("contenu"))
        if remplace:
            niveau = _niveau_titre(ancre)
            suivant = ancre._p.getnext()
            while suivant is not None and suivant.tag.rsplit("}", 1)[-1] != "sectPr":
                balise = suivant.tag.rsplit("}", 1)[-1]
                if balise == "p" and 0 < _niveau_titre(Paragraph(suivant, document)) <= niveau:
                    break
                a_supprimer, suivant = suivant, suivant.getnext()
                if a_supprimer not in nouveaux:
                    a_supprimer.getparent().remove(a_supprimer)
        _deplacer_apres(ancre._p, nouveaux)
        return f"section « {titre} » {'remplacée' if remplace else 'complétée'}"
    raise _Refus(erreur(f"La modification {i} n'est pas reconnue.",
                        "Types : remplacer (+ par), remplir_tableau (+ lignes, mode), remplacer_section (+ contenu), ajouter_apres (+ contenu)."))


@outil("word_regenerer",
       "Remplit un document Word EXISTANT (un modèle) en en produisant une COPIE modifiée ; l'original n'est jamais touché "
       "et sa mise en forme est conservée. « modifications » est une liste appliquée dans l'ordre : "
       "{remplacer: \"{{DATE}}\", par: \"texte\"} (partout : texte, tableaux, en-têtes) ; "
       "{remplir_tableau: n, lignes: [[...]], mode: \"ajouter\"|\"remplacer\"} ; "
       "POUR REPRENDRE DES DONNÉES D'EXCEL SANS LES RECOPIER (le code les copie, ne retapez jamais un chiffre) : "
       "{remplacer: \"{{TOTAL}}\", valeur_excel: {fichier, feuille?, condition?, calcul?: compter|somme|moyenne|min|max, colonne?}} "
       "(un calcul, ou la cellule de l'unique ligne retenue) et "
       "{remplir_tableau: n, depuis_excel: {fichier, feuille?, condition?, colonnes?: [...]}, mode?} (les lignes du classeur) ; "
       "{remplacer_section: \"Titre\", contenu: [blocs]} ; {ajouter_apres: \"Titre\", contenu: [blocs]}. "
       "Blocs : " + _DESCRIPTION_BLOCS + ". Tout ou rien : si une modification échoue, aucun fichier n'est produit.",
       {"type": "object", "properties": {
           "fichier_source": {"type": "string"},
           "modifications": {"type": "array", "items": {"type": "object"}},
           "nom_sortie": {"type": "string"}}, "required": ["fichier_source", "modifications", "nom_sortie"]})
@_garde
def word_regenerer(ctx: Contexte, fichier_source: str, modifications: list, nom_sortie: str) -> Reponse:
    source = _resoudre_word(ctx, fichier_source)
    if not isinstance(modifications, list) or not modifications:
        return erreur("« modifications » doit être une liste non vide.", "Exemple : [{\"remplacer\": \"{{DATE}}\", \"par\": \"20/10/2026\"}].")
    if len(modifications) > MAX_MODIFICATIONS:
        return erreur(f"Au plus {MAX_MODIFICATIONS} modifications par appel.", "Découpez en plusieurs appels.")
    nom = _nom_sortie(ctx, nom_sortie, f"{source.stem}-modifie.docx", ".docx")
    ctx.dossier_sortie.mkdir(parents=True, exist_ok=True)
    sortie = ctx.dossier_sortie / nom
    shutil.copyfile(source, sortie)                  # la source n'est lue que par cette copie
    try:
        document = DocumentWord(str(sortie))
        comptes = [_appliquer(ctx, document, i, m) for i, m in enumerate(modifications, start=1)]
        document.save(sortie)
    except _Refus as refus:
        sortie.unlink(missing_ok=True)
        return refus.reponse
    except Exception as exc:  # noqa: BLE001 — document étrange : message clair, aucun fichier laissé
        sortie.unlink(missing_ok=True)
        return erreur(f"Le document « {fichier_source} » n'a pas pu être rempli ({type(exc).__name__}).",
                      "Vérifiez qu'il s'ouvre normalement dans Word, ou utilisez word_creer pour un document neuf.")
    ctx.fichiers_produits.append(nom)
    return ok(f"Document « {nom} » produit à partir de « {fichier_source} » : " + " ; ".join(comptes) + ".",
              {"fichier_produit": nom, "modifications": comptes},
              "Le fichier est prêt à être téléchargé. L'original est intact.",
              [{"nom": nom, "type": "docx", "intermediaire": False, "modifications": len(comptes)}])


# --- Un document par ligne d'un classeur (publipostage) -------------------------------------------------------------
MAX_PUBLIPOSTAGE = 50
_ACCOLADE_SIMPLE = re.compile(r"\{([^{}]+)\}")


@outil("word_publipostage",
       "Produit UN document Word PAR LIGNE d'un classeur Excel, à partir d'un modèle Word contenant des marqueurs "
       "{{Colonne}} (le nom du marqueur est le nom d'une colonne ; accents et majuscules sans importance). Le programme "
       f"copie lui-même les valeurs ({MAX_PUBLIPOSTAGE} documents au plus). Le modèle et le classeur ne sont jamais modifiés. "
       "« condition » limite les lignes (comme excel_selectionner). « nom_fichier » donne le nom de chaque document, avec "
       "des colonnes entre accolades simples : \"courrier-{Nom}\" (par défaut : nom du modèle + première colonne). « correspondances » associe un marqueur à une colonne "
       "quand les noms diffèrent : {\"{{NOM}}\": \"Nom complet\"}. Tout ou rien.",
       {"type": "object", "properties": {
           "fichier_modele": {"type": "string"}, "fichier_excel": {"type": "string"}, "feuille": {"type": "string"},
           "condition": {"type": "object"}, "nom_fichier": {"type": "string"},
           "correspondances": {"type": "object"}}, "required": ["fichier_modele", "fichier_excel"]})
@_garde
def word_publipostage(ctx: Contexte, fichier_modele: str, fichier_excel: str, feuille: str | None = None,
                      condition=None, nom_fichier: str | None = None, correspondances=None) -> Reponse:
    source = _resoudre_word(ctx, fichier_modele)
    try:
        c = charger(ctx, fichier_excel, feuille)
        test = compiler_condition(condition, c.entetes)
    except ErreurCondition as exc:
        return erreur(str(exc), "Corrigez la condition ou le nom de la colonne (voir excel_decrire).")
    lignes = [l for l in c.lignes if test(l)]
    if not lignes:
        return erreur(f"Aucune ligne de « {fichier_excel} » ne correspond : aucun document n'est produit.", "Vérifiez la condition avec excel_selectionner.")
    if len(lignes) > MAX_PUBLIPOSTAGE:
        return erreur(f"{len(lignes)} lignes : au plus {MAX_PUBLIPOSTAGE} documents par appel.", "Ajoutez une condition pour traiter la liste par groupes.")

    modele = DocumentWord(str(source))
    marqueurs = sorted({m for p in _paragraphes(modele) for m in _MARQUEUR.findall(p.text)})
    if not marqueurs:
        return erreur(f"Le modèle « {fichier_modele} » ne contient aucun marqueur {{{{Colonne}}}}.",
                      "Écrivez dans le modèle, à la place de ce qui change, des marqueurs comme {{Nom}} ou {{Date}}.")
    explicites = correspondances if isinstance(correspondances, dict) else {}
    colonne_de, absents = {}, []
    for m in marqueurs:
        voulu = explicites.get(m, m[2:-2].strip())
        try:
            colonne_de[m] = trouver_colonne(voulu, c.entetes)
        except ErreurCondition:
            absents.append(m)
    if absents:
        return erreur("Marqueur(s) du modèle sans colonne correspondante : " + ", ".join(absents) + ".",
                      "Colonnes de « " + fichier_excel + " » : " + ", ".join(c.entetes) + ". Renommez le marqueur ou donnez « correspondances ».")

    motif = nom_fichier or f"{source.stem}-{{{c.entetes[0]}}}"          # par défaut : la première colonne du classeur
    try:
        champs = {k: trouver_colonne(k, c.entetes) for k in _ACCOLADE_SIMPLE.findall(motif)}
    except ErreurCondition as exc:
        return erreur(f"Le nom de fichier demandé utilise une colonne inconnue. {exc}", "Corrigez « nom_fichier ».")

    crees, vides = [], {}
    try:
        ctx.dossier_sortie.mkdir(parents=True, exist_ok=True)
        for n, ligne in enumerate(lignes, start=1):
            base = _ACCOLADE_SIMPLE.sub(lambda mm: _texte_cellule(ligne[champs[mm.group(1)]]) or str(n), motif)
            nom = _nom_sortie(ctx, base, f"{source.stem}-{n}.docx", ".docx")
            sortie = ctx.dossier_sortie / nom
            shutil.copyfile(source, sortie)
            ctx.fichiers_produits.append(nom)
            crees.append(nom)
            document = DocumentWord(str(sortie))
            for m, indice in colonne_de.items():
                valeur = _texte_cellule(ligne[indice])
                if valeur == "":
                    vides[m] = vides.get(m, 0) + 1
                for p in _paragraphes(document):
                    _remplacer_dans_paragraphe(p, m, valeur)
            document.save(sortie)
    except Exception as exc:  # noqa: BLE001 — tout ou rien : on retire ce qui a été écrit
        for nom in crees:
            (ctx.dossier_sortie / nom).unlink(missing_ok=True)
            if nom in ctx.fichiers_produits:
                ctx.fichiers_produits.remove(nom)
        return erreur(f"Les documents n'ont pas pu être produits ({type(exc).__name__}) : aucun fichier n'a été gardé.",
                      "Vérifiez que le modèle s'ouvre normalement dans Word.")
    avertissement = ("" if not vides else " Attention, des cellules vides ont laissé un champ vide : "
                     + ", ".join(f"{m} ({n} document(s))" for m, n in sorted(vides.items())) + ".")
    return ok(f"{len(crees)} document(s) produit(s) à partir de « {fichier_modele} » et « {fichier_excel} ».{avertissement}",
              {"fichiers_produits": crees, "nombre": len(crees), "marqueurs": {m: c.entetes[i] for m, i in colonne_de.items()},
               "valeurs_vides": vides},
              "Les documents sont prêts à être téléchargés. Les originaux sont intacts.",
              [{"nom": n, "type": "docx", "intermediaire": False} for n in crees])
