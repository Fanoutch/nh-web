import pytest

from rag import assistant as module
from rag import modeles
from rag.assistant import Assistant
from rag.base import BaseVectorielle
from rag.lecteurs import LectureImpossible
from rag.reglages import Reglages
from tests.fabrique import pdf_texte, pdf_vide


def faux_vecteur(texte: str) -> list[float]:
    texte = texte.lower()
    if "maintenance" in texte:
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
        {"type": "sources", "sources": [{"document_id": 1, "nom": "Manuel.pdf", "page": 1}]},
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

    assert evenements[0]["sources"] == [{"document_id": 1, "nom": "Manuel.pdf", "page": 1}]
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


def test_erreur_inattendue_de_la_base_donne_un_evenement_erreur(assistant, journal, monkeypatch):
    def en_panne(secteur, vecteur, top_k):
        raise RuntimeError("dimension 4096 != 1024")

    monkeypatch.setattr(assistant.base, "chercher", en_panne)

    evenements = list(assistant.repondre("bmn", "BMN", "maintenance ?", []))

    assert evenements == [
        {"type": "erreur", "message": "L'assistant est indisponible pour le moment, réessayez plus tard.",
         "detail": "RuntimeError: dimension 4096 != 1024"},
    ]
