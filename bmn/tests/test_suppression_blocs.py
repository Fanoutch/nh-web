"""Suppression des blocs des NH absentes de l'extraction.

Reproduit la suppression de lignes d'Excel : cellules remontées, formules,
fusions, mises en forme conditionnelles, hauteurs de ligne et zone d'impression
décalées ; une référence vers une ligne supprimée devient #REF!, comme dans Excel.
"""
from pathlib import Path

import pandas as pd
import pytest
from openpyxl import Workbook, load_workbook
from openpyxl.comments import Comment
from openpyxl.formatting.rule import CellIsRule
from openpyxl.styles import PatternFill

import config
import daily_report as dr
import suppression_blocs as sb

PAS = 24


def template_trois_blocs(chemin):
    """En-tête (lignes 1-8) + NH01 (9) + emplacement sans nom (33) + NH03 (57) + pied (81)."""
    wb = Workbook()
    ws = wb.active
    ws.title = "DISPO"
    ws["C2"] = "Etat de disponibilité du"
    ws["BC4"] = None
    for base, nom in ((9, "NH01"), (33, None), (57, "NH03")):
        if nom:
            ws[f"A{base}"] = nom
        ws[f"AQ{base + 1}"] = "FH"
        ws[f"R{base}"] = f"=BG{base + 8}"                       # même bloc
        ws[f"AJ{base + 9}"] = f'=IF(ISNUMBER(W{base + 9}),(W{base + 9}-BC4),"")'  # en-tête
        ws.merge_cells(f"L{base + 6}:Q{base + 6}")
        ws.row_dimensions[base + 1].height = 20 + base          # hauteur propre au bloc
    ws["L15"] = "HIL NH01"
    ws["L63"] = "HIL NH03"
    ws["AU58"] = 2339.16
    ws["AK66"] = '=IF(ISNUMBER(W66),(W66-BC29),"")'             # pointe dans le bloc NH01
    ws["AQ58"].comment = Comment("note NH03", "test")
    ws["A81"] = "<<<<<<<<<<<"
    ws.conditional_formatting.add(
        "I15:K17 I63:K65",
        CellIsRule(operator="lessThan", formula=["$BC$4+15"], fill=PatternFill("solid", fgColor="FF0000")))
    ws.print_area = "A1:AM80"
    wb.save(chemin)
    return chemin


@pytest.fixture
def feuille(tmp_path):
    return load_workbook(template_trois_blocs(tmp_path / "dispo.xlsx"))["DISPO"]


# ---------------------------------------------------------------------------
# Choix des blocs à supprimer
# ---------------------------------------------------------------------------
def test_blocs_absents_et_emplacement_sans_nom(feuille):
    lignes = sb.lignes_des_blocs_absents(feuille, presentes={"NH03"}, hauteur=PAS)
    assert lignes == set(range(9, 57))          # NH01 + emplacement sans nom


def test_aucune_suppression_si_toutes_presentes(feuille):
    assert sb.lignes_des_blocs_absents(feuille, presentes={"NH01", "NH03"}, hauteur=PAS) \
        == set(range(33, 57))                   # seul l'emplacement sans nom part


# ---------------------------------------------------------------------------
# Réécriture de formule (règles d'Excel)
# ---------------------------------------------------------------------------
def carte(supprimees, max_row=200):
    return sb.table_de_decalage(set(supprimees), max_row)


def test_formule_decalee_vers_le_haut():
    m = carte(range(9, 33))
    assert sb.decaler_formule("=BG65", m, "DISPO") == "=BG41"


def test_reference_en_tete_inchangee():
    m = carte(range(9, 33))
    assert sb.decaler_formule('=IF(ISNUMBER(W66),(W66-BC4),"")', m, "DISPO") \
        == '=IF(ISNUMBER(W42),(W42-BC4),"")'


def test_reference_vers_ligne_supprimee_devient_ref():
    m = carte(range(9, 33))
    assert sb.decaler_formule("=W66-BC29", m, "DISPO") == "=W42-#REF!"


def test_references_absolues_et_texte_preserves():
    m = carte(range(9, 33))
    assert sb.decaler_formule('=$BC$40&"A40"', m, "DISPO") == '=$BC$16&"A40"'


def test_autre_feuille_non_decalee():
    m = carte(range(9, 33))
    assert sb.decaler_formule("=Tuto!A40+A40", m, "DISPO") == "=Tuto!A40+A16"


def test_plage_retrecie_ou_supprimee():
    m = carte(range(9, 33))
    assert sb.decaler_formule("=SUM(A5:A40)", m, "DISPO") == "=SUM(A5:A16)"
    assert sb.decaler_formule("=SUM(A10:A20)", m, "DISPO") == "=SUM(#REF!)"


# ---------------------------------------------------------------------------
# Suppression complète sur une feuille
# ---------------------------------------------------------------------------
def test_suppression_remonte_les_cellules_et_les_formules(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))

    assert feuille["A9"].value == "NH03"
    assert feuille["L15"].value == "HIL NH03"
    assert feuille["AU10"].value == 2339.16
    assert feuille["R9"].value == "=BG17"                    # était =BG65 en ligne 57
    assert feuille["AJ18"].value == '=IF(ISNUMBER(W18),(W18-BC4),"")'   # en-tête conservé
    assert feuille["A33"].value == "<<<<<<<<<<<"             # pied remonté de 48 lignes
    assert feuille.max_row == 33


def test_aucune_cellule_des_lignes_supprimees_ne_subsiste(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    valeurs = [c.value for row in feuille.iter_rows() for c in row if c.value is not None]
    assert "HIL NH01" not in valeurs and "NH01" not in valeurs


def test_reference_croisee_vers_bloc_supprime_devient_ref(feuille):
    """AK66 pointe vers BC29, dans le bloc NH01 supprimé : #REF!, comme Excel."""
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    assert feuille["AK18"].value == '=IF(ISNUMBER(W18),(W18-#REF!),"")'


def test_fusions_decalees_et_supprimees(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    fusions = {str(r) for r in feuille.merged_cells.ranges}
    assert fusions == {"L15:Q15"}                            # celle de NH03 (L63), remontée


def test_mise_en_forme_conditionnelle_decalee(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    plages = [str(cf.sqref) for cf in feuille.conditional_formatting]
    assert plages == ["I15:K17"]                             # I63:K65 remontée, I15:K17 (NH01) supprimée
    regle = list(feuille.conditional_formatting)[0].rules[0]
    assert regle.formula == ["$BC$4+15"]


def test_hauteurs_de_ligne_decalees(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    assert feuille.row_dimensions[10].height == 20 + 57      # hauteur de la ligne 58


def test_commentaire_suit_sa_cellule(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    assert feuille["AQ10"].comment is not None
    assert feuille["AQ10"].comment.text == "note NH03"


def test_zone_dimpression_ajustee(feuille):
    sb.supprimer_lignes(feuille, set(range(9, 57)))
    assert feuille.print_area.endswith("$AM$32")


# ---------------------------------------------------------------------------
# Intégration dans le pipeline
# ---------------------------------------------------------------------------
@pytest.fixture
def pipeline(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "TEMPLATE_PATH", template_trois_blocs(tmp_path / "dispo.xlsx"))
    monkeypatch.setattr(config, "LOG_FILE", tmp_path / "logs" / "t.log")
    monkeypatch.setattr(config, "BLOCS", dict(config.BLOCS, actif=True, supprimer_absentes=True))
    return tmp_path


def test_process_ne_garde_que_les_nh_de_lextraction(pipeline):
    source = pipeline / "dispo_2026-09-16.csv"
    source.write_text("machine;zone;date;equipement;ref;fh\nNH03;;;;;1500\n", encoding="utf-8-sig")

    resultat = dr.process(source, dry_run=True, output_dir=pipeline / "out")

    ws = load_workbook(resultat.output_path)["DISPO"]
    noms = [c.value for c in ws["A"] if isinstance(c.value, str) and c.value.startswith("NH")]
    assert noms == ["NH03"]
    assert ws["A9"].value == "NH03" and ws["AU10"].value == 1500


def test_option_desactivee_garde_tous_les_blocs(pipeline, monkeypatch):
    monkeypatch.setattr(config, "BLOCS", dict(config.BLOCS, supprimer_absentes=False))
    source = pipeline / "dispo_2026-09-16.csv"
    source.write_text("machine;zone;date;equipement;ref;fh\nNH03;;;;;1500\n", encoding="utf-8-sig")

    resultat = dr.process(source, dry_run=True, output_dir=pipeline / "out")

    ws = load_workbook(resultat.output_path)["DISPO"]
    assert ws["A9"].value == "NH01" and ws["A57"].value == "NH03"


# ---------------------------------------------------------------------------
# Vrai template (poste de dev uniquement : ignoré par git)
# ---------------------------------------------------------------------------
VRAI = Path(config.BASE_DIR) / "template" / "dispo.xlsx"


@pytest.mark.skipif(not VRAI.exists(), reason="template réel absent (bmn/template/dispo.xlsx)")
def test_vrai_template_formules_coherentes_apres_suppression():
    wb = load_workbook(VRAI)
    ws = wb["DISPO"]
    avant = load_workbook(VRAI)["DISPO"]
    blocs = dr.reperer_blocs(avant)
    garder = {"NH03", "NH10", "NH27"}
    lignes = sb.lignes_des_blocs_absents(ws, presentes=garder, hauteur=PAS)
    table = sb.table_de_decalage(lignes, ws.max_row)
    sb.supprimer_lignes(ws, lignes)

    # chaque formule d'un bloc conservé = formule d'origine décalée selon les règles d'Excel
    for nom in garder:
        base = blocs[nom]
        for r in range(base, base + PAS):
            for c in avant[r]:
                if isinstance(c.value, str) and c.value.startswith("="):
                    attendu = sb.decaler_formule(c.value, table, "DISPO")
                    assert ws.cell(table[r], c.column).value == attendu, c.coordinate
    noms = [c.value.strip() for c in ws["A"] if isinstance(c.value, str) and c.value.strip().startswith("NH")]
    assert noms == ["NH03", "NH10", "NH27"]
    assert all(r.max_row <= ws.max_row for r in ws.merged_cells.ranges)


# ===========================================================================
#  Duplication : une NH de l'extraction absente du template obtient son bloc
# ===========================================================================
def test_machines_absentes_du_template(feuille):
    assert sb.machines_absentes(feuille, presentes={"NH01", "NH02", "NH03"}, hauteur=PAS) == ["NH02"]


def test_aucune_absente_si_toutes_sont_la(feuille):
    assert sb.machines_absentes(feuille, presentes={"NH01", "NH03"}, hauteur=PAS) == []


def test_ordre_numerique_des_machines_absentes(feuille):
    assert sb.machines_absentes(feuille, presentes={"NH10", "NH02", "NH04"}, hauteur=PAS) \
        == ["NH02", "NH04", "NH10"]


# ---------------------------------------------------------------------------
# Insertion de lignes (mouvement inverse de la suppression)
# ---------------------------------------------------------------------------
def test_inserer_lignes_descend_les_cellules_et_les_formules(feuille):
    sb.inserer_lignes(feuille, position=33, nombre=PAS)

    assert feuille["A9"].value == "NH01"                  # au-dessus : inchangé
    assert feuille["A81"].value == "NH03"                 # était en 57, descendu de 24
    assert feuille["R81"].value == "=BG89"                # formule suivie (était =BG65)
    assert feuille["AJ90"].value == '=IF(ISNUMBER(W90),(W90-BC4),"")'   # en-tête inchangé
    assert feuille["A105"].value == "<<<<<<<<<<<"         # pied descendu
    assert feuille["A33"].value is None                   # place libérée


def test_inserer_lignes_decale_fusions_hauteurs_et_mfc(feuille):
    hauteur_nh03 = feuille.row_dimensions[58].height
    sb.inserer_lignes(feuille, position=33, nombre=PAS)

    fusions = {str(r) for r in feuille.merged_cells.ranges}
    assert "L15:Q15" in fusions and "L87:Q87" in fusions   # NH01 en place, NH03 descendue
    assert feuille.row_dimensions[82].height == hauteur_nh03
    plages = " ".join(str(cf.sqref) for cf in feuille.conditional_formatting)
    assert "I15:K17" in plages and "I87:K89" in plages


# ---------------------------------------------------------------------------
# Duplication d'un bloc
# ---------------------------------------------------------------------------
def test_duplique_le_bloc_modele_a_sa_place_numerique(feuille):
    sb.dupliquer_bloc(feuille, nom="NH02", modele=9, hauteur=PAS, position=33)

    assert feuille["A33"].value == "NH02"                  # nom écrit dans la case
    assert feuille["AQ34"].value == "FH"                   # intitulés recopiés
    assert feuille["R33"].value == "=BG41"                 # formule du bloc, décalée
    assert feuille["AJ42"].value == '=IF(ISNUMBER(W42),(W42-BC4),"")'   # en-tête conservé
    assert feuille["A81"].value == "NH03"                  # NH03 poussée plus bas (24 lignes)
    assert feuille["R81"].value == "=BG89"


def test_duplique_sans_recopier_les_donnees_du_modele(feuille):
    feuille["AU10"] = 1234            # compteur du modèle NH01
    feuille["L15"] = "vieux HIL"

    sb.dupliquer_bloc(feuille, nom="NH02", modele=9, hauteur=PAS, position=33,
                      colonnes_donnees=("AU", "I", "L", "R"))

    assert feuille["AU34"].value is None                   # nouveau bloc vierge
    assert feuille["L39"].value is None
    assert feuille["AU10"].value == 1234                   # modèle intact
    assert feuille["L15"].value == "vieux HIL"
    assert feuille["R33"].value == "=BG41"                 # les formules ne sont jamais vidées


def test_duplique_fusions_et_hauteurs(feuille):
    sb.dupliquer_bloc(feuille, nom="NH02", modele=9, hauteur=PAS, position=33)

    fusions = {str(r) for r in feuille.merged_cells.ranges}
    assert "L39:Q39" in fusions                            # fusion du bloc recopiée
    assert feuille.row_dimensions[34].height == feuille.row_dimensions[10].height


# ---------------------------------------------------------------------------
# Intégration dans le pipeline
# ---------------------------------------------------------------------------
@pytest.fixture
def pipeline_dup(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "TEMPLATE_PATH", template_trois_blocs(tmp_path / "dispo.xlsx"))
    monkeypatch.setattr(config, "LOG_FILE", tmp_path / "logs" / "t.log")
    monkeypatch.setattr(config, "BLOCS", dict(config.BLOCS, actif=True,
                                              supprimer_absentes=True, dupliquer_absentes=True))
    return tmp_path


def source_csv(dossier, lignes):
    chemin = dossier / "dispo_2026-09-16.csv"
    chemin.write_text("machine;zone;date;equipement;ref;fh\n" + lignes, encoding="utf-8-sig")
    return chemin


def test_process_cree_le_bloc_dune_machine_nouvelle(pipeline_dup):
    source = source_csv(pipeline_dup, "NH01;;;;;100\nNH02;;;;;200\nNH03;;;;;300\n")

    resultat = dr.process(source, dry_run=True, output_dir=pipeline_dup / "out")

    ws = load_workbook(resultat.output_path)["DISPO"]
    noms = [c.value for c in ws["A"] if isinstance(c.value, str) and c.value.startswith("NH")]
    assert noms == ["NH01", "NH02", "NH03"]                # ordre numérique respecté
    blocs = dr.reperer_blocs(ws)
    assert ws[f"AU{blocs['NH02'] + 1}"].value == 200       # bloc nouveau rempli
    assert ws[f"AU{blocs['NH03'] + 1}"].value == 300       # bloc existant rempli


def test_process_sans_duplication_ignore_la_machine_nouvelle(pipeline_dup, monkeypatch):
    monkeypatch.setattr(config, "BLOCS", dict(config.BLOCS, dupliquer_absentes=False))
    source = source_csv(pipeline_dup, "NH01;;;;;100\nNH02;;;;;200\n")

    resultat = dr.process(source, dry_run=True, output_dir=pipeline_dup / "out")

    ws = load_workbook(resultat.output_path)["DISPO"]
    noms = [c.value for c in ws["A"] if isinstance(c.value, str) and c.value.startswith("NH")]
    assert noms == ["NH01"]


def test_duplique_un_bloc_dont_les_cellules_de_donnees_sont_fusionnees(feuille):
    """Sur le vrai template, les cases de valeurs sont fusionnées (ex. AU10:AX10)."""
    feuille.merge_cells("AU10:AX10")
    feuille["AU10"] = 1234

    sb.dupliquer_bloc(feuille, nom="NH02", modele=9, hauteur=PAS, position=33,
                      colonnes_donnees=("AU", "AV", "I", "L", "R"))

    assert feuille["A33"].value == "NH02"
    assert feuille["AU34"].value is None                    # bloc neuf vide
    assert "AU34:AX34" in {str(r) for r in feuille.merged_cells.ranges}
    assert feuille["AU10"].value == 1234                    # modèle intact


def test_zone_dimpression_couvre_les_blocs_ajoutes(feuille):
    """Un bloc créé après la fin de la zone d'impression doit rester imprimable."""
    feuille.print_area = "A1:AM80"
    sb.dupliquer_bloc(feuille, nom="NH28", modele=9, hauteur=PAS, position=81)

    sb.ajuster_zone_impression(feuille)

    assert feuille.print_area.endswith(f"$AM${feuille.max_row}")


def test_zone_dimpression_inchangee_si_deja_suffisante(feuille):
    feuille.print_area = "A1:AM200"
    sb.ajuster_zone_impression(feuille)
    assert feuille.print_area.endswith("$AM$200")
