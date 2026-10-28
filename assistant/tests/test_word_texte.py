"""Couche texte générale : tout le texte visible d'un .docx, où qu'il soit (contrôles de contenu, encadrés, notes…)."""
import zipfile

import openpyxl
import pytest
from docx import Document
from docx.oxml import parse_xml

import outils  # noqa: F401 — enregistre les outils
from outils import word_texte as wt
from outils.registre import REGISTRE, Contexte, executer

NS = ('xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
      'xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml"')


def sdt_bloc(texte):
    """Contrôle de contenu « bloc » (Word récent : « Cliquez ici pour entrer du texte »)."""
    return parse_xml(f'<w:sdt {NS}><w:sdtPr><w:alias w:val="Grade"/></w:sdtPr><w:sdtContent>'
                     f'<w:p><w:r><w:t xml:space="preserve">{texte}</w:t></w:r></w:p></w:sdtContent></w:sdt>')


def sdt_en_ligne(avant, texte):
    return parse_xml(f'<w:p {NS}><w:r><w:t xml:space="preserve">{avant}</w:t></w:r><w:sdt><w:sdtPr/><w:sdtContent>'
                     f'<w:r><w:t xml:space="preserve">{texte}</w:t></w:r></w:sdtContent></w:sdt></w:p>')


def case_a_cocher():
    return parse_xml(f'<w:p {NS}><w:sdt><w:sdtPr><w14:checkbox/></w:sdtPr><w:sdtContent><w:r><w:t>☐</w:t></w:r>'
                     f'</w:sdtContent></w:sdt><w:r><w:t xml:space="preserve"> Apte</w:t></w:r></w:p>')


@pytest.fixture
def ctx(tmp_path):
    entree = tmp_path / "entree"
    entree.mkdir()
    d = Document()
    d.add_paragraph("FICHE")
    corps = d.element.body
    corps.insert(len(corps) - 1, sdt_en_ligne("Nom : ", "Cliquez ici"))
    corps.insert(len(corps) - 1, parse_xml(f'<w:p {NS}><w:r><w:t>Grade</w:t></w:r></w:p>'))
    corps.insert(len(corps) - 1, sdt_bloc("........"))
    corps.insert(len(corps) - 1, case_a_cocher())
    d.sections[0].footer.paragraphs[0].text = "Réf. ........"
    d.save(entree / "fiche.docx")
    wb = openpyxl.Workbook()
    wb.active.append(["Nom", "Grade", "Référence"])
    wb.active.append(["DURAND", "Adjudant", "R-1"])
    wb.active.append(["MARTIN", "Sergent", "R-2"])
    wb.save(entree / "liste.xlsx")
    return Contexte(dossier_execution=entree, fichiers_autorises=["fiche.docx", "liste.xlsx"],
                    outils_autorises=set(REGISTRE), dossier_sortie=tmp_path / "sortie")


def test_tout_le_texte_est_lu_y_compris_controles_de_contenu_et_pied_de_page(ctx):
    r = executer("word_lire", {"fichier": "fiche.docx"}, ctx)

    texte = str(r.donnees)
    assert "Nom : Cliquez ici" in texte and "........" in texte and "[pied de page] Réf. ........" in texte


def test_inventaire_compte_sans_rien_montrer_et_previent_des_cases_a_cocher(ctx):
    d = Document(str(ctx.dossier_execution / "fiche.docx"))

    inv = wt.inventaire(d)

    assert inv["controles_de_contenu"] == 3 and inv["cases_a_cocher"] == 1 and inv["entetes_pieds"] >= 1
    assert wt.avertissements(inv) == ["cases à cocher (elles ne seront pas cochées)"]
    r = executer("word_decrire", {"fichier": "fiche.docx"}, ctx)
    assert "cases à cocher" in r.resume + r.suite + str(r.donnees)


def test_publipostage_remplit_controles_de_contenu_et_pied_de_page(ctx):
    r = executer("word_publipostage", {"fichier_modele": "fiche.docx", "fichier_excel": "liste.xlsx", "champs": [
        {"remplacer": "Cliquez ici", "colonne": "Nom", "precedent": "Nom"},
        {"remplacer": "........", "colonne": "Grade", "precedent": "Grade"},
        {"remplacer": "........", "colonne": "Référence", "precedent": "Réf"}]}, ctx)

    assert r.statut == "ok", r.resume
    martin = next(n for n in r.donnees["fichiers_produits"] if "MARTIN" in n)
    with zipfile.ZipFile(ctx.dossier_sortie / martin) as z:
        corps = z.read("word/document.xml").decode()
        pied = next(z.read(n).decode() for n in z.namelist() if n.startswith("word/footer"))
    assert "MARTIN" in corps and "Sergent" in corps and "Cliquez ici" not in corps and "........" not in corps
    assert "Réf. R-2" in pied and "w:sdt" in corps                     # les contrôles de contenu restent en place


def test_remplacer_partout_dans_une_note_de_bas_de_page(tmp_path):
    # une note : partie sans classe dédiée dans python-docx, relue et réécrite par la couche texte
    import subprocess

    from outils import conversion
    if conversion.convertisseur() is None:
        pytest.skip("LibreOffice absent de ce poste")
    (tmp_path / "n.fodt").write_text(
        '<?xml version="1.0" encoding="UTF-8"?><office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2" '
        'office:mimetype="application/vnd.oasis.opendocument.text"><office:body><office:text><text:p>Texte'
        '<text:note text:note-class="footnote"><text:note-citation>1</text:note-citation><text:note-body>'
        '<text:p>Source : {{SOURCE}}</text:p></text:note-body></text:note></text:p></office:text></office:body></office:document>',
        encoding="utf-8")
    subprocess.run([conversion.convertisseur(), "--headless", "--norestore", "--convert-to", "docx", "--outdir", str(tmp_path),
                    str(tmp_path / "n.fodt")], check=True, capture_output=True, timeout=120)
    ctx = Contexte(dossier_execution=tmp_path, fichiers_autorises=["n.docx"], outils_autorises=set(REGISTRE),
                   dossier_sortie=tmp_path / "sortie")

    r = executer("word_regenerer", {"fichier_source": "n.docx", "nom_sortie": "o.docx",
                                    "modifications": [{"remplacer": "{{SOURCE}}", "par": "Manuel 2026"}]}, ctx)

    assert r.statut == "ok", r.resume
    with zipfile.ZipFile(tmp_path / "sortie" / "o.docx") as z:
        assert "Source : Manuel 2026" in z.read("word/footnotes.xml").decode()
