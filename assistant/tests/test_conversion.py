import subprocess

import pytest
from docx import Document

from outils import conversion


def fabriquer_doc(dossier):
    """Un vrai .doc (Word 97-2003) avec tableau, gras et en-tête, fabriqué par LibreOffice."""
    d = Document()
    p = d.add_paragraph()
    p.add_run("Nom : ").bold = True
    p.add_run("........")
    t = d.add_table(rows=1, cols=2)
    t.cell(0, 0).text, t.cell(0, 1).text = "Grade", "........"
    d.sections[0].header.paragraphs[0].text = "EN-TÊTE"
    d.save(dossier / "modele.docx")
    subprocess.run([conversion.convertisseur(), "--headless", "--norestore", "--convert-to", "doc", "--outdir", str(dossier),
                    str(dossier / "modele.docx")], check=True, capture_output=True, timeout=120)
    (dossier / "modele.docx").unlink()
    return dossier / "modele.doc"


@pytest.mark.skipif(conversion.convertisseur() is None, reason="LibreOffice absent de ce poste")
def test_un_doc_devient_un_docx_avec_sa_mise_en_forme(tmp_path):
    doc = fabriquer_doc(tmp_path)

    sortie = conversion.convertir(doc)

    d = Document(str(sortie))
    assert sortie.name == "modele.docx" and doc.exists()
    assert d.paragraphs[0].text == "Nom : ........" and d.paragraphs[0].runs[0].bold
    assert [c.text for c in d.tables[0].rows[0].cells] == ["Grade", "........"]
    assert d.sections[0].header.paragraphs[0].text == "EN-TÊTE"


def test_sans_libreoffice_refus_avec_la_marche_a_suivre(tmp_path, monkeypatch):
    (tmp_path / "f.doc").write_bytes(b"x")
    monkeypatch.setattr(conversion, "convertisseur", lambda: None)

    with pytest.raises(conversion.ConversionImpossible, match="Enregistrer sous » au format .docx"):
        conversion.convertir(tmp_path / "f.doc")


def test_un_fichier_illisible_est_refuse_proprement(tmp_path):
    if conversion.convertisseur() is None:
        pytest.skip("LibreOffice absent de ce poste")
    (tmp_path / "abime.xls").write_bytes(b"ceci n'est pas un classeur")

    with pytest.raises(conversion.ConversionImpossible):
        conversion.convertir(tmp_path / "abime.xls")
