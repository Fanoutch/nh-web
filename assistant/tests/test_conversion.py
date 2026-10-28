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


FODT = """<?xml version="1.0" encoding="UTF-8"?>
<office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
 xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"
 office:version="1.2" office:mimetype="application/vnd.oasis.opendocument.text"><office:body><office:text>
<text:p>FORMULAIRE</text:p>
<text:p><draw:frame draw:name="Cadre1" text:anchor-type="paragraph" svg:width="8cm" svg:height="2cm"><draw:text-box>
<text:p>Grade : ........</text:p></draw:text-box></draw:frame></text:p>
<text:p>Nom : <text:text-input text:description="champ">     </text:text-input></text:p>
</office:text></office:body></office:document>"""


@pytest.mark.skipif(conversion.convertisseur() is None, reason="LibreOffice absent de ce poste")
def test_doc_avec_encadre_et_champ_de_formulaire_lu_et_rempli(tmp_path):
    import openpyxl
    import outils  # noqa: F401
    from outils.registre import REGISTRE, Contexte, executer

    (tmp_path / "forme.fodt").write_text(FODT, encoding="utf-8")
    subprocess.run([conversion.convertisseur(), "--headless", "--norestore", "--convert-to", "doc", "--outdir", str(tmp_path),
                    str(tmp_path / "forme.fodt")], check=True, capture_output=True, timeout=120)
    conversion.convertir(tmp_path / "forme.doc")
    wb = openpyxl.Workbook()
    wb.active.append(["Nom", "Grade"])
    wb.active.append(["DURAND", "Adjudant"])
    wb.save(tmp_path / "liste.xlsx")
    ctx = Contexte(dossier_execution=tmp_path, fichiers_autorises=["forme.docx", "liste.xlsx"],
                   outils_autorises=set(REGISTRE), dossier_sortie=tmp_path / "sortie")

    lu = executer("word_lire", {"fichier": "forme.docx"}, ctx)
    assert "[encadré] Grade : ........" in str(lu.donnees) and "Nom : [champ]" in str(lu.donnees)

    r = executer("word_publipostage", {"fichier_modele": "forme.docx", "fichier_excel": "liste.xlsx", "champs": [
        {"remplacer": "........", "colonne": "Grade", "precedent": "Grade"},
        {"remplacer": "[champ]", "colonne": "Nom", "precedent": "Nom"}]}, ctx)

    assert r.statut == "ok", r.resume
    xml = (tmp_path / "sortie" / r.donnees["fichiers_produits"][0])
    import zipfile
    contenu = zipfile.ZipFile(xml).read("word/document.xml").decode()
    assert contenu.count("Grade : Adjudant") == 2 and "........" not in contenu    # encadré : les deux copies remplies
    assert "DURAND" in contenu and "FORMTEXT" in contenu                            # le champ reste un champ, rempli
