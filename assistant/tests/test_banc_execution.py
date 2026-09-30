import pytest

from banc import execution
from banc.execution import executer, reglages_du_jeu
from banc.questions import Question
from banc.rapport import ecrire_rapport
from rag import modeles
from rag.reglages import Reglages


def faux_vecteur(texte):
    t = texte.lower()
    return [1.0 if "gonflage" in t else 0.0, 1.0 if "report" in t else 0.0, 0.1]


@pytest.fixture
def corpus(tmp_path, monkeypatch):
    (tmp_path / "txt").mkdir()
    (tmp_path / "txt" / "m.txt").write_text(
        "1. PRESSION\nLa pression de gonflage à froid est de 1 450 kPa.\n\n"
        "2. REPORT\nCe report ne peut excéder 5 heures de vol.\n", encoding="utf-8")
    monkeypatch.setattr(modeles, "embedder", lambda textes, r: [faux_vecteur(t) for t in textes])
    monkeypatch.setattr(modeles, "discuter_en_flux", lambda m, r, t: iter(["1 450 kPa."]))
    monkeypatch.setattr(modeles, "discuter", lambda m, r, t: '{"juste": true, "fidele": true, "justification": "ok"}')
    return tmp_path


QUESTIONS = [
    Question("A", "simple", "Pression de gonflage ?", ["1 450 kPa"], ["La pression de gonflage à froid est de 1 450 kPa."], ["m"]),
    Question("B", "simple", "Question sur un PDF réel ?", ["x"], ["un passage quelconque ici"], ["reel-absent"]),
]


def test_executer_recherche_et_non_applicable(corpus, tmp_path):
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=2.0, chroma_dir=tmp_path / "jamais")
    res = executer("actuel", r, "txt", QUESTIONS, corpus, avec_reponses=False)

    lignes = {l["id"]: l for l in res["lignes"]}
    assert lignes["A"]["applicable"] and lignes["A"]["trouve"] and lignes["A"]["rang"] == 1
    assert lignes["B"]["applicable"] is False
    assert res["morceaux"]["nombre"] == 2
    assert not (tmp_path / "jamais").exists()          # jamais la base du site


def test_executer_avec_reponses(corpus):
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=2.0)
    ligne = executer("actuel", r, "txt", QUESTIONS[:1], corpus, avec_reponses=True)["lignes"][0]
    assert ligne["reponse"] == "1 450 kPa." and ligne["juste"] is True


def test_embedding_injoignable_arret_clair(corpus, monkeypatch):
    def panne(textes, r):
        raise modeles.ModeleIndisponible("EMBED_BASE_URL manquant dans llm.env")

    monkeypatch.setattr(modeles, "embedder", panne)
    with pytest.raises(SystemExit, match="EMBED_BASE_URL"):
        executer("actuel", Reglages(llm_actif=True), "txt", QUESTIONS[:1], corpus, avec_reponses=False)


def test_reglages_du_jeu_surcharge_llm_env(tmp_path, monkeypatch):
    llm = tmp_path / "llm.env"
    llm.write_text("EMBED_MODELE=BAAI/bge-m3\nASSISTANT_VOISINS=0\n", encoding="utf-8")
    monkeypatch.setattr(execution, "DOSSIER_JEUX", tmp_path)
    (tmp_path / "voisins.env").write_text("ASSISTANT_VOISINS=1\n", encoding="utf-8")

    r = reglages_du_jeu("voisins", llm)

    assert r.voisins == 1 and r.embed_modele == "BAAI/bge-m3"


def test_rapport_ecrit_tableau_et_detail(corpus, tmp_path):
    res = executer("actuel", Reglages(llm_actif=True, top_k=1, seuil_distance=2.0), "txt", QUESTIONS, corpus, False)
    dossier = ecrire_rapport([res], tmp_path / "sortie")

    rapport = (dossier / "rapport.md").read_text(encoding="utf-8")
    assert "| actuel | txt |" in rapport and "100 %" in rapport
    assert (dossier / "detail.csv").read_text(encoding="utf-8-sig").splitlines()[0].startswith("jeu;format;id")
