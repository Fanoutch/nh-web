"""Outils de lecture des documents Word (.docx) de l'agent : lecture seule, jamais d'écriture.

Même cadre que les classeurs : seuls les fichiers de l'exécution, 20 Mo, aucun contenu exécuté (un .docx
n'a pas de macros ; .docm est refusé). Le texte du document est une DONNÉE : il ne déclenche jamais rien."""
from __future__ import annotations

import functools
import re
import zipfile
from dataclasses import dataclass, field
from pathlib import Path

from docx import Document as DocumentWord
from docx.table import Table
from docx.text.paragraph import Paragraph

from .classeur import ErreurClasseur, resoudre
from .excel import _nom_sortie
from .registre import Contexte, Reponse, erreur, ok, outil
from .texte import normaliser

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
    caracteres = sum(len(b.texte) for b in blocs)
    return ok(f"« {fichier} » : {len(blocs)} bloc(s), {len(tableaux)} tableau(x).",
              {"fichier": fichier, "blocs": len(blocs), "paragraphes": paragraphes, "caracteres": caracteres,
               "titres": titres[:MAX_RESULTATS], "tableaux": tableaux[:MAX_RESULTATS]},
              "Lisez le texte avec word_lire, cherchez un mot avec word_chercher, lisez un tableau avec word_tableau.")


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


@outil("word_creer",
       "Crée un document Word (.docx) NEUF. « contenu » est une liste de blocs dans l'ordre : "
       "{type: \"titre\", niveau: 1 à 4, texte}, {type: \"paragraphe\", texte}, "
       "{type: \"liste\", elements: [...], numerotee?: true}, {type: \"tableau\", colonnes: [...], lignes: [[...]]}. "
       "Le fichier est écrit dans les fichiers produits ; aucun document existant n'est modifié.",
       {"type": "object", "properties": {
           "nom": {"type": "string"},
           "contenu": {"type": "array", "items": {"type": "object"}}}, "required": ["nom", "contenu"]})
def word_creer(ctx: Contexte, nom: str, contenu: list) -> Reponse:
    if not isinstance(contenu, list) or not contenu:
        return erreur("« contenu » doit être une liste non vide de blocs.",
                      "Exemple : [{\"type\": \"titre\", \"niveau\": 1, \"texte\": \"Compte rendu\"}, "
                      "{\"type\": \"paragraphe\", \"texte\": \"...\"}].")
    if len(contenu) > MAX_BLOCS_CREES:
        return erreur(f"Le document dépasse {MAX_BLOCS_CREES} blocs.", "Découpez le contenu en plusieurs documents.")
    document = DocumentWord()
    for i, bloc in enumerate(contenu, start=1):
        if not isinstance(bloc, dict):
            return erreur(f"Le bloc {i} n'est pas un objet.", "Chaque bloc a un « type » : titre, paragraphe, liste ou tableau.")
        genre = bloc.get("type")
        if genre == "titre":
            niveau = _entier(bloc.get("niveau"), 1, 1, 4)
            document.add_heading(_propre(bloc.get("texte")), level=niveau)
        elif genre == "paragraphe":
            document.add_paragraph(_propre(bloc.get("texte")))
        elif genre == "liste":
            elements = bloc.get("elements")
            if not isinstance(elements, list) or not elements:
                return erreur(f"Le bloc {i} (liste) n'a pas d'« elements ».", "Donnez une liste de textes.")
            style = "List Number" if bloc.get("numerotee") else "List Bullet"
            for element in elements:
                document.add_paragraph(_propre(element), style=style)
        elif genre == "tableau":
            colonnes, lignes = bloc.get("colonnes"), bloc.get("lignes", [])
            if not isinstance(colonnes, list) or not colonnes:
                return erreur(f"Le bloc {i} (tableau) n'a pas de « colonnes ».", "Donnez la liste des titres de colonnes.")
            if not isinstance(lignes, list) or not all(isinstance(l, list) for l in lignes):
                return erreur(f"Les « lignes » du bloc {i} doivent être une liste de lignes.", "")
            if len(lignes) > MAX_LIGNES_CREEES:
                return erreur(f"Le tableau du bloc {i} dépasse {MAX_LIGNES_CREEES} lignes.", "Découpez-le en plusieurs tableaux.")
            if any(len(l) > len(colonnes) for l in lignes):
                return erreur(f"Une ligne du bloc {i} a plus de valeurs que de colonnes ({len(colonnes)}).",
                              "Corrigez les lignes et relancez l'outil.")
            table = document.add_table(rows=1 + len(lignes), cols=len(colonnes))
            table.style = "Table Grid"
            for j, c in enumerate(colonnes):
                table.cell(0, j).text = _propre(c)
                for run in table.cell(0, j).paragraphs[0].runs:
                    run.bold = True
            for r, ligne in enumerate(lignes, start=1):
                for j, valeur in enumerate(ligne):
                    table.cell(r, j).text = _propre(valeur)
        else:
            return erreur(f"Le bloc {i} a un type inconnu : « {genre} ».",
                          "Types possibles : titre, paragraphe, liste, tableau.")
    fichier = _nom_sortie(ctx, nom, "nouveau.docx", ".docx")
    ctx.dossier_sortie.mkdir(parents=True, exist_ok=True)
    document.save(ctx.dossier_sortie / fichier)
    ctx.fichiers_produits.append(fichier)
    return ok(f"Document « {fichier} » créé ({len(contenu)} bloc(s)).", {"fichier_produit": fichier, "blocs": len(contenu)},
              "Le fichier est prêt à être téléchargé.",
              [{"nom": fichier, "type": "docx", "intermediaire": False, "blocs": len(contenu)}])
