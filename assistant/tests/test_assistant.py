import pytest

from rag import assistant as module
from rag import modeles
from rag.assistant import Assistant, _page_a, _pages_bout_a_bout
from rag.base import BaseVectorielle
from rag.lecteurs import LectureImpossible
from rag.reglages import Reglages
from tests.fabrique import pdf_texte, pdf_vide


def faux_vecteur(texte: str) -> list[float]:
    texte = texte.lower()
    if "maintenance" in texte or "vol" in texte:
        return [1.0, 0.0, 0.0]
    if "compost" in texte:
        return [0.0, 1.0, 0.0]
    return [0.0, 0.0, 1.0]


@pytest.fixture
def journal(monkeypatch):
    j = {"discuter": [], "flux": [], "reponse_flux": ["Le vol ", "est ordonné par le chef."]}
    monkeypatch.setattr(modeles, "embedder", lambda textes, r: [faux_vecteur(t) for t in textes])

    def faux_discuter(messages, r, temperature):
        j["discuter"].append({"messages": messages, "temperature": temperature})
        return "Qui ordonne le vol de maintenance de controle ?"

    def faux_flux(messages, r, temperature):
        j["flux"].append({"messages": messages, "temperature": temperature})
        yield from j["reponse_flux"]

    monkeypatch.setattr(modeles, "discuter", faux_discuter)
    monkeypatch.setattr(modeles, "discuter_en_flux", faux_flux)
    return j


@pytest.fixture
def assistant(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=6, seuil_distance=1.0, temperature=0.3, historique_tours=3)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    a.indexer("bmn", 1, str(pdf_texte(tmp_path / "manuel.pdf", ["Le vol de maintenance est ordonne par le chef BMN."])), "Manuel.pdf")
    return a


def test_indexer_retourne_le_nombre_de_morceaux(assistant, tmp_path):
    n = assistant.indexer("bmn", 2, str(pdf_texte(tmp_path / "b.pdf", ["Le compost se retourne chaque semaine."])), "Jardin.pdf")

    assert n == 1
    assert assistant.base.nb_morceaux("bmn", 2) == 1


def test_indexer_pdf_scanne(assistant, tmp_path):
    with pytest.raises(LectureImpossible, match="Aucun texte trouvé"):
        assistant.indexer("bmn", 3, str(pdf_vide(tmp_path / "scan.pdf")), "Scan.pdf")


def test_repondre_sources_puis_texte_en_flux(assistant, journal):
    evenements = list(assistant.repondre("bmn", "BMN", "Qui lance un vol de maintenance ?", []))

    assert evenements == [
        {"type": "sources", "sources": [{"document_id": 1, "nom": "Manuel.pdf", "page": 1,
                                        "extrait": "Le vol de maintenance est ordonne par le chef BMN.",
                                        "pj": False}]},
        {"type": "texte", "texte": "Le vol "},
        {"type": "texte", "texte": "est ordonné par le chef."},
        {"type": "fin"},
    ]
    messages = journal["flux"][0]["messages"]
    assert messages[0]["role"] == "system"
    assert "secteur BMN" in messages[0]["content"]
    assert "vol de maintenance est ordonne" in messages[-1]["content"]
    assert journal["flux"][0]["temperature"] == 0.3
    assert journal["discuter"] == []  # pas de reformulation sans historique


def test_rien_de_pertinent_reponse_fixe_sans_llm(assistant, journal):
    evenements = list(assistant.repondre("bmn", "BMN", "Comment faire un compost ?", []))

    assert evenements == [
        {"type": "sources", "sources": []},
        {"type": "texte", "texte": "Je ne trouve pas cette information dans les documents du secteur BMN."},
        {"type": "fin"},
    ]
    assert journal["flux"] == []


def test_question_de_suite_reformulee_pour_la_recherche(assistant, journal):
    historique = [
        {"role": "user", "content": "Qui lance un vol de maintenance ?"},
        {"role": "assistant", "content": "Le chef BMN."},
    ]

    evenements = list(assistant.repondre("bmn", "BMN", "et pour un vol de contrôle ?", historique))

    assert evenements[0]["sources"] == [{"document_id": 1, "nom": "Manuel.pdf", "page": 1,
                                         "extrait": "Le vol de maintenance est ordonne par le chef BMN.",
                                         "pj": False}]
    assert journal["discuter"][0]["temperature"] == 0
    assert "et pour un vol de contrôle ?" in journal["discuter"][0]["messages"][0]["content"]
    messages = journal["flux"][0]["messages"]
    assert messages[1:3] == historique
    assert "et pour un vol de contrôle ?" in messages[-1]["content"]


def test_historique_limite_aux_derniers_tours(assistant, journal):
    historique = [{"role": "user" if i % 2 == 0 else "assistant", "content": f"m{i}"} for i in range(10)]

    list(assistant.repondre("bmn", "BMN", "maintenance ?", historique))

    envoyes = journal["flux"][0]["messages"][1:-1]
    assert [m["content"] for m in envoyes] == [f"m{i}" for i in range(4, 10)]


def test_llm_desactive(tmp_path, journal):
    a = Assistant(Reglages(llm_actif=False), BaseVectorielle(tmp_path / "c"))

    evenements = list(a.repondre("bmn", "BMN", "?", []))

    assert evenements[1] == {"type": "texte", "texte": module.REPONSE_DESACTIVE}
    assert journal["flux"] == []


def test_modele_indisponible_donne_un_evenement_erreur(assistant, journal, monkeypatch):
    def en_panne(messages, r, temperature):
        yield "Le vol "
        raise modeles.ModeleIndisponible("délai dépassé")

    monkeypatch.setattr(modeles, "discuter_en_flux", en_panne)

    evenements = list(assistant.repondre("bmn", "BMN", "maintenance ?", []))

    assert evenements[1] == {"type": "texte", "texte": "Le vol "}
    assert evenements[-1] == {"type": "erreur",
                              "message": "L'assistant est indisponible pour le moment, réessayez plus tard.",
                              "detail": "délai dépassé"}


def test_supprimer(assistant):
    assistant.supprimer("bmn", 1)

    assert assistant.base.nb_morceaux("bmn", 1) == 0


def test_supprimer_piece_jointe_ne_touche_pas_les_autres_pieces_de_la_conversation(assistant, tmp_path):
    assistant.indexer_piece_jointe(
        7, 10, str(pdf_texte(tmp_path / "cr1.pdf", ["Le vol de maintenance."])), "CR1.pdf",
    )
    assistant.indexer_piece_jointe(
        7, 11, str(pdf_texte(tmp_path / "cr2.pdf", ["Le compost se retourne."])), "CR2.pdf",
    )

    assistant.supprimer_piece_jointe(7, 10)

    restants = assistant.base.chercher_conversation(7, faux_vecteur("compost"), top_k=5)
    assert [m["nom"] for m in restants] == ["CR2.pdf"]


def test_erreur_inattendue_de_la_base_donne_un_evenement_erreur(assistant, journal, monkeypatch):
    def en_panne(secteur, vecteur, top_k):
        raise RuntimeError("dimension 4096 != 1024")

    monkeypatch.setattr(assistant.base, "chercher", en_panne)

    evenements = list(assistant.repondre("bmn", "BMN", "maintenance ?", []))

    assert evenements == [
        {"type": "erreur", "message": "L'assistant est indisponible pour le moment, réessayez plus tard.",
         "detail": "RuntimeError: dimension 4096 != 1024"},
    ]


@pytest.fixture
def assistant_fabrique(tmp_path, journal):
    """Assistant + base espionne : `base.appels_chercher` enregistre chaque
    appel à `chercher` sous la forme (document_ids, top_k)."""
    r = Reglages(llm_actif=True, top_k=6, top_k_dossier=3, top_k_pj=3,
                 seuil_distance=1.0, temperature=0.3, historique_tours=3)
    base = BaseVectorielle(tmp_path / "chroma")
    base.appels_chercher = []
    origine = base.chercher

    def chercher_espion(secteur, vecteur, top_k, document_ids=None):
        base.appels_chercher.append((document_ids, top_k))
        return origine(secteur, vecteur, top_k, document_ids=document_ids)

    base.chercher = chercher_espion
    a = Assistant(r, base)
    a.indexer("bmn", 1, str(pdf_texte(tmp_path / "manuel.pdf",
              ["Le vol de maintenance est ordonne par le chef BMN."])), "Manuel.pdf")
    a.indexer("bmn", 9, str(pdf_texte(tmp_path / "prioritaire.pdf",
              ["Tolerances dimensionnelles strictes pour le secteur."])), "Prioritaire.pdf")
    return a, base


def test_les_documents_prioritaires_ajoutent_sans_exclure(assistant_fabrique):
    assistant, base = assistant_fabrique
    list(assistant.repondre("bmn", "BMN", "tolerances ?", [], documents_prioritaires=[9]))

    appels = base.appels_chercher                  # [(document_ids, top_k), ...]
    assert appels[0][0] is None                    # 1re recherche : tout le secteur
    assert appels[1][0] == [9]                     # 2e : les documents prioritaires, en plus


def test_sources_avec_extrait_et_dedoublonnage(assistant_fabrique):
    assistant, base = assistant_fabrique
    evenements = list(assistant.repondre("bmn", "BMN", "vol ?", []))
    sources = next(e for e in evenements if e["type"] == "sources")["sources"]

    assert sources[0]["extrait"]                   # le texte du morceau est renvoyé
    assert len(sources[0]["extrait"]) <= 600
    assert len({(s["document_id"], s["page"]) for s in sources}) == len(sources)


def test_sources_deux_pieces_jointes_distinctes_meme_page_pas_ecrasees():
    """Toutes les pièces jointes ont `document_id = None` : dédoublonner seulement sur
    `(document_id, page)` ferait disparaître l'une des deux, alors que les deux ont servi."""
    morceaux = [
        {"document_id": None, "nom": "Facture.pdf", "page": 1, "texte": "Texte de la facture.", "pj": True},
        {"document_id": None, "nom": "Devis.pdf", "page": 1, "texte": "Texte du devis.", "pj": True},
    ]

    sources = module._sources(morceaux)

    assert {s["nom"] for s in sources} == {"Facture.pdf", "Devis.pdf"}


def test_sources_documents_du_secteur_toujours_dedoublonnes_par_page():
    """Un document du secteur, lui, a un `document_id` : la clé (document_id, page) suffit, avec ou
    sans le nom — deux morceaux de la même page d'un même document restent dédoublonnés."""
    morceaux = [
        {"document_id": 1, "nom": "Manuel.pdf", "page": 1, "texte": "Premier morceau.", "pj": False},
        {"document_id": 1, "nom": "Manuel.pdf", "page": 1, "texte": "Second morceau.", "pj": False},
    ]

    sources = module._sources(morceaux)

    assert len(sources) == 1


def test_pages_bout_a_bout_garde_la_page_de_chaque_position():
    texte, reperes = _pages_bout_a_bout([{"page": 1, "texte": "Début de la règle"}, {"page": 2, "texte": "suite de la règle."}])

    assert texte == "Début de la règle\n\nsuite de la règle."
    assert reperes == [(0, 1), (19, 2)]


def test_document_entier_ne_coupe_plus_au_saut_de_page(tmp_path, monkeypatch):
    pages = [{"page": 1, "texte": "Avant toute dépose, l'opérateur doit"},
             {"page": 2, "texte": "purger le circuit jusqu'à zéro."}]
    monkeypatch.setattr(module.lecteurs, "lire", lambda chemin: pages)

    par_page = Assistant(Reglages(par_page=True), None)._decouper("x.pdf", "X")
    entier = Assistant(Reglages(par_page=False), None)._decouper("x.pdf", "X")

    assert len(par_page) == 2
    assert len(entier) == 1
    assert "doit\n\npurger" in entier[0]["texte"]
    assert entier[0]["page"] == 1                                # page où le morceau commence


def test_decouper_applique_taille_et_fusion(monkeypatch):
    texte = "\n\n".join(f"{i}. TITRE {i}\nContenu court {i}." for i in range(1, 30))
    monkeypatch.setattr(module.lecteurs, "lire", lambda chemin: [{"page": None, "texte": texte}])

    defaut = Assistant(Reglages(fusion_sections=False, taille_morceau=800, taille_min=200, chevauchement=0), None)._decouper("x.txt", "X")
    fusion = Assistant(Reglages(fusion_sections=True, taille_morceau=400, taille_min=100), None)._decouper("x.txt", "X")

    assert len(fusion) < len(defaut)
    assert all(len(m["texte"]) <= 750 for m in fusion)


def test_passages_sans_voisins_egal_a_la_recherche(assistant):
    assistant.r = Reglages(**{**assistant.r.__dict__, "voisins": 0})
    passages = assistant.passages("bmn", "vol de maintenance ?")
    assert [p["id"] for p in passages] == ["doc1_m0"]


def test_passages_avec_voisins_places_a_cote_sans_doublon(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=2, seuil_distance=1.0, voisins=1)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    morceaux = [{"texte": t, "page": 1} for t in ["intro compost", "vol de maintenance", "suite compost", "fin"]]
    a.base.ajouter_document("bmn", 4, "M.pdf", morceaux, [faux_vecteur(m["texte"]) for m in morceaux])

    ids = [p["id"] for p in a.passages("bmn", "vol de maintenance ?")]

    assert ids.index("doc4_m0") < ids.index("doc4_m1") < ids.index("doc4_m2")
    assert len(ids) == len(set(ids))


def test_repondre_envoie_les_voisins_au_llm(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=1.0, voisins=1)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    morceaux = [{"texte": t, "page": 1} for t in ["le chef BMN", "ordonne le vol de maintenance", "sous 72 heures"]]
    a.base.ajouter_document("bmn", 4, "M.pdf", morceaux, [faux_vecteur(m["texte"]) for m in morceaux])

    list(a.repondre("bmn", "BMN", "vol de maintenance ?", []))

    envoye = journal["flux"][0]["messages"][-1]["content"]
    assert "le chef BMN" in envoye and "sous 72 heures" in envoye


def test_sources_ne_montrent_que_les_passages_trouves_pas_les_voisins(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=1.0, voisins=1)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    morceaux = [{"texte": "texte sans rapport", "page": 3},
                {"texte": "ordonne le vol de maintenance", "page": 3},
                {"texte": "autre page sans rapport", "page": 4}]
    a.base.ajouter_document("bmn", 4, "M.pdf", morceaux, [faux_vecteur(m["texte"]) for m in morceaux])

    evenements = list(a.repondre("bmn", "BMN", "vol de maintenance ?", []))

    sources = next(e for e in evenements if e["type"] == "sources")["sources"]
    assert len(sources) == 1
    assert sources[0]["page"] == 3 and sources[0]["extrait"] == "ordonne le vol de maintenance"
    envoye = journal["flux"][0]["messages"][-1]["content"]
    assert "texte sans rapport" in envoye and "autre page sans rapport" in envoye


def test_page_a_morceau_commencant_apres_la_page_1():
    reperes = [(0, 1), (100, 2), (200, 3)]
    assert _page_a(reperes, 0) == 1
    assert _page_a(reperes, 150) == 2
    assert _page_a(reperes, 250) == 3


from rag.assistant import _fusionner


def _assistant_mots_cles(tmp_path, journal, **reglages_supp):
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=1.0, voisins=0, mots_cles=True, top_k_mots_cles=3,
                 **reglages_supp)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    textes = ["vol de maintenance ordonné par le chef"] + [f"compost numéro {i}" for i in range(18)] + \
             ["code HYD-112 : fuite de l'accumulateur"]
    a.base.ajouter_document("bmn", 1, "M.pdf", [{"texte": t, "page": 1} for t in textes],
                            [faux_vecteur(t) for t in textes])
    return a


def test_mots_cles_retrouvent_le_code_rate_par_les_vecteurs(tmp_path, journal):
    a = _assistant_mots_cles(tmp_path, journal)
    passages = a.passages("bmn", "vol HYD-112 ?")
    ids = {p["id"]: p["origine"] for p in passages}
    assert ids["doc1_m19"] == "mots_cles"
    assert ids["doc1_m0"] in ("vecteurs", "les_deux")


def test_garde_fou_sans_mot_rare_rien_n_est_ajoute(tmp_path, journal):
    a = _assistant_mots_cles(tmp_path, journal)
    passages = a.passages("bmn", "compost ?")            # « compost » est partout : pas rare
    assert all(p["origine"] != "mots_cles" for p in passages)


def test_question_sans_mot_utile(tmp_path, journal):
    a = _assistant_mots_cles(tmp_path, journal)
    assert [p["origine"] for p in a.passages("bmn", "de la ?")] in ([], ["vecteurs"])


def test_mots_cles_desactives_identique_a_aujourd_hui(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=6, seuil_distance=1.0, voisins=0, mots_cles=False)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    a.indexer("bmn", 1, str(pdf_texte(tmp_path / "m.pdf", ["Le vol de maintenance est ordonne par le chef BMN."])), "M.pdf")
    passages = a.passages("bmn", "vol de maintenance ?")
    assert [p["id"] for p in passages] == ["doc1_m0"] and "origine" not in passages[0]


def test_panne_de_l_index_vectoriel_seul_et_avertissement(tmp_path, journal, monkeypatch, caplog):
    a = _assistant_mots_cles(tmp_path, journal)

    def panne(*args, **kwargs):
        raise RuntimeError("index illisible")

    monkeypatch.setattr(a.base, "chercher_mots_cles", panne)
    with caplog.at_level("WARNING"):
        passages = a.passages("bmn", "vol HYD-112 ?")
    assert [p["id"] for p in passages] == ["doc1_m0"]
    assert "recherche par mots-clés indisponible" in caplog.text


def test_fusionner_rrf_et_origine():
    v = [{"id": "a", "texte": "x", "distance": 0.1}, {"id": "b", "texte": "y", "distance": 0.2}]
    mc = [{"id": "b", "texte": "y", "distance": None}, {"id": "c", "texte": "code HYD-112", "distance": None},
          {"id": "d", "texte": "rien de rare", "distance": None}]
    f = _fusionner(v, mc, {"hyd112"})
    assert [m["id"] for m in f] == ["b", "a", "c"]                 # b dans les deux listes passe en tête
    assert [m["origine"] for m in f] == ["les_deux", "vecteurs", "mots_cles"]


def test_mots_cles_retrouvent_un_code_de_la_piece_jointe(tmp_path, journal):
    r = Reglages(llm_actif=True, top_k=1, top_k_pj=1, seuil_distance=0.5, voisins=0, mots_cles=True,
                 top_k_mots_cles=3)
    a = Assistant(r, BaseVectorielle(tmp_path / "chroma"))
    a.base.ajouter_document("bmn", 1, "M.pdf", [{"texte": "vol de maintenance ordonné", "page": 1}],
                            [faux_vecteur("vol maintenance")])
    textes = ["note sur le compost", "plaque ZX-900 : serrage à 40 Nm"]
    a.base.ajouter_piece_jointe(7, 2, "P.pdf", [{"texte": t, "page": 1} for t in textes],
                                [faux_vecteur(t) for t in textes])
    passages = a.passages("bmn", "vol ZX-900 ?", conversation=7)
    origines = {p["id"]: p["origine"] for p in passages}
    assert origines["pj2_m1"] == "mots_cles"
