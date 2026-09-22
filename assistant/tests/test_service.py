import json

import pytest
from fastapi.testclient import TestClient

import service
from rag import modeles
from rag.lecteurs import LectureImpossible
from rag.reglages import Reglages

JETON = {"X-Assistant-Jeton": "secret"}


class FauxAssistant:
    def __init__(self):
        self.appels = []
        self.erreur = None

    def indexer(self, secteur, document_id, chemin, nom):
        self.appels.append(("indexer", secteur, document_id, chemin, nom))
        if self.erreur:
            raise self.erreur
        return 7

    def supprimer(self, secteur, document_id):
        self.appels.append(("supprimer", secteur, document_id))

    def repondre(self, secteur, nom_secteur, question, historique):
        self.appels.append(("repondre", secteur, nom_secteur, question, historique))
        yield {"type": "sources", "sources": [{"document_id": 1, "nom": "Manuel.pdf", "page": 2}]}
        yield {"type": "texte", "texte": "Réponse "}
        yield {"type": "texte", "texte": "complète."}
        yield {"type": "fin"}


@pytest.fixture
def faux():
    return FauxAssistant()


@pytest.fixture
def client(faux, tmp_path):
    reglages = Reglages(jeton="secret", llm_actif=True, chroma_dir=tmp_path / "c")
    return TestClient(service.creer_app(reglages, faux))


def test_jeton_obligatoire(client):
    assert client.get("/formats").status_code == 401
    assert client.get("/formats", headers={"X-Assistant-Jeton": "faux"}).status_code == 401


def test_jeton_vide_refuse_tout(faux, tmp_path):
    client = TestClient(service.creer_app(Reglages(jeton="", chroma_dir=tmp_path / "c"), faux))

    assert client.get("/formats", headers={"X-Assistant-Jeton": ""}).status_code == 401


def test_formats(client):
    assert client.get("/formats", headers=JETON).json() == {"formats": ["docx", "pdf", "txt"]}


def test_indexer_un_document(client, faux):
    reponse = client.post("/documents", headers=JETON,
                          json={"secteur": "bmn", "document_id": 4, "chemin": "/x/4_manuel.pdf", "nom": "Manuel.pdf"})

    assert reponse.status_code == 200
    assert reponse.json() == {"nb_morceaux": 7}
    assert faux.appels == [("indexer", "bmn", 4, "/x/4_manuel.pdf", "Manuel.pdf")]


def test_document_illisible_422(client, faux):
    try:
        raise LectureImpossible("Aucun texte trouvé dans ce PDF (document scanné ?).") from ValueError("page vide")
    except LectureImpossible as exc:
        faux.erreur = exc

    reponse = client.post("/documents", headers=JETON,
                          json={"secteur": "bmn", "document_id": 4, "chemin": "/x", "nom": "Scan.pdf"})

    assert reponse.status_code == 422
    assert reponse.json()["detail"]["code"] == "lecture"
    assert reponse.json()["detail"]["message"] == "Aucun texte trouvé dans ce PDF (document scanné ?)."
    assert "page vide" in reponse.json()["detail"]["detail"]


def test_modele_indisponible_503(client, faux):
    faux.erreur = modeles.ModeleIndisponible("HF a répondu 503")

    reponse = client.post("/documents", headers=JETON,
                          json={"secteur": "bmn", "document_id": 4, "chemin": "/x", "nom": "M.pdf"})

    assert reponse.status_code == 503
    assert reponse.json()["detail"]["code"] == "modele"
    assert reponse.json()["detail"]["detail"] == "HF a répondu 503"


def test_supprimer_un_document(client, faux):
    reponse = client.delete("/documents/4", params={"secteur": "bmn"}, headers=JETON)

    assert reponse.json() == {"ok": True}
    assert faux.appels == [("supprimer", "bmn", 4)]


def test_question_en_flux_ndjson(client, faux):
    historique = [{"role": "user", "content": "Q1"}, {"role": "assistant", "content": "R1"}]

    with client.stream("POST", "/questions", headers=JETON,
                       json={"secteur": "bmn", "nom_secteur": "BMN", "question": "Q2", "historique": historique}) as reponse:
        assert reponse.headers["content-type"].startswith("application/x-ndjson")
        lignes = [json.loads(l) for l in reponse.iter_lines() if l]

    assert [e["type"] for e in lignes] == ["sources", "texte", "texte", "fin"]
    assert lignes[1]["texte"] == "Réponse "
    assert faux.appels == [("repondre", "bmn", "BMN", "Q2", historique)]


def test_sante(client, monkeypatch):
    monkeypatch.setattr(modeles, "embedder", lambda t, r: [[1.0]])

    def llm_en_panne(messages, r, temperature):
        raise modeles.ModeleIndisponible("LLM injoignable")

    monkeypatch.setattr(modeles, "discuter", llm_en_panne)

    assert client.get("/sante", headers=JETON).json() == {"service": "ok", "embedding": "ok", "llm": "LLM injoignable"}


def test_erreur_imprevue_a_l_indexation_503(client, faux):
    faux.erreur = RuntimeError("disque plein")

    reponse = client.post("/documents", headers=JETON,
                          json={"secteur": "bmn", "document_id": 4, "chemin": "/x", "nom": "M.pdf"})

    assert reponse.status_code == 503
    assert reponse.json()["detail"] == {
        "code": "interne",
        "message": "L'assistant est indisponible pour le moment, réessayez plus tard.",
        "detail": "RuntimeError: disque plein",
    }


def test_jeton_compare_en_temps_constant(client, monkeypatch):
    appels = []
    original = service.hmac.compare_digest

    def espion(a, b):
        appels.append((a, b))
        return original(a, b)

    monkeypatch.setattr(service.hmac, "compare_digest", espion)

    assert client.get("/formats", headers=JETON).status_code == 200
    assert client.get("/formats", headers={"X-Assistant-Jeton": "faux"}).status_code == 401
    assert len(appels) == 2


def test_jeton_non_ascii_refuse_sans_planter(client):
    reponse = client.get("/formats", headers={"X-Assistant-Jeton": "s\xe9cret".encode("latin-1")})

    assert reponse.status_code == 401
