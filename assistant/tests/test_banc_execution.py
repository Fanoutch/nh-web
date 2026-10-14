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
    r = Reglages(llm_actif=True, top_k=1, seuil_distance=2.0, chroma_dir=tmp_path / "jamais",
                 fusion_sections=False, par_page=True, chevauchement=0, voisins=0, taille_morceau=800, taille_min=200)
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


def test_rapport_montre_erreurs_et_illisibles(tmp_path):
    lignes = [{"id": str(i), "type": "simple", "applicable": True, "trouve": True, "rang": 1, "note": n,
               "juste": None, "fidele": None, "refus_correct": None}
              for i, n in enumerate(["ok", "erreur", "erreur", "illisible"])]
    res = {"jeu": "j", "format": "txt", "lignes": lignes, "seuil_propose": None,
           "morceaux": {"nombre": 1, "mediane": 1, "petits": 0}}
    dossier = ecrire_rapport([res], tmp_path / "s")

    rapport = (dossier / "rapport.md").read_text(encoding="utf-8").splitlines()
    en_tete = [c.strip() for c in rapport[4].strip("|").split("|")]
    ligne = [c.strip() for c in rapport[6].strip("|").split("|")]
    assert ligne[en_tete.index("Erreurs")] == "2"
    assert ligne[en_tete.index("Illisibles")] == "1"


def test_banc_refuse_reponses_si_llm_desactive(tmp_path, monkeypatch, capsys):
    from banc import banc
    llm = tmp_path / "llm.env"
    llm.write_text("LLM_ACTIF=false\n", encoding="utf-8")
    monkeypatch.setattr(banc, "reglages_du_jeu", lambda nom, llm_env: Reglages(llm_actif=False))

    code = banc.main(["--reglages", "actuel", "--reponses", "--llm-env", str(llm)])

    assert code == 2
    assert "Le LLM est désactivé" in capsys.readouterr().out


def test_trouve_par_mots_cles_et_etat_du_rapport(corpus, tmp_path, monkeypatch):
    from rag.assistant import Assistant

    def passages_mots_cles(self, secteur, question, *a, **k):
        return [{"id": "doc1_m0", "document_id": 1, "nom": "m.txt", "page": None, "pj": False, "distance": None,
                 "texte": "La pression de gonflage à froid est de 1 450 kPa.", "origine": "mots_cles"}]

    monkeypatch.setattr(Assistant, "passages", passages_mots_cles)
    res = executer("actuel", Reglages(llm_actif=True, top_k=1, seuil_distance=2.0), "txt", QUESTIONS[:1], corpus, False)
    ligne = res["lignes"][0]
    assert ligne["trouve"] is True and ligne["trouve_par_mots_cles"] is True
    assert "[mots_cles]" in ligne["retrouves"]
    rapport = (ecrire_rapport([res], tmp_path / "s") / "rapport.md").read_text(encoding="utf-8")
    assert rapport.splitlines()[2].startswith("**État : OK**")
    assert "Trouvé grâce aux mots-clés" in rapport


def test_volume_envoye_par_question_et_dans_le_rapport(corpus, tmp_path, monkeypatch):
    from rag.assistant import Assistant

    def deux_passages(self, secteur, question, *a, **k):
        return [{"id": f"doc1_m{i}", "document_id": 1, "nom": "m.txt", "page": None, "pj": False, "distance": 0.1,
                 "texte": t} for i, t in enumerate(["x" * 30, "La pression de gonflage à froid est de 1 450 kPa."])]

    monkeypatch.setattr(Assistant, "passages", deux_passages)
    res = executer("actuel", Reglages(llm_actif=True, top_k=1, seuil_distance=2.0), "txt", QUESTIONS[:1], corpus, False)
    ligne = res["lignes"][0]
    assert ligne["passages_envoyes"] == 2 and ligne["caracteres_envoyes"] == 30 + 49
    res["lignes"].append({**ligne, "id": "A2", "passages_envoyes": 4, "caracteres_envoyes": 200})

    dossier = ecrire_rapport([res], tmp_path / "s")
    rapport = (dossier / "rapport.md").read_text(encoding="utf-8").splitlines()
    en_tete = [c.strip() for c in rapport[4].strip("|").split("|")]
    valeurs = [c.strip() for c in rapport[6].strip("|").split("|")]
    assert valeurs[en_tete.index("Passages envoyés (moy / max)")] == "3 / 4"
    assert valeurs[en_tete.index("Caractères envoyés (moy / max)")] == "140 / 200"
    assert "caracteres_envoyes" in (dossier / "detail.csv").read_text(encoding="utf-8-sig").splitlines()[0]
