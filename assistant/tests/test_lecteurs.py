import pytest

from rag import lecteurs
from tests.fabrique import docx_texte, pdf_texte, pdf_vide


def test_formats_acceptes():
    assert lecteurs.formats() == ["docx", "pdf", "txt"]


def test_lit_un_pdf_page_par_page(tmp_path):
    chemin = pdf_texte(tmp_path / "manuel.pdf", ["Le vol de maintenance", "est ordonne par le chef BMN"])

    pages = lecteurs.lire(chemin)

    assert len(pages) == 1
    assert pages[0]["page"] == 1
    assert "vol de maintenance" in pages[0]["texte"]


def test_pdf_sans_texte_est_refuse(tmp_path):
    with pytest.raises(lecteurs.LectureImpossible, match=r"Aucun texte trouvé dans ce PDF \(document scanné \?\)\."):
        lecteurs.lire(pdf_vide(tmp_path / "scan.pdf"))


def test_lit_un_word(tmp_path):
    chemin = docx_texte(tmp_path / "procedure.docx", ["Titre", "Le bordereau de visite est rempli par le technicien."])

    pages = lecteurs.lire(chemin)

    assert pages == [{"page": None, "texte": "Titre\n\nLe bordereau de visite est rempli par le technicien."}]


def test_lit_les_tableaux_word(tmp_path):
    from docx import Document
    document = Document()
    tableau = document.add_table(rows=1, cols=2)
    tableau.rows[0].cells[0].text = "Machine"
    tableau.rows[0].cells[1].text = "NH-12"
    document.save(str(tmp_path / "t.docx"))

    assert "Machine | NH-12" in lecteurs.lire(tmp_path / "t.docx")[0]["texte"]


def test_lit_un_txt_avec_sauts_de_page(tmp_path):
    chemin = tmp_path / "note.txt"
    chemin.write_text("Page un\fPage deux", encoding="utf-8")

    assert lecteurs.lire(chemin) == [{"page": 1, "texte": "Page un"}, {"page": 2, "texte": "Page deux"}]


def test_ancien_word_refuse_avec_conseil(tmp_path):
    chemin = tmp_path / "vieux.doc"
    chemin.write_bytes(b"\xd0\xcf\x11\xe0")

    with pytest.raises(lecteurs.LectureImpossible, match=r"enregistrez-le en \.docx"):
        lecteurs.lire(chemin)


def test_format_inconnu_refuse(tmp_path):
    chemin = tmp_path / "image.png"
    chemin.write_bytes(b"x")

    with pytest.raises(lecteurs.LectureImpossible, match="non pris en charge"):
        lecteurs.lire(chemin)


def test_fichier_endommage(tmp_path):
    chemin = tmp_path / "casse.pdf"
    chemin.write_bytes(b"pas un pdf")

    with pytest.raises(lecteurs.LectureImpossible, match="illisible ou endommagé") as info:
        lecteurs.lire(chemin)
    assert info.value.__cause__ is not None


def test_fichier_absent(tmp_path):
    with pytest.raises(lecteurs.LectureImpossible, match="introuvable"):
        lecteurs.lire(tmp_path / "absent.pdf")


def test_lit_un_txt_windows_cp1252(tmp_path):
    chemin = tmp_path / "ancien.txt"
    chemin.write_bytes("Procédure : contrôle du bordereau".encode("cp1252"))

    assert lecteurs.lire(chemin) == [{"page": None, "texte": "Procédure : contrôle du bordereau"}]
