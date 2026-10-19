import pytest

from outils import condition, formule

ENT = ["Matériel", "Quantité", "Besoin"]
LIG = [["Joint torique", 3, 10], ["Vis M6", 12, 10], ["Rondelle", None, 4], ["Écrou", "7,5", "10"]]


def filtrer(cond):
    f = condition.compiler(cond, ENT)
    return [l[0] for l in LIG if f(l)]


@pytest.mark.parametrize("cond,attendu", [
    ({"colonne": "Quantité", "operateur": "<", "valeur": 5}, ["Joint torique"]),
    ({"colonne": "Quantité", "operateur": ">=", "valeur": 12}, ["Vis M6"]),
    ({"colonne": "Quantité", "operateur": "=", "valeur": 3}, ["Joint torique"]),
    ({"colonne": "Quantité", "operateur": "!=", "valeur": 3}, ["Vis M6", "Écrou"]),
    ({"colonne": "Quantité", "operateur": "<", "valeur": 8}, ["Joint torique", "Écrou"]),   # « 7,5 » est un nombre
    ({"colonne": "Matériel", "operateur": "contient", "valeur": "ECROU"}, ["Écrou"]),       # casse + accents
    ({"colonne": "Quantité", "operateur": "vide"}, ["Rondelle"]),
    ({"colonne": "Quantité", "operateur": "non_vide"}, ["Joint torique", "Vis M6", "Écrou"]),
    ({"logique": "ou", "clauses": [{"colonne": "Quantité", "operateur": "<", "valeur": 5},
                                   {"colonne": "Matériel", "operateur": "contient", "valeur": "vis"}]},
     ["Joint torique", "Vis M6"]),
    ([{"colonne": "Besoin", "operateur": ">=", "valeur": 10}, {"colonne": "Quantité", "operateur": "<", "valeur": 8}],
     ["Joint torique", "Écrou"]),
    (None, ["Joint torique", "Vis M6", "Rondelle", "Écrou"]),
    ({}, ["Joint torique", "Vis M6", "Rondelle", "Écrou"]),
])
def test_operateurs(cond, attendu):
    assert filtrer(cond) == attendu


def test_colonne_reconnue_sans_casse_ni_accent():
    assert filtrer({"colonne": "quantite", "operateur": "<", "valeur": 5}) == ["Joint torique"]


@pytest.mark.parametrize("cond,mot", [
    ({"colonne": "Prix", "operateur": "<", "valeur": 1}, "Prix"),
    ({"colonne": "Quantité", "operateur": "presque", "valeur": 1}, "presque"),
    ({"colonne": "Quantité", "operateur": "<"}, "valeur"),
    ({"operateur": "<"}, "colonne"),
    ({"logique": "xor", "clauses": []}, "xor"),
])
def test_condition_invalide_message_lisible(cond, mot):
    with pytest.raises(condition.ErreurCondition) as exc:
        condition.compiler(cond, ENT)
    assert mot in str(exc.value)


def calc(f, ligne):
    return formule.compiler(f, ENT)(ligne)


def test_formules_nominales():
    assert calc("[Besoin] - [Quantité]", ["x", 3, 10]) == 7
    assert calc("[Besoin] - [Quantité] * 2", ["x", 3, 10]) == 4         # priorité des opérateurs
    assert calc("([Besoin] - [Quantité]) * 2", ["x", 3, 10]) == 14
    assert calc("max([Besoin] - [Quantité], 0)", ["x", 12, 10]) == 0
    assert calc("-[Quantité] + 1", ["x", 3, 10]) == -2
    assert calc("[Besoin] - [Quantité]", ["x", "7,5", "10"]) == 2.5      # texte numérique accepté
    assert calc("[besoin]/4", ["x", 3, 10]) == 2.5                       # colonne reconnue sans casse


@pytest.mark.parametrize("f", [
    "__import__('os').system('ls')", "[Quantité].__class__", "open('x')", "[Quantité][0]",
    "lambda: 1", "'a' + 'b'", "[Quantité] ** 2", "[Quantité] % 2", "eval('1')", "True + 1", "x",
    "max(key=len)",
])
def test_formules_dangereuses_refusees(f):
    with pytest.raises(formule.ErreurFormule):
        calc(f, ["x", 1, 1])


def test_formule_vide_mal_ecrite_ou_colonne_inconnue():
    for f in ["", "   ", "[Besoin] -", "[Prix] * 2"]:
        with pytest.raises(formule.ErreurFormule):
            formule.compiler(f, ENT)


def test_erreurs_de_ligne():
    with pytest.raises(formule.ErreurCalcul):
        calc("[Besoin] / [Quantité]", ["x", 0, 10])
    with pytest.raises(formule.ErreurCalcul):
        calc("[Besoin] - [Quantité]", ["x", "beaucoup", 10])
    with pytest.raises(formule.ErreurCalcul):
        calc("[Besoin] - [Quantité]", ["x", None, 10])
