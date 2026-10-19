from pathlib import Path

import pytest

from outils.registre import Contexte, Reponse, declaration_outils, erreur, executer, ok, outil, REGISTRE


@pytest.fixture
def ctx(tmp_path):
    return Contexte(dossier_execution=tmp_path, fichiers_autorises=[], outils_autorises={"outil_test"},
                    dossier_sortie=tmp_path / "sortie")


@pytest.fixture(autouse=True)
def outil_test():
    @outil("outil_test", "Outil d'essai.", {"type": "object", "properties": {"x": {"type": "integer"}},
                                              "required": ["x"]})
    def _outil_test(ctx, x):
        return ok(f"x vaut {x}", {"x": x}, suite="Rien d'autre à faire.")
    yield
    REGISTRE.pop("outil_test", None)


def test_reponse_a_la_forme_uniforme():
    r = ok("fait", {"a": 1}, suite="ensuite", artefacts=[{"nom": "a.xlsx"}])
    assert r.en_dict() == {"statut": "ok", "resume": "fait", "donnees": {"a": 1}, "suite": "ensuite",
                           "artefacts": [{"nom": "a.xlsx"}]}


def test_erreur_dit_la_cause_et_comment_reessayer():
    r = erreur("La colonne « Prix » n'existe pas.", "Utilisez excel_decrire pour voir les colonnes.")
    assert r.statut == "erreur"
    assert "Prix" in r.resume and "excel_decrire" in r.suite


def test_executer_un_outil_autorise(ctx):
    r = executer("outil_test", {"x": 3}, ctx)
    assert r.statut == "ok" and r.donnees == {"x": 3}


def test_outil_inconnu_refuse_en_francais(ctx):
    r = executer("n_importe_quoi", {}, ctx)
    assert r.statut == "erreur" and "n'existe pas" in r.resume


def test_outil_hors_de_la_liste_de_l_agent_refuse(ctx):
    ctx.outils_autorises = {"autre"}
    r = executer("outil_test", {"x": 1}, ctx)
    assert r.statut == "erreur" and "pas autorisé" in r.resume


def test_arguments_manquants_ou_inconnus_donnent_une_erreur_lisible(ctx):
    assert executer("outil_test", {}, ctx).statut == "erreur"
    r = executer("outil_test", {"x": 1, "y": 2}, ctx)
    assert r.statut == "erreur" and "y" in r.resume


def test_une_exception_dans_un_outil_ne_sort_jamais(ctx):
    @outil("outil_test", "Plante.", {"type": "object", "properties": {}})
    def _plante(ctx):
        raise RuntimeError("boum")
    r = executer("outil_test", {}, ctx)
    assert r.statut == "erreur" and "boum" not in r.resume


def test_declaration_au_format_fonctions(ctx):
    decl = declaration_outils({"outil_test"})
    assert decl == [{"type": "function", "function": {
        "name": "outil_test", "description": "Outil d'essai.",
        "parameters": {"type": "object", "properties": {"x": {"type": "integer"}}, "required": ["x"]}}}]
