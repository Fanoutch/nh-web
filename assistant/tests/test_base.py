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


def test_supprimer_piece_jointe_ne_touche_pas_les_autres_pieces_de_la_conversation(base):
    base.ajouter_piece_jointe(7, 10, "cr1.pdf", [{"texte": "depose moteur", "page": 1}], [MAINT])
    base.ajouter_piece_jointe(7, 11, "cr2.pdf", [{"texte": "remplacement pneu", "page": 1}], [COMPOST])

    base.supprimer_piece_jointe(7, 10)

    restants = base.chercher_conversation(7, COMPOST, top_k=5)
    assert [m["nom"] for m in restants] == ["cr2.pdf"]
    assert all(not m["id"].startswith("pj10_") for m in restants)


def test_supprimer_piece_jointe_collection_absente_sans_erreur(base):
    base.supprimer_piece_jointe(99, 1)                                  # conversation jamais indexée


def test_secteur_et_conversation_de_meme_nom_ne_se_melangent_pas(base):
    # Un secteur nommé "conv_7" ne doit pas partager d'entrée de cache avec la conversation 7 :
    # les noms réels de collection ChromaDB ("secteur_conv_7" vs "conv_7") sont distincts.
    base.ajouter_document("conv_7", 1, "secteur.pdf", [{"texte": "vol de controle", "page": 1}], [MAINT])
    base.ajouter_piece_jointe(7, 3, "conversation.pdf", [{"texte": "depose moteur", "page": 1}], [COMPOST])

    trouves_secteur = base.chercher("conv_7", MAINT, top_k=5)
    trouves_conversation = base.chercher_conversation(7, COMPOST, top_k=5)

    assert [m["nom"] for m in trouves_secteur] == ["secteur.pdf"]
    assert [m["nom"] for m in trouves_conversation] == ["conversation.pdf"]


def _indexer_cinq(base):
    morceaux = [{"texte": f"morceau {i}", "page": i} for i in range(5)]
    base.ajouter_document("bmn", 7, "Manuel.pdf", morceaux, [[1.0, float(i), 0.0] for i in range(5)])


def test_voisins_avant_et_apres_dans_l_ordre(base):
    _indexer_cinq(base)
    avant, apres = base.voisins("bmn", "doc7_m2", 1)

    assert [m["id"] for m in avant] == ["doc7_m1"]
    assert [m["id"] for m in apres] == ["doc7_m3"]
    assert avant[0]["texte"] == "morceau 1" and avant[0]["distance"] is None


def test_voisins_aux_extremites_sans_erreur(base):
    _indexer_cinq(base)
    assert base.voisins("bmn", "doc7_m0", 2)[0] == []
    assert [m["id"] for m in base.voisins("bmn", "doc7_m4", 2)[1]] == []
    assert [m["id"] for m in base.voisins("bmn", "doc7_m4", 2)[0]] == ["doc7_m2", "doc7_m3"]


def test_voisins_zero_ou_identifiant_inconnu(base):
    _indexer_cinq(base)
    assert base.voisins("bmn", "doc7_m2", 0) == ([], [])
    assert base.voisins("bmn", "n_importe_quoi", 1) == ([], [])
    assert base.voisins("autre_secteur", "doc7_m2", 1) == ([], [])


def test_voisins_conversation(base):
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [{"texte": f"pj {i}", "page": None} for i in range(3)],
                              [[0.0, 1.0, float(i)] for i in range(3)])
    avant, apres = base.voisins_conversation(3, "pj5_m1", 1)
    assert [m["id"] for m in avant + apres] == ["pj5_m0", "pj5_m2"]
    assert apres[0]["pj"] is True
    assert base.voisins_conversation(99, "pj5_m1", 1) == ([], [])


def test_get_chroma_ignore_les_identifiants_absents(base):
    """Vérifie l'hypothèse de `_lire_ids` : `get` ne renvoie que les ids existants."""
    _indexer_cinq(base)
    trouves = base._collection("bmn").get(ids=["doc7_m4", "doc7_m9"], include=["documents", "metadatas"])
    assert trouves["ids"] == ["doc7_m4"]
