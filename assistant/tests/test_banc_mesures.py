from banc.cache import envelopper_embedder
from banc.mesures import contient, evaluer_recherche, normaliser, seuil_propose
from banc.questions import Question


def q(type_="simple", passages=("La visite V1 est réalisée toutes les 25 heures de vol.",), documents=("m",)):
    return Question("Q", type_, "?", [], list(passages), list(documents))


def m(texte, doc=1):
    return {"id": "x", "document_id": doc, "texte": texte, "distance": 0.5}


def test_normaliser_retire_accents_ponctuation_et_barres():
    assert normaliser("Échéance | 25 h, réalisée !") == ["echeance", "25", "h", "realisee"]


def test_normaliser_separe_lettres_et_chiffres_colles():
    assert normaliser("Inspection des pales100 h") == ["inspection", "des", "pales", "100", "h"]


def test_contient_cellule_collee_au_mot_precedent():
    assert contient("Inspection des \npales100 h 10 h Chef BMN", "Inspection des pales 100 h 10 h Chef BMN")


def test_contient_tolere_un_mot_casse_du_pdf():
    passage = "Le technicien réalise l'entretien du circuit après chaque vol de contrôle programmé."
    texte = "Le technicien réalise l'en tretien du circuit après chaque vol de contrôle programmé."
    assert contient(texte, passage)
    assert not contient("Le technicien réalise un vol.", passage)


def test_contient_tableau_aplati_du_pdf():
    assert contient("Inspection des pales 100 h 10 h Chef BMN", "Inspection des pales | 100 h | 10 h | Chef BMN")


def test_evaluer_recherche_rang():
    r = evaluer_recherche(q(), [m("autre"), m("Donc la visite V1 est réalisée toutes les 25 heures de vol.")], {1: "m"})
    assert r == {"trouve": True, "rang": 2}


def test_evaluer_recherche_passages_multiples_tous_requis():
    question = q(type_="coupee", passages=("alpha beta gamma delta", "epsilon zeta eta theta"))
    assert evaluer_recherche(question, [m("alpha beta gamma delta")], {1: "m"})["trouve"] is False
    assert evaluer_recherche(question, [m("alpha beta gamma delta"), m("epsilon zeta eta theta")], {1: "m"})["trouve"] is True


def test_evaluer_recherche_multi_documents_exige_chaque_document():
    question = q(type_="multi_documents", passages=("alpha beta gamma delta", "epsilon zeta eta theta"), documents=("a", "b"))
    passages = [m("alpha beta gamma delta epsilon zeta eta theta", doc=1)]
    assert evaluer_recherche(question, passages, {1: "a", 2: "b"})["trouve"] is False


def test_evaluer_recherche_absente():
    assert evaluer_recherche(q(type_="absente", passages=(), documents=()), [], {}) == {"trouve": True, "rang": None}
    assert evaluer_recherche(q(type_="absente", passages=(), documents=()), [m("x")], {1: "m"})["trouve"] is False


def test_seuil_propose_separe_les_deux_groupes():
    assert 0.7 < seuil_propose([0.5, 0.6, 0.7], [0.9, 1.0]) < 0.9
    assert seuil_propose([], [0.9]) is None


def test_cache_n_appelle_le_modele_qu_une_fois(tmp_path):
    appels = []

    def faux(textes, r):
        appels.append(list(textes))
        return [[float(len(t))] for t in textes]

    emb = envelopper_embedder(faux, tmp_path, "bge-m3")
    assert emb(["a", "bb"], None) == [[1.0], [2.0]]
    assert emb(["bb", "ccc"], None) == [[2.0], [3.0]]
    assert appels == [["a", "bb"], ["ccc"]]
    assert envelopper_embedder(faux, tmp_path, "bge-m3")(["a"], None) == [[1.0]]   # relu depuis le disque
    assert len(appels) == 2


def test_cache_ignore_une_ligne_illisible(tmp_path):
    appels = []
    def emb(textes, r):
        appels.append(textes)
        return [[1.0] for _ in textes]
    e1 = envelopper_embedder(emb, tmp_path, "m")
    e1(["a"], None)
    fichier = next(tmp_path.glob("vecteurs-*.jsonl"))
    with fichier.open("a", encoding="utf-8") as f:
        f.write('{"cle": "tronq\n')
    e2 = envelopper_embedder(emb, tmp_path, "m")
    assert e2(["a"], None) == [[1.0]]
    assert len(appels) == 1
