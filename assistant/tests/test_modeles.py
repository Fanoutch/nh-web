import json

import pytest
import requests

from rag import modeles
from rag.reglages import Reglages


class FausseReponse:
    def __init__(self, donnees=None, statut=200, lignes=None, erreur_json=False):
        self._donnees = donnees
        self.status_code = statut
        self.ok = statut < 400
        self.text = json.dumps(donnees) if donnees is not None else ""
        self._lignes = lignes or []
        self.encoding = None
        self.ferme = False
        self._erreur_json = erreur_json

    def json(self):
        if self._erreur_json:
            raise ValueError("Expecting value")
        return self._donnees

    def iter_lines(self, decode_unicode=False):
        yield from self._lignes

    def close(self):
        self.ferme = True


@pytest.fixture
def appels(monkeypatch):
    journal = {"reponses": [], "requetes": []}

    def faux_post(url, headers, json, timeout, stream=False):
        journal["requetes"].append({"url": url, "headers": headers, "json": json, "stream": stream})
        return journal["reponses"].pop(0)

    monkeypatch.setattr(modeles.requests, "post", faux_post)
    return journal


R_HF = Reglages(embed_base_url="https://hf.example/bge", embed_format="hf", embed_cle_api="hf_x")
R_OPENAI = Reglages(embed_base_url="http://serveur/v1", embed_format="openai", embed_modele="BAAI/bge-m3")
R_LLM = Reglages(llm_base_url="https://llm.example/v1/", llm_modele="mistral", llm_cle_api="cle")


def test_embedding_format_hf(appels):
    appels["reponses"].append(FausseReponse([[0.1, 0.2], [0.3, 0.4]]))

    assert modeles.embedder(["a", "b"], R_HF) == [[0.1, 0.2], [0.3, 0.4]]
    requete = appels["requetes"][0]
    assert requete["url"] == "https://hf.example/bge"
    assert requete["json"] == {"inputs": ["a", "b"]}
    assert requete["headers"]["Authorization"] == "Bearer hf_x"


def test_embedding_format_openai_sans_cle(appels):
    appels["reponses"].append(FausseReponse({"data": [{"embedding": [1.0]}]}))

    assert modeles.embedder(["a"], R_OPENAI) == [[1.0]]
    requete = appels["requetes"][0]
    assert requete["url"] == "http://serveur/v1/embeddings"
    assert requete["json"] == {"input": ["a"], "model": "BAAI/bge-m3"}
    assert "Authorization" not in requete["headers"]


def test_embedding_par_lots_de_32(appels):
    textes = [f"t{i}" for i in range(40)]
    appels["reponses"] += [FausseReponse([[1.0]] * 32), FausseReponse([[2.0]] * 8)]

    vecteurs = modeles.embedder(textes, R_HF)

    assert len(vecteurs) == 40
    assert [len(r["json"]["inputs"]) for r in appels["requetes"]] == [32, 8]


def test_embedding_liste_vide_sans_appel(appels):
    assert modeles.embedder([], R_HF) == []
    assert appels["requetes"] == []


def test_embedding_erreur_http(appels):
    appels["reponses"].append(FausseReponse({"detail": "No matching chute found!"}, statut=404))

    with pytest.raises(modeles.ModeleIndisponible, match="404"):
        modeles.embedder(["a"], R_HF)


def test_embedding_service_injoignable(monkeypatch):
    def refuse(*args, **kwargs):
        raise requests.ConnectionError("connexion refusée")

    monkeypatch.setattr(modeles.requests, "post", refuse)

    with pytest.raises(modeles.ModeleIndisponible, match="injoignable"):
        modeles.embedder(["a"], R_HF)


def test_embedding_mal_configure():
    with pytest.raises(modeles.ModeleIndisponible, match="EMBED_BASE_URL"):
        modeles.embedder(["a"], Reglages())
    with pytest.raises(modeles.ModeleIndisponible, match="EMBED_FORMAT"):
        modeles.embedder(["a"], Reglages(embed_base_url="x", embed_format="grpc"))


def test_discuter(appels):
    appels["reponses"].append(FausseReponse({"choices": [{"message": {"content": "Bonjour"}}]}))

    assert modeles.discuter([{"role": "user", "content": "Salut"}], R_LLM, 0) == "Bonjour"
    requete = appels["requetes"][0]
    assert requete["url"] == "https://llm.example/v1/chat/completions"
    assert requete["json"]["model"] == "mistral"
    assert requete["json"]["temperature"] == 0
    assert requete["json"]["stream"] is False


def test_discuter_en_flux(appels):
    lignes = [
        'data: {"choices": [{"delta": {"role": "assistant"}}]}',
        "",
        'data: {"choices": [{"delta": {"content": "Le vol "}}]}',
        'data: {"choices": [{"delta": {"content": "est ordonné."}}]}',
        "data: [DONE]",
    ]
    reponse = FausseReponse(lignes=lignes)
    appels["reponses"].append(reponse)

    morceaux = list(modeles.discuter_en_flux([{"role": "user", "content": "?"}], R_LLM, 0.3))

    assert morceaux == ["Le vol ", "est ordonné."]
    assert appels["requetes"][0]["json"]["stream"] is True
    assert appels["requetes"][0]["stream"] is True
    assert reponse.encoding == "utf-8"
    assert reponse.ferme is True


def test_llm_mal_configure():
    with pytest.raises(modeles.ModeleIndisponible, match="LLM_BASE_URL"):
        modeles.discuter([{"role": "user", "content": "?"}], Reglages(), 0)


def test_embedding_reponse_non_json(appels):
    appels["reponses"].append(FausseReponse(erreur_json=True))

    with pytest.raises(modeles.ModeleIndisponible, match="Réponse non JSON"):
        modeles.embedder(["a"], R_HF)


def test_embedding_hf_reponse_inattendue(appels):
    appels["reponses"].append(FausseReponse({"error": "Model is loading"}))

    with pytest.raises(modeles.ModeleIndisponible, match="Réponse d'embedding inattendue"):
        modeles.embedder(["a"], R_HF)


def test_discuter_reponse_non_json(appels):
    appels["reponses"].append(FausseReponse(erreur_json=True))

    with pytest.raises(modeles.ModeleIndisponible, match="Réponse non JSON"):
        modeles.discuter([{"role": "user", "content": "?"}], R_LLM, 0)


def test_embedding_format_openai_trie_par_index(appels):
    appels["reponses"].append(FausseReponse({"data": [
        {"index": 2, "embedding": [3.0]}, {"index": 0, "embedding": [1.0]}, {"index": 1, "embedding": [2.0]},
    ]}))

    assert modeles.embedder(["a", "b", "c"], R_OPENAI) == [[1.0], [2.0], [3.0]]
