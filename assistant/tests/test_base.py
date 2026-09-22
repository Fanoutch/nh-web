import threading

import pytest

from rag.base import BaseVectorielle

MAINT = [1.0, 0.0, 0.0]
COMPOST = [0.0, 1.0, 0.0]


@pytest.fixture
def base(tmp_path):
    return BaseVectorielle(tmp_path / "chroma")


def test_ajouter_et_chercher(base):
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "vol de maintenance", "page": 3}], [MAINT])
    base.ajouter_document("bmn", 2, "Jardin.docx", [{"texte": "compost", "page": None}], [COMPOST])

    resultats = base.chercher("bmn", MAINT, top_k=6)

    assert resultats[0] == {"document_id": 1, "nom": "Manuel.pdf", "page": 3,
                            "texte": "vol de maintenance", "distance": pytest.approx(0.0, abs=1e-6),
                            "id": "doc1_m0", "pj": False}
    assert resultats[1]["document_id"] == 2
    assert resultats[1]["page"] is None


def test_secteurs_isoles(base):
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "vol", "page": 1}], [MAINT])

    assert base.chercher("autre", MAINT, top_k=6) == []


def test_reindexer_remplace_les_morceaux(base):
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "a", "page": 1}, {"texte": "b", "page": 2}], [MAINT, MAINT])
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "c", "page": 1}], [MAINT])

    assert base.nb_morceaux("bmn", 1) == 1


def test_supprimer_document(base):
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "a", "page": 1}], [MAINT])
    base.ajouter_document("bmn", 2, "Autre.pdf", [{"texte": "b", "page": 1}], [COMPOST])

    base.supprimer_document("bmn", 1)

    assert base.nb_morceaux("bmn", 1) == 0
    assert [r["document_id"] for r in base.chercher("bmn", MAINT, 6)] == [2]


def test_supprimer_document_absent_sans_erreur(base):
    base.supprimer_document("bmn", 99)


def test_nombre_de_vecteurs_incoherent(base):
    with pytest.raises(ValueError):
        base.ajouter_document("bmn", 1, "x.pdf", [{"texte": "a", "page": 1}], [])


def test_ecritures_concurrentes(base):
    def indexer(i):
        base.ajouter_document("bmn", i, f"doc{i}.pdf", [{"texte": f"t{i}", "page": 1}], [MAINT])

    fils = [threading.Thread(target=indexer, args=(i,)) for i in range(1, 9)]
    for fil in fils:
        fil.start()
    for fil in fils:
        fil.join()

    assert len(base.chercher("bmn", MAINT, 20)) == 8


def test_premier_acces_concurrent_a_un_nouveau_secteur(tmp_path):
    base = BaseVectorielle(tmp_path / "chroma")
    exceptions: list = []

    def lecteur():
        try:
            base.chercher("neuf", MAINT, 6)
        except Exception as e:
            exceptions.append(e)

    def redacteur(i):
        try:
            base.ajouter_document("neuf", i, f"d{i}.pdf", [{"texte": "t", "page": 1}], [MAINT])
        except Exception as e:
            exceptions.append(e)

    fils = []
    for i in range(1, 5):
        fils.append(threading.Thread(target=redacteur, args=(i,)))
    for _ in range(4):
        fils.append(threading.Thread(target=lecteur))

    for fil in fils:
        fil.start()
    for fil in fils:
        fil.join()

    assert exceptions == []
    assert len(base.chercher("neuf", MAINT, 20)) == 4


def test_telemetrie_chromadb_desactivee(base):
    # Réseau fermé : ChromaDB ne doit rien envoyer à l'extérieur.
    assert base._client.get_settings().anonymized_telemetry is False


def test_aucune_fonction_d_embedding_chromadb(base):
    # Les vecteurs viennent toujours de nos modèles : ChromaDB ne doit pas charger son modèle par défaut.
    assert base._collection("bmn")._embedding_function is None


def test_chercher_avec_top_k_nul_renvoie_au_moins_un_resultat(base):
    base.ajouter_document("bmn", 1, "Manuel.pdf", [{"texte": "vol", "page": 1}], [MAINT])

    assert len(base.chercher("bmn", MAINT, top_k=0)) == 1


def test_chercher_filtre_sur_les_documents_demandes(base):
    base.ajouter_document("bmn", 1, "a.pdf", [{"texte": "vol de controle", "page": 1}], [[1.0, 0.0]])
    base.ajouter_document("bmn", 2, "b.pdf", [{"texte": "vol de controle", "page": 1}], [[1.0, 0.0]])

    trouves = base.chercher("bmn", [1.0, 0.0], top_k=5, document_ids=[2])

    assert [m["document_id"] for m in trouves] == [2]
    assert trouves[0]["id"]                      # identifiant de morceau présent


def test_chercher_avec_document_ids_vide_ne_filtre_pas(base):
    base.ajouter_document("bmn", 1, "a.pdf", [{"texte": "vol de controle", "page": 1}], [[1.0, 0.0]])
    base.ajouter_document("bmn", 2, "b.pdf", [{"texte": "vol de controle", "page": 1}], [[1.0, 0.0]])

    trouves = base.chercher("bmn", [1.0, 0.0], top_k=5, document_ids=[])

    assert {m["document_id"] for m in trouves} == {1, 2}


def test_piece_jointe_dans_une_collection_de_conversation(base):
    base.ajouter_piece_jointe(7, 3, "cr.pdf", [{"texte": "depose moteur", "page": 1}], [[0.0, 1.0]])

    trouves = base.chercher_conversation(7, [0.0, 1.0], top_k=3)

    assert trouves[0]["nom"] == "cr.pdf" and trouves[0]["pj"] is True
    assert base.chercher_conversation(99, [0.0, 1.0], top_k=3) == []   # collection absente

    base.supprimer_conversation(7)
    assert base.chercher_conversation(7, [0.0, 1.0], top_k=3) == []
    base.supprimer_conversation(7)                                      # deux fois : pas d'erreur
