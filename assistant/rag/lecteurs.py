"""Lecture des documents déposés : un lecteur par extension.

Ajouter un format = écrire une fonction `_lire_xxx(chemin) -> list[dict]` et
l'inscrire dans LECTEURS. Laravel récupère la liste via GET /formats : rien à
changer côté site.
"""
from __future__ import annotations

import re
from pathlib import Path
from typing import Callable

from docx import Document as DocumentWord
from docx.table import Table
from docx.text.paragraph import Paragraph
from pypdf import PdfReader


class LectureImpossible(Exception):
    """Document illisible. Le message est affiché tel quel à l'utilisateur."""


# --- Nettoyage (repris du loader Genialrag) ---------------------------------
_NUMERO_PAGE_SEUL = re.compile(r"^\s*\d+\s*/\s*\d+\s*$", re.MULTILINE)
_NUMERO_PAGE_PREFIXE = re.compile(r"^\s*\d+\s*/\s*\d+\s+", re.MULTILINE)
_LIGNES_ADMINISTRATIVES = [
    re.compile(r"^.*BCRM de .*$", re.MULTILINE | re.IGNORECASE),
    re.compile(r"^\s*BP \d+\s*[–-].*$", re.MULTILINE),
    re.compile(r"^\s*\S+@intradef\.gouv\.fr\s*$", re.MULTILINE),
    re.compile(r"^\s*Dossier suivi par\s*:.*$", re.MULTILINE | re.IGNORECASE),
]
_LIGNES_VIDES = re.compile(r"\n\s*\n\s*\n+")


def nettoyer(texte: str) -> str:
    texte = _NUMERO_PAGE_PREFIXE.sub("", texte)
    texte = _NUMERO_PAGE_SEUL.sub("", texte)
    for motif in _LIGNES_ADMINISTRATIVES:
        texte = motif.sub("", texte)
    return _LIGNES_VIDES.sub("\n\n", texte).strip()


# --- Lecteurs ----------------------------------------------------------------
def _lire_pdf(chemin: Path) -> list[dict]:
    lecteur = PdfReader(str(chemin))
    return [
        {"page": numero, "texte": nettoyer(page.extract_text() or "")}
        for numero, page in enumerate(lecteur.pages, start=1)
    ]


def lignes_tableau(lignes: list[list[str]]) -> list[str]:
    """Une ligne de tableau → « En-tête : valeur ; … » (1re ligne = en-têtes, cellules vides omises)."""
    lignes = [[c.strip() for c in l] for l in lignes]
    if not lignes:
        return []
    if len(lignes) == 1:
        return [" ; ".join(c for c in lignes[0] if c)] if any(lignes[0]) else []
    entetes = [e or f"Colonne {i}" for i, e in enumerate(lignes[0], start=1)]
    return [" ; ".join(f"{e} : {v}" for e, v in zip(entetes, l) if v) for l in lignes[1:] if any(l)]


def _lire_docx(chemin: Path) -> list[dict]:
    """Corps du document dans l'ordre : paragraphes et tableaux à leur place."""
    document = DocumentWord(str(chemin))
    blocs = []
    for element in document.element.body.iterchildren():
        balise = element.tag.rsplit("}", 1)[-1]
        if balise == "p":
            texte = Paragraph(element, document).text
            if texte.strip():
                blocs.append(texte)
        elif balise == "tbl":
            lignes = [[cellule.text for cellule in ligne.cells] for ligne in Table(element, document).rows]
            blocs.extend(lignes_tableau(lignes))
    return [{"page": None, "texte": nettoyer("\n\n".join(blocs))}]


def _tableaux_texte(texte: str) -> str:
    """Réécrit les blocs d'au moins 2 lignes consécutives contenant chacune au moins deux « | »."""
    lignes, sortie, bloc = texte.split("\n"), [], []

    def vider():
        if len(bloc) >= 2:
            sortie.extend(lignes_tableau([l.strip().strip("|").split("|") for l in bloc]))
        else:
            sortie.extend(bloc)
        bloc.clear()

    for ligne in lignes:
        if ligne.count("|") >= 2:
            bloc.append(ligne)
        else:
            vider()
            sortie.append(ligne)
    vider()
    return "\n".join(sortie)


def _decoder(octets: bytes) -> str:
    """Decode bytes as UTF-8-SIG, falling back to cp1252 on error."""
    try:
        return octets.decode("utf-8-sig")
    except UnicodeDecodeError:
        return octets.decode("cp1252")


def _lire_txt(chemin: Path) -> list[dict]:
    octets = chemin.read_bytes()
    texte = _decoder(octets)
    if "\f" not in texte:
        return [{"page": None, "texte": nettoyer(_tableaux_texte(texte))}]
    return [
        {"page": numero, "texte": nettoyer(_tableaux_texte(bloc))}
        for numero, bloc in enumerate(texte.split("\f"), start=1)
    ]


LECTEURS: dict[str, Callable[[Path], list[dict]]] = {
    ".pdf": _lire_pdf,
    ".docx": _lire_docx,
    ".txt": _lire_txt,
}

REFUS_EXPLIQUES = {
    ".doc": "Format Word ancien (.doc) non pris en charge : enregistrez-le en .docx.",
}


def formats() -> list[str]:
    return sorted(extension.lstrip(".") for extension in LECTEURS)


def lire(chemin) -> list[dict]:
    """Pages non vides du document : [{"page": int | None, "texte": str}]."""
    chemin = Path(chemin)
    extension = chemin.suffix.lower()
    if extension in REFUS_EXPLIQUES:
        raise LectureImpossible(REFUS_EXPLIQUES[extension])
    lecteur = LECTEURS.get(extension)
    if lecteur is None:
        raise LectureImpossible(
            f"Format « {extension or 'sans extension'} » non pris en charge "
            f"(formats acceptés : {', '.join(formats())})."
        )
    if not chemin.is_file():
        raise LectureImpossible("Fichier introuvable sur le serveur.")
    try:
        pages = lecteur(chemin)
    except Exception as exc:
        raise LectureImpossible("Le fichier est illisible ou endommagé.") from exc
    pages = [page for page in pages if page["texte"].strip()]
    if not pages:
        if extension == ".pdf":
            raise LectureImpossible("Aucun texte trouvé dans ce PDF (document scanné ?).")
        raise LectureImpossible("Aucun texte trouvé dans ce document.")
    return pages
