"""Conversion des anciens formats (Word / Excel 97-2003) vers .docx / .xlsx, mise en forme comprise.

Seul LibreOffice sait le faire fidèlement (tableaux, gras, images, en-têtes) ; il doit être installé sur le serveur
du service (commande « soffice » ou « libreoffice »). Sans lui, la conversion est refusée avec la marche à suivre :
jamais de document « à plat » qui ne ressemblerait plus au modèle.
"""
from __future__ import annotations

import shutil
import subprocess
import tempfile
from pathlib import Path

CIBLES = {".doc": "docx", ".xls": "xlsx"}
DELAI_SECONDES = 120


class ConversionImpossible(Exception):
    """Message lisible par l'utilisateur."""


def convertisseur() -> str | None:
    return shutil.which("soffice") or shutil.which("libreoffice")


def _marche_a_suivre(nom: str, cible: str) -> str:
    logiciel = "Word" if cible == "docx" else "Excel"
    return (f"Ouvrez « {nom} » dans {logiciel}, faites « Enregistrer sous » au format .{cible}, puis déposez ce nouveau "
            "fichier : la mise en forme sera conservée.")


def convertir(chemin: Path) -> Path:
    """Convertit `chemin` (.doc / .xls) à côté de lui ; renvoie le nouveau fichier. L'original n'est pas touché."""
    cible = CIBLES.get(chemin.suffix.lower())
    if cible is None:
        raise ConversionImpossible(f"« {chemin.name} » n'est pas un ancien format à convertir.")
    programme = convertisseur()
    if programme is None:
        raise ConversionImpossible(f"« {chemin.name} » est à l'ancien format {chemin.suffix.lower()} et ce serveur ne sait pas "
                                   "le convertir (LibreOffice absent). " + _marche_a_suivre(chemin.name, cible))
    sortie = chemin.with_suffix("." + cible)
    if sortie.exists():
        raise ConversionImpossible(f"Un fichier « {sortie.name} » existe déjà : supprimez-le ou renommez « {chemin.name} ».")
    with tempfile.TemporaryDirectory() as tmp:
        profil = Path(tmp) / "profil"            # profil LibreOffice à part : deux conversions simultanées ne se bloquent pas
        try:
            subprocess.run([programme, f"-env:UserInstallation={profil.as_uri()}", "--headless", "--norestore",
                            "--convert-to", cible, "--outdir", tmp, str(chemin)],
                           check=True, capture_output=True, timeout=DELAI_SECONDES)
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired, OSError) as exc:
            raise ConversionImpossible(f"La conversion de « {chemin.name} » a échoué ({type(exc).__name__}). "
                                       + _marche_a_suivre(chemin.name, cible)) from exc
        produit = Path(tmp) / sortie.name
        if not produit.is_file() or produit.stat().st_size == 0:
            raise ConversionImpossible(f"La conversion de « {chemin.name} » n'a rien produit. " + _marche_a_suivre(chemin.name, cible))
        shutil.move(str(produit), sortie)
    return sortie
