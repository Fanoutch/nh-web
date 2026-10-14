from collections import Counter

from rag.motscles import IndexMotsCles, codes, mots


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
    assert idx.rares("vol HYD-112") == {"hyd112"}          # fragments d'un code de la question exclus
    assert idx.rares("vol") == set()
    assert IndexMotsCles().rares("vol") == set()


def test_code_voisin_n_est_pas_rare():
    """« HYD-113 » ne doit pas retrouver « HYD-112 » par ses fragments « hyd » / « 113 »."""
    idx = IndexMotsCles()
    idx.ajouter([f"d_m{i}" for i in range(20)], ["vol de maintenance"] * 19 + ["Code HYD-112"])
    assert idx.rares("HYD-113 ?") == set()
    assert idx.rares("hyd ?") == {"hyd"}                    # mot isolé (hors code) : reste rare


def test_codes_formes_collees():
    assert codes("Codes HYD-112, pc-hyd-08 et 3.2.1 ; pales100") == {"hyd112", "pchyd08", "321"}
    assert codes("rien ici") == set()


def test_connait():
    idx = IndexMotsCles()
    idx.ajouter(["a"], ["Code HYD-112"])
    assert idx.connait("hyd112") and not idx.connait("hyd113")


def test_apostrophes_courbes_vs_droites():
    """Apostrophes courbes (U+2019, U+2018, U+00B4, U+02BC) traitées comme la droite."""
    assert mots("l\u2019\u00e9ch\u00e9ance") == mots("l'\u00e9ch\u00e9ance") == ["echeance"]
    assert mots("l\u2019huile du filtre") == ["huile", "filtre"]
    for apostrophe in ("\u2018", "\u00b4", "\u02bc"):
        assert mots(f"l{apostrophe}huile") == ["huile"]


def test_retirer_ne_parcourt_pas_tout_le_vocabulaire():
    class Espion(Counter):
        def items(self):
            raise AssertionError("parcours de tout le vocabulaire")

    idx = IndexMotsCles()
    idx.ajouter(["a", "b"], ["frein avant", "frein arriere"])
    idx._df = Espion(idx._df)
    idx.retirer("a")
    assert dict.__getitem__(idx._df, "frein") == 1
    assert "avant" not in idx._df and dict.__getitem__(idx._df, "arriere") == 1


def test_ligatures():
    """Ligatures œ/æ doivent être converties en oe/ae."""
    assert mots("Œil de bœuf") == ["oeil", "boeuf"]
