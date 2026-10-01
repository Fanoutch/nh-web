from rag.motscles import IndexMotsCles, mots


def test_mots_codes_accents_et_mots_vides():
    assert mots("Le code HYD-112 de l'Échéance") == ["hyd112", "code", "hyd", "112", "echeance"]


def test_mots_lettres_et_chiffres_colles():
    assert mots("Inspection des pales100 h") == ["inspection", "pales", "100", "h"]


def test_mots_texte_vide_ou_ponctuation():
    assert mots("") == []
    assert mots(" ? ; de la ") == []


def _index():
    idx = IndexMotsCles()
    idx.ajouter(
        ["doc1_m0", "doc1_m1", "doc2_m0", "doc2_m1"],
        ["Le vol de contrôle est ordonné par le chef.",
         "Code HYD-112 : fuite de l'accumulateur.",
         "La visite V2 a lieu toutes les 50 heures de vol.",
         "Le graissage du rotor de queue tous les 30 jours."],
    )
    return idx


def test_chercher_le_code_passe_en_tete():
    resultats = _index().chercher("À quoi correspond le code HYD-112 ?", 3)
    assert resultats[0][0] == "doc1_m1"
    assert all(score > 0 for _, score in resultats)


def test_chercher_aucun_mot_commun_ou_index_vide():
    assert _index().chercher("dégivrage", 5) == []
    assert IndexMotsCles().chercher("vol", 5) == []
    assert _index().chercher("vol", 0) == []


def test_retirer_par_prefixe_sans_toucher_doc10():
    idx = _index()
    idx.ajouter(["doc10_m0"], ["Graissage hebdomadaire du doc dix."])
    idx.retirer("doc1_m")
    ids = {i for i, _ in idx.chercher("graissage vol code", 10)}
    assert "doc1_m0" not in ids and "doc1_m1" not in ids
    assert "doc10_m0" in ids
    assert len(idx) == 3


def test_ajouter_remplace_un_id_existant():
    idx = _index()
    idx.ajouter(["doc1_m1"], ["Texte remplacé sans code."])
    assert idx.chercher("HYD-112", 5) == []
    assert len(idx) == 4


def test_resultats_deterministes_a_egalite():
    idx = IndexMotsCles()
    idx.ajouter(["b", "a", "c"], ["frein", "frein", "autre"])
    assert [i for i, _ in idx.chercher("frein", 5)] == ["a", "b"]


def test_rares():
    idx = IndexMotsCles()
    idx.ajouter([f"d_m{i}" for i in range(20)], ["vol de maintenance"] * 19 + ["code HYD-112 vol"])
    assert idx.rares("vol HYD-112") == {"hyd112", "hyd", "112"}
    assert idx.rares("vol") == set()
    assert IndexMotsCles().rares("vol") == set()


def test_apostrophes_courbes_vs_droites():
    """Apostrophes courbes et droites doivent être traitées identiquement."""
    assert mots("l'échéance") == mots("l'échéance") == ["echeance"]


def test_ligatures():
    """Ligatures œ/æ doivent être converties en oe/ae."""
    assert mots("Œil de bœuf") == ["oeil", "boeuf"]
