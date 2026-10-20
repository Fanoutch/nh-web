import json

import openpyxl
import pytest
from fastapi.testclient import TestClient

import service
from rag import modeles
from rag.reglages import Reglages

JETON = {"X-Assistant-Jeton": "secret"}


def std(nom, arguments):
    return {"content": None, "tool_calls": [{"id": "c", "type": "function",
                                             "function": {"name": nom, "arguments": json.dumps(arguments)}}]}


class Scenario:
    def __init__(self, *messages):
        self.messages = list(messages)
        self.recus = []

    def __call__(self, messages, outils):
        self.recus.append((messages, outils))
        m = self.messages.pop(0)
        if isinstance(m, Exception):
            raise m
        return m


@pytest.fixture
def dossiers(tmp_path):
    entree = tmp_path / "entree"
    entree.mkdir()
    wb = openpyxl.Workbook()
    wb.active.append(["Réf", "Stock"])
    wb.active.append(["A", 3])
    wb.save(entree / "stock.xlsx")
    return entree, tmp_path / "sortie"


def corps(dossiers, **plus):
    entree, sortie = dossiers
    base = {"execution_id": 1, "consignes": "Sois bref.", "demande": "Décris le stock.",
            "dossier_execution": str(entree), "fichiers": ["stock.xlsx"], "dossier_sortie": str(sortie),
            "outils": ["excel_decrire", "excel_creer", "excel_lire"]}
    base.update(plus)
    return base


def client(scenario):
    return TestClient(service.creer_app(Reglages(jeton="secret", chroma_dir=None), object(), scenario))


def evenements(reponse):
    return [json.loads(l) for l in reponse.text.splitlines() if l]


def test_jeton_obligatoire(dossiers):
    c = client(Scenario())
    assert c.post("/agents/executions", json=corps(dossiers)).status_code == 401
    assert c.post("/agents/executions/1/arret").status_code == 401


def test_execution_en_flux(dossiers):
    s = Scenario(std("excel_lire", {"fichier": "stock.xlsx"}), {"content": "Un article."})
    r = client(s).post("/agents/executions", headers=JETON, json=corps(dossiers))
    assert r.status_code == 200 and r.headers["content-type"].startswith("application/x-ndjson")
    ev = evenements(r)
    assert [e["type"] for e in ev] == ["etape", "fin"]
    assert ev[1]["statut"] == "termine" and ev[1]["message"] == "Un article."
    messages, declares = s.recus[0]
    assert "Sois bref." in messages[0]["content"]
    assert [o["function"]["name"] for o in declares] == ["excel_creer", "excel_decrire", "excel_lire"]


def test_outil_hors_liste_refuse(dossiers):
    s = Scenario(std("excel_filtrer", {"fichier": "stock.xlsx"}), {"content": "ok"}, {"content": "ok"})
    ev = evenements(client(s).post("/agents/executions", headers=JETON, json=corps(dossiers)))
    assert ev[0]["statut"] == "erreur" and "pas autorisé" in ev[0]["resume"]


def test_fichier_hors_execution_refuse(dossiers):
    s = Scenario(std("excel_decrire", {"fichier": "../secret.xlsx"}), {"content": "ok"})
    ev = evenements(client(s).post("/agents/executions", headers=JETON, json=corps(dossiers)))
    assert ev[0]["statut"] == "erreur"


def test_entrees_invalides(dossiers, tmp_path):
    c = client(Scenario())
    assert c.post("/agents/executions", headers=JETON,
                  json=corps(dossiers, dossier_execution="relatif")).status_code == 422
    assert c.post("/agents/executions", headers=JETON,
                  json=corps(dossiers, dossier_execution=str(tmp_path / "absent"))).status_code == 422
    assert c.post("/agents/executions", headers=JETON, json=corps(dossiers, fichiers=["../x.xlsx"])).status_code == 422
    r = c.post("/agents/executions", headers=JETON, json=corps(dossiers, outils=["rm_rf"]))
    assert r.status_code == 422 and "rm_rf" in r.json()["detail"]["message"]


def test_llm_indisponible_donne_un_evenement_lisible(dossiers):
    s = Scenario(modeles.ModeleIndisponible("Service injoignable sur http://x"))
    r = client(s).post("/agents/executions", headers=JETON, json=corps(dossiers))
    assert r.status_code == 200
    fin = evenements(r)[-1]
    assert fin["type"] == "fin" and fin["statut"] == "arret" and "injoignable" in fin["detail"]


def test_erreur_imprevue_donne_un_evenement_lisible(dossiers):
    r = client(Scenario(RuntimeError("boum"))).post("/agents/executions", headers=JETON, json=corps(dossiers))
    fin = evenements(r)[-1]
    assert fin["statut"] == "arret" and "RuntimeError" in fin["detail"]


def test_arret_demande_avant_le_premier_tour_est_oublie_au_lancement(dossiers):
    # un arrêt d'une exécution précédente de même numéro ne doit pas couper la suivante
    c = client(Scenario({"content": "Fait."}, {"content": "Fait."}))
    assert c.post("/agents/executions/1/arret", headers=JETON).json() == {"ok": True}
    fin = evenements(c.post("/agents/executions", headers=JETON, json=corps(dossiers)))[-1]
    assert fin["statut"] == "termine"


def test_arret_en_cours_d_execution(dossiers):
    c = client(None)

    def scenario(messages, outils):
        c.post("/agents/executions/1/arret", headers=JETON)   # l'utilisateur clique « Arrêter »
        return std("excel_decrire", {"fichier": "stock.xlsx"})

    app = service.creer_app(Reglages(jeton="secret", chroma_dir=None), object(), scenario)
    c = TestClient(app)
    ev = evenements(c.post("/agents/executions", headers=JETON, json=corps(dossiers)))
    assert ev[-1]["statut"] == "arret" and ev[-1]["message"] == "Arrêt demandé."


def test_fichier_produit_dans_la_fin(dossiers):
    s = Scenario(std("excel_creer", {"nom": "commande.xlsx", "colonnes": ["Réf"], "lignes": [["A"]]}),
                 {"content": "Créé."})
    fin = evenements(client(s).post("/agents/executions", headers=JETON, json=corps(dossiers)))[-1]
    assert fin["statut"] == "termine" and fin["fichiers"]
    assert (dossiers[1] / fin["fichiers"][0]).exists()


def test_lire_fichiers_donne_la_structure_sans_les_valeurs(dossiers):
    entree, _ = dossiers
    r = client(Scenario()).post("/agents/fichiers/lire", headers=JETON,
                                json={"dossier": str(entree), "fichiers": ["stock.xlsx", "absent.xlsx"]})

    assert r.status_code == 200
    ok_, absent = r.json()["fichiers"]
    assert ok_["statut"] == "ok" and ok_["feuilles"][0]["colonnes"] == ["Réf", "Stock"] and ok_["feuilles"][0]["lignes"] == 1
    assert '"A"' not in json.dumps(ok_)          # la valeur d'une cellule n'est jamais renvoyée
    assert absent["statut"] == "erreur"


def test_lire_fichiers_signale_un_classeur_vide_ou_illisible(dossiers, tmp_path):
    entree, _ = dossiers
    openpyxl.Workbook().save(entree / "vide.xlsx")
    (entree / "casse.xlsx").write_bytes(b"pas un classeur")

    r = client(Scenario()).post("/agents/fichiers/lire", headers=JETON,
                                json={"dossier": str(entree), "fichiers": ["vide.xlsx", "casse.xlsx"]})

    vide, casse = r.json()["fichiers"]
    assert vide["statut"] == "avertissement" and "aucune ligne" in vide["message"]
    assert casse["statut"] == "erreur" and "illisible" in casse["message"]


def test_lire_fichiers_exige_le_jeton_et_des_entrees_valides(dossiers, tmp_path):
    entree, _ = dossiers
    c = client(Scenario())
    assert c.post("/agents/fichiers/lire", json={"dossier": str(entree), "fichiers": []}).status_code == 401
    assert c.post("/agents/fichiers/lire", headers=JETON, json={"dossier": "relatif", "fichiers": []}).status_code == 422
    assert c.post("/agents/fichiers/lire", headers=JETON,
                  json={"dossier": str(entree), "fichiers": ["../x.xlsx"]}).status_code == 422
