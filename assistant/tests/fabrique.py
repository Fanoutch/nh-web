"""Fabrique de petits fichiers de test (PDF avec ou sans texte, Word)."""
from pathlib import Path

from docx import Document
from pypdf import PdfWriter


def pdf_texte(chemin, lignes: list[str]) -> Path:
    """PDF d'une page contenant `lignes` (ASCII) en texte sélectionnable."""
    flux = "BT /F1 12 Tf 72 720 Td " + " ".join(f"({l}) Tj 0 -16 Td" for l in lignes) + " ET"
    objets = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R "
        "/Resources << /Font << /F1 5 0 R >> >> >>",
        f"<< /Length {len(flux)} >>\nstream\n{flux}\nendstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    sortie = "%PDF-1.4\n"
    positions = []
    for numero, objet in enumerate(objets, start=1):
        positions.append(len(sortie.encode("latin-1")))
        sortie += f"{numero} 0 obj\n{objet}\nendobj\n"
    xref = len(sortie.encode("latin-1"))
    sortie += f"xref\n0 {len(objets) + 1}\n0000000000 65535 f \n"
    sortie += "".join(f"{p:010d} 00000 n \n" for p in positions)
    sortie += f"trailer\n<< /Size {len(objets) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n"
    chemin = Path(chemin)
    chemin.write_bytes(sortie.encode("latin-1"))
    return chemin


def pdf_vide(chemin) -> Path:
    """PDF d'une page sans aucun texte (comme un scan)."""
    ecrivain = PdfWriter()
    ecrivain.add_blank_page(width=612, height=792)
    chemin = Path(chemin)
    with chemin.open("wb") as f:
        ecrivain.write(f)
    return chemin


def docx_texte(chemin, paragraphes: list[str]) -> Path:
    document = Document()
    for paragraphe in paragraphes:
        document.add_paragraph(paragraphe)
    chemin = Path(chemin)
    document.save(str(chemin))
    return chemin
