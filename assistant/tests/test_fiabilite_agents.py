"""Filtres sur les dates, copie par le code, recette rejouée sans LLM."""
import datetime

import openpyxl
import pytest
from docx import Document

import outils  # noqa: F401 — enregistre les outils
from agents.recette import associer_fichiers, est_rejouable, rejouer
from outils.condition import ErreurCondition, filtrer
from outils.registre import REGISTRE, Contexte, executer
from outils.texte import en_date


@pytest.fixture
def ctx(tmp_path):
    entree = tmp_path / "entree"
    entree.mkdir()
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Suivi"
    for l in [["Nom", "Fin", "FinTexte", "Stock", "Note"],
              ["Bravo", datetime.date(2027, 1, 15), "15 janvier 2027", 5, "x"],
              ["Alpha", datetime.date(2029, 6, 1), "1er juin 2029", 2, "x"],
              ["Charlie", datetime.date(2026, 3, 8), "8 mars 2026", 9, "x"],
              ["Delta", None, "Néant", 9, "x"]]:
        ws.append(l)
    wb.save(entree / "suivi.xlsx")
    d = Document()
    t = d.add_table(rows=2, cols=2)
    t.cell(0, 0).text, t.cell(0, 1).text = "Réf", "Qté"
    t.cell(1, 0).text, t.cell(1, 1).text = "A-1", "4"
    d.save(entree / "note.docx")
    return Contexte(dossier_execution=entree, fichiers_autorises=["suivi.xlsx", "note.docx"], outils_autorises=set(REGISTRE),
                    dossier_sortie=tmp_path / "sortie")


def noms(ctx, **condition):
    r = executer("excel_selectionner", {"fichier": "suivi.xlsx", "condition": condition}, ctx)
    assert r.statut == "ok", r.resume
    return sorted(l["Nom"] if isinstance(l, dict) else l[0] for l in r.donnees["lignes"]), r


@pytest.mark.parametrize("texte, attendu", [
    ("15/01/2027", datetime.date(2027, 1, 15)), ("15-01-27", datetime.date(2027, 1, 15)), ("2027-01-15", datetime.date(2027, 1, 15)),
    ("15 janvier 2027", datetime.date(2027, 1, 15)), ("1er FÉVRIER 2027", datetime.date(2027, 2, 1)), ("31/02/2027", None),
    ("Néant", None), ("12", None), (12, None), (datetime.datetime(2027, 1, 15, 8), datetime.date(2027, 1, 15)),
])
def test_en_date(texte, attendu):
    assert en_date(texte) == attendu


@pytest.mark.parametrize("valeur", ["01/01/2028", "1er janvier 2028", "2028-01-01"])
def test_filtre_sur_vraies_dates_quel_que_soit_le_format(ctx, valeur):
    assert noms(ctx, colonne="Fin", operateur="<", valeur=valeur)[0] == ["Bravo", "Charlie"]


def test_filtre_sur_dates_ecrites_en_toutes_lettres_et_ligne_ecartee_signalee(ctx):
    trouves, r = noms(ctx, colonne="FinTexte", operateur="<", valeur="01/01/2028")

    assert trouves == ["Bravo", "Charlie"]
    assert "1 ligne(s)" in r.resume and "Néant" in r.resume and "écartées" in r.resume


def test_valeur_impossible_a_comparer_refusee_au_lieu_de_zero_ligne(ctx):
    r = executer("excel_selectionner", {"fichier": "suivi.xlsx", "condition": {"colonne": "Fin", "operateur": "<", "valeur": "bientôt"}}, ctx)

    assert r.statut == "erreur" and "ne se comparent pas" in r.resume


def test_chiffres_et_textes_ne_se_comparent_pas_par_ordre_alphabetique():
    gardees, alertes = filtrer({"colonne": "Q", "operateur": "<", "valeur": 5}, ["Q"], [[3], ["N/A"], [10]])

    assert gardees == [[3]] and "N/A" in alertes[0]
    with pytest.raises(ErreurCondition):
        filtrer({"colonne": "Q", "operateur": "<", "valeur": 5}, ["Q"], [["abc"], ["def"]])


def relire(ctx, nom):
    return [list(l) for l in openpyxl.load_workbook(ctx.dossier_sortie / nom).active.iter_rows(values_only=True)]


def test_extraire_copie_par_le_code_avec_tri_et_colonnes(ctx):
    r = executer("excel_extraire", {"fichier": "suivi.xlsx", "condition": {"colonne": "Stock", "operateur": ">=", "valeur": 5},
                                    "colonnes": ["Nom", "Fin"], "tri": [{"colonne": "Fin", "ordre": "decroissant"}], "nom_sortie": "extrait"}, ctx)

    assert r.statut == "ok" and r.donnees["lignes"] == 3
    lignes = relire(ctx, "extrait.xlsx")
    assert lignes[0] == ["Nom", "Fin"] and [l[0] for l in lignes[1:]] == ["Bravo", "Charlie", "Delta"]   # sans date : à la fin
    assert lignes[1][1].date() == datetime.date(2027, 1, 15)                                            # une vraie date


def test_extraire_sans_doublons_et_aucune_ligne_aucun_fichier(ctx):
    r = executer("excel_extraire", {"fichier": "suivi.xlsx", "colonnes": ["Note"], "sans_doublons": True, "nom_sortie": "n"}, ctx)
    assert r.donnees["lignes"] == 1 and "3 doublon(s)" in r.resume

    vide = executer("excel_extraire", {"fichier": "suivi.xlsx", "condition": {"colonne": "Nom", "operateur": "=", "valeur": "Zoé"}, "nom_sortie": "v"}, ctx)
    assert vide.statut == "erreur" and "v.xlsx" not in ctx.fichiers_produits


def test_tableau_word_copie_dans_un_classeur_par_le_code(ctx):
    r = executer("excel_depuis_word", {"fichier": "note.docx", "numero": 1, "nom_sortie": "t"}, ctx)

    assert r.statut == "ok" and relire(ctx, "t.xlsx") == [["Réf", "Qté"], ["A-1", "4"]]
    assert executer("excel_depuis_word", {"fichier": "note.docx", "numero": 9, "nom_sortie": "t"}, ctx).statut == "erreur"


# --- recette rejouée par le code ------------------------------------------------------------------------------------
EXTRAIT = {"outil": "excel_extraire", "arguments": {"fichier": "suivi-octobre.xlsx", "colonnes": ["Nom"], "nom_sortie": "liste"}}


@pytest.mark.parametrize("recette, attendu", [
    ([EXTRAIT], True),
    ([{"outil": "excel_lire", "arguments": {"fichier": "a.xlsx"}}, EXTRAIT], True),
    ([{"outil": "excel_lire", "arguments": {"fichier": "a.xlsx"}}], False),                                 # ne produit rien
    ([{"outil": "excel_creer", "arguments": {"nom": "a", "colonnes": ["x"], "lignes": [[1]]}}], False),     # données du mois dernier
    ([{"outil": "word_regenerer", "arguments": {"modifications": [{"remplacer": "{{D}}", "par": "octobre"}]}}], False),
    ([{"outil": "word_regenerer", "arguments": {"modifications": [{"remplacer": "{{T}}", "valeur_excel": {"fichier": "a.xlsx"}}]}}], True),
    ([], False), (None, False),
])
def test_seules_les_recettes_sans_valeurs_figees_se_rejouent_par_le_code(recette, attendu):
    assert est_rejouable(recette) is attendu


def test_recette_rejouee_sans_modele_avec_le_nouveau_document(ctx):
    evenements = list(rejouer([EXTRAIT], ctx))

    fin = evenements[-1]
    assert fin["statut"] == "termine" and fin["sans_llm"] and "liste.xlsx" in fin["fichiers"]
    assert "« suivi-octobre.xlsx » → « suivi.xlsx »" in fin["message"]
    assert evenements[0]["arguments"]["fichier"] == "suivi.xlsx" and relire(ctx, "liste.xlsx")[1] == ["Bravo"]


def test_etape_en_erreur_arrete_la_recette_et_le_dit(ctx):
    recette = [{"outil": "excel_extraire", "arguments": {"fichier": "suivi.xlsx", "colonnes": ["Absente"], "nom_sortie": "x"}},
               {"outil": "excel_extraire", "arguments": {"fichier": "suivi.xlsx", "nom_sortie": "y"}}]

    evenements = list(rejouer(recette, ctx))

    assert evenements[-1]["statut"] == "arret" and "étape 1" in evenements[-1]["message"] and len(evenements) == 2


def test_une_etape_suivante_utilise_le_fichier_produit_par_la_precedente(ctx):
    recette = [{"outil": "excel_extraire", "arguments": {"fichier": "suivi.xlsx", "nom_sortie": "etape1"}},
               {"outil": "excel_extraire", "arguments": {"fichier": "etape1.xlsx", "colonnes": ["Nom"], "nom_sortie": "etape2"}}]

    fin = list(rejouer(recette, ctx))[-1]

    assert fin["statut"] == "termine" and relire(ctx, "etape2.xlsx")[1] == ["Bravo"]


def test_association_des_fichiers_jamais_de_devinette():
    recette = [{"arguments": {"fichier": "stock-octobre.xlsx"}}]

    assert associer_fichiers(recette, ["stock-octobre.xlsx", "autre.xlsx"]) == ({"stock-octobre.xlsx": "stock-octobre.xlsx"}, None)
    assert associer_fichiers(recette, ["stock-novembre.xlsx", "bilan.xlsx"])[0]["stock-octobre.xlsx"] == "stock-novembre.xlsx"
    assert "Je ne sais pas" in associer_fichiers(recette, ["aaa.xlsx", "bbb.xlsx"])[1]
    assert "Il manque" in associer_fichiers(recette, ["note.docx"])[1]
