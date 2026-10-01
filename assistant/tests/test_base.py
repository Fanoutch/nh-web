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


def _deux_documents(base):
    base.ajouter_document("bmn", 1, "A.pdf", [{"texte": "vol de maintenance ordonné", "page": 1},
                                             {"texte": "code HYD-112 fuite accumulateur", "page": 2}],
                          [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]])
    base.ajouter_document("bmn", 2, "B.pdf", [{"texte": "graissage rotor de queue 30 jours", "page": 1}],
                          [[0.0, 0.0, 1.0]])


def test_mots_cles_index_construit_depuis_la_collection_existante(tmp_path):
    base = BaseVectorielle(tmp_path / "chroma")
    _deux_documents(base)
    relue = BaseVectorielle(tmp_path / "chroma")          # nouvelle instance : index vide, reconstruit
    r = relue.chercher_mots_cles("bmn", "code HYD-112 ?", 2)
    assert r[0]["id"] == "doc1_m1"
    assert r[0]["distance"] is None and r[0]["score_mots_cles"] > 0
    assert set(r[0]) >= {"id", "document_id", "nom", "page", "texte", "distance", "pj"}
    assert r[0]["document_id"] == 1 and r[0]["page"] == 2 and r[0]["pj"] is False


def test_mots_cles_suit_ajout_suppression_et_reindexation(base):
    _deux_documents(base)
    assert base.chercher_mots_cles("bmn", "graissage", 5)[0]["id"] == "doc2_m0"   # index construit
    base.supprimer_document("bmn", 2)
    assert base.chercher_mots_cles("bmn", "graissage", 5) == []
    base.ajouter_document("bmn", 1, "A.pdf", [{"texte": "nouveau texte sans code", "page": 1}], [[1.0, 0.0, 0.0]])
    assert base.chercher_mots_cles("bmn", "HYD-112", 5) == []                    # pas de morceau fantôme
    assert [m["id"] for m in base.chercher_mots_cles("bmn", "nouveau", 5)] == ["doc1_m0"]


def test_mots_cles_reindexation_sans_morceau_ne_laisse_pas_de_fantome(base):
    _deux_documents(base)
    assert base.chercher_mots_cles("bmn", "HYD-112", 5)                          # index construit
    base.ajouter_document("bmn", 1, "A.pdf", [], [])
    assert base.chercher_mots_cles("bmn", "HYD-112", 5) == []


def test_mots_cles_secteur_vide_et_conversation_absente(base):
    assert base.chercher_mots_cles("vide", "HYD-112", 5) == []
    assert base.chercher_mots_cles_conversation(99, "HYD-112", 5) == []
    assert base.mots_rares("vide", "HYD-112") == set()


def test_mots_cles_conversation(base):
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [{"texte": "référence PC-HYD-07", "page": None},
                                              {"texte": "autre chose", "page": None}],
                              [[0.0, 1.0, 0.0], [0.0, 0.0, 1.0]])
    r = base.chercher_mots_cles_conversation(3, "plaque PC-HYD-07", 5)
    assert r[0]["id"] == "pj5_m0" and r[0]["pj"] is True and r[0]["document_id"] is None
    base.supprimer_piece_jointe(3, 5)
    assert base.chercher_mots_cles_conversation(3, "PC-HYD-07", 5) == []


def test_mots_rares_du_secteur(base):
    morceaux = [{"texte": "vol de maintenance", "page": 1} for _ in range(19)] + [{"texte": "code HYD-112", "page": 2}]
    base.ajouter_document("bmn", 1, "A.pdf", morceaux, [[1.0, float(i), 0.0] for i in range(20)])
    assert "hyd112" in base.mots_rares("bmn", "vol HYD-112")
    assert "vol" not in base.mots_rares("bmn", "vol HYD-112")


def test_mots_cles_lectures_pendant_reindexation(base):
    # Index garni directement (sans ChromaDB) de milliers de morceaux : les itérations sont assez longues
    # pour que, sans verrou en lecture, « dictionary changed size during iteration » se produise.
    index = base._index_de("secteur_bmn", base._collection("bmn"))
    index.ajouter([f"doc2_m{i}" for i in range(1500)], [f"vol maintenance mot{i} code HYD-{i}" for i in range(1500)])
    ids = [f"doc1_m{i}" for i in range(1500)]
    textes = [f"graissage rotor mot{i} code HYD-{i}" for i in range(1500)]
    exceptions: list = []
    fin = threading.Event()

    def lecteur():
        try:
            while not fin.is_set():
                base.chercher_mots_cles("bmn", "code HYD-112 graissage", 5)
                base.mots_rares("bmn", "code HYD-112")
        except Exception as e:
            exceptions.append(e)

    def redacteur():
        try:
            for _ in range(5):
                with base._verrou:   # comme les vraies écritures
                    base._maj_index("secteur_bmn", "doc1_m", ids, textes)
        except Exception as e:
            exceptions.append(e)
        finally:
            fin.set()

    fils = [threading.Thread(target=lecteur) for _ in range(3)] + [threading.Thread(target=redacteur)]
    for fil in fils:
        fil.start()
    for fil in fils:
        fil.join()
    assert exceptions == []


def test_mots_cles_supprimer_conversation_vide_l_index(base):
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [{"texte": "référence PC-HYD-07", "page": None}], [[0.0, 1.0, 0.0]])
    assert base.chercher_mots_cles_conversation(3, "PC-HYD-07", 5)
    base.supprimer_conversation(3)
    assert base.chercher_mots_cles_conversation(3, "PC-HYD-07", 5) == []


def test_mots_cles_piece_jointe_reindexee_avec_moins_de_morceaux(base):
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [{"texte": "alpha", "page": None}, {"texte": "bravo", "page": None}],
                              [[0.0, 1.0, 0.0], [0.0, 0.0, 1.0]])
    assert base.chercher_mots_cles_conversation(3, "bravo", 5)
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [{"texte": "alpha", "page": None}], [[0.0, 1.0, 0.0]])
    assert base.chercher_mots_cles_conversation(3, "bravo", 5) == []
    base.ajouter_piece_jointe(3, 5, "pj.pdf", [], [])
    assert base.chercher_mots_cles_conversation(3, "alpha", 5) == []


def test_mots_rares_conversation(base):
    textes = [{"texte": "vol de maintenance", "page": 1} for _ in range(9)] + [{"texte": "plaque ZX-900", "page": 2}]
    base.ajouter_piece_jointe(3, 5, "P.pdf", textes, [[1.0, float(i), 0.0] for i in range(10)])
    rares = base.mots_rares_conversation(3, "vol ZX-900")
    assert "zx900" in rares and "vol" not in rares
    assert base.mots_rares_conversation(99, "vol ZX-900") == set()


def test_codes_connus_secteur_et_conversation(base):
    _deux_documents(base)
    assert base.codes_connus("bmn", "Que faire pour HYD-112 et HYD-113 ?") == {"hyd112"}
    assert base.codes_connus("bmn", "graissage ?") == set()
    assert base.codes_connus_conversation(7, "HYD-112 ?") == set()            # pas de pièce jointe
    base.ajouter_piece_jointe(7, 1, "P.pdf", [{"texte": "plaque ZX-900", "page": 1}], [[1.0, 0.0, 0.0]])
    assert base.codes_connus_conversation(7, "ZX-900 et HYD-112 ?") == {"zx900"}
