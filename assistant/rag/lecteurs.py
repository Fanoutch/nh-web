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


def _lire_docx(chemin: Path) -> list[dict]:
    document = DocumentWord(str(chemin))
    blocs = [p.text for p in document.paragraphs if p.text.strip()]
    for tableau in document.tables:
        for ligne in tableau.rows:
            blocs.append(" | ".join(cellule.text.strip() for cellule in ligne.cells))
    return [{"page": None, "texte": nettoyer("\n\n".join(blocs))}]


def _lire_txt(chemin: Path) -> list[dict]:
    texte = chemin.read_text(encoding="utf-8", errors="replace")
    if "\f" not in texte:
        return [{"page": None, "texte": nettoyer(texte)}]
    return [
        {"page": numero, "texte": nettoyer(bloc)}
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
