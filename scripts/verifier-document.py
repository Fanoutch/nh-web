#!/usr/bin/env python3
"""Inventaire d'un ou plusieurs documents Word, SANS jamais afficher leur contenu.

    assistant/.venv/bin/python scripts/verifier-document.py <fichier.docx | fichier.doc | dossier> [...]

Pour chaque document : le nombre de chaque élément (paragraphes, tableaux, encadrés, contrôles de contenu, champs de
formulaire, cases à cocher, listes déroulantes, notes, en-têtes et pieds de page, images, équations, modifications
suivies) et ce que l'agent ne saura PAS remplir. Un .doc est d'abord converti (copie temporaire) si LibreOffice est
présent ; sinon il est signalé. Le résultat peut être collé tel quel dans un rapport : il ne contient aucun texte du
document.
"""
from __future__ import annotations

import shutil
import sys
import tempfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "assistant"))

from docx import Document  # noqa: E402

from outils import conversion  # noqa: E402
from outils import word_texte as wt  # noqa: E402

LIBELLES = {
    "paragraphes": "paragraphes", "tableaux": "tableaux", "encadres": "encadrés (zones de texte)",
    "controles_de_contenu": "contrôles de contenu", "champs_formulaire_texte": "champs de formulaire (cases grises)",
    "cases_a_cocher": "cases à cocher", "listes_deroulantes": "listes déroulantes", "notes": "parties de notes avec texte",
    "entetes_pieds": "en-têtes / pieds de page avec texte", "images": "images", "equations": "équations",
    "modifications_suivies": "modifications suivies", "sections": "sections",
}


def examiner(chemin: Path) -> None:
    print(f"\n== {chemin.name}")
    source = chemin
    with tempfile.TemporaryDirectory() as tmp:
        if chemin.suffix.lower() == ".doc":
            if conversion.convertisseur() is None:
                print("  ancien format .doc : LibreOffice absent ici, inventaire impossible (sur le serveur, il sera converti au dépôt)")
                return
            copie = Path(tmp) / chemin.name
            shutil.copy2(chemin, copie)
            try:
                source = conversion.convertir(copie)
            except conversion.ConversionImpossible as exc:
                print(f"  conversion impossible : {exc}")
                return
            print("  ancien format .doc : converti (copie temporaire) pour l'inventaire")
        try:
            inv = wt.inventaire(Document(str(source)))
        except Exception as exc:  # noqa: BLE001
            print(f"  illisible : {type(exc).__name__}")
            return
    for cle, libelle in LIBELLES.items():
        if inv.get(cle):
            print(f"  {libelle} : {inv[cle]}")
    alertes = wt.avertissements(inv)
    print("  NON PRIS EN CHARGE : " + ("; ".join(alertes) if alertes else "aucun"))


def main() -> None:
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    fichiers: list[Path] = []
    for arg in sys.argv[1:]:
        p = Path(arg)
        fichiers += sorted(f for f in p.iterdir() if f.suffix.lower() in (".docx", ".doc")) if p.is_dir() else [p]
    for f in fichiers:
        examiner(f)


if __name__ == "__main__":
    main()
