"""Tests de l'appel LLM (llm_client) et de l'enrichissement du CSV.

Aucun test ne contacte un vrai modèle : soit le mode hors ligne, soit un
faux serveur HTTP local compatible OpenAI.
"""
import json
import threading
from datetime import date, datetime
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest
from openpyxl import load_workbook

import config
import daily_report as dr
import llm_client
import make_fixtures as mf


# ---------------------------------------------------------------------------
# Faux serveur compatible OpenAI
# ---------------------------------------------------------------------------
@pytest.fixture
def faux_service():
    """Démarre un service local qui répond comme /v1/chat/completions."""
    recu = {}

    class Handler(BaseHTTPRequestHandler):
        def do_POST(self):
            taille = int(self.headers.get("content-length", 0))
            recu["requete"] = json.loads(self.rfile.read(taille))
            recu["chemin"] = self.path
            contenu = ('```json\n{"equipement": "MGB", "indice": "haut", '
                       '"justification": "vibration"}\n```')
            corps = json.dumps(
                {"choices": [{"message": {"role": "assistant", "content": contenu}}]}
            ).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(corps)))
            self.end_headers()
            self.wfile.write(corps)

        def log_message(self, *args):
            pass

    serveur = HTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=serveur.serve_forever, daemon=True).start()
    port = serveur.server_address[1]
    yield f"http://127.0.0.1:{port}/v1", recu
    serveur.shutdown()


@pytest.fixture
def sandbox(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "INCOMING_DIR", tmp_path / "incoming")
    monkeypatch.setattr(config, "OUTPUT_DIR", tmp_path / "output")
    monkeypatch.setattr(config, "ARCHIVE_DIR", tmp_path / "archive")
    monkeypatch.setattr(config, "TEMPLATE_PATH", tmp_path / "template.xlsx")
    monkeypatch.setattr(config, "LOG_FILE", tmp_path / "logs" / "test.log")
    mf.make_template(config.TEMPLATE_PATH)
    csv = mf.make_csv(config.INCOMING_DIR, date(2026, 9, 3))
    return tmp_path, csv


# ---------------------------------------------------------------------------
# Lecture de la réponse
# ---------------------------------------------------------------------------
def test_extraire_json_accepte_un_bloc_de_code():
    brut = 'Voici :\n```json\n{"equipement": "MGB"}\n```\nvoilà.'
    assert llm_client.extraire_json(brut)["equipement"] == "MGB"


def test_extraire_json_signale_une_reponse_sans_json():
    resultat = llm_client.extraire_json("je ne sais pas")
    assert "erreur" in resultat


# ---------------------------------------------------------------------------
# Extraction d'un enregistrement
# ---------------------------------------------------------------------------
def test_mode_hors_ligne_ne_contacte_personne():
    conf = dict(config.LLM, actif=True, hors_ligne=True, base_url="http://127.0.0.1:1/v1")
    resultat = llm_client.extraire_equipement({"code": "46830"}, conf)
    assert set(config.LLM["champs_produits"]) <= set(resultat)


def test_appel_reel_sur_faux_service(faux_service):
    url, recu = faux_service
    conf = dict(config.LLM, actif=True, hors_ligne=False, base_url=url,
                modele="modele-test", champs_envoyes=["code", "description"])
    resultat = llm_client.extraire_equipement(
        {"code": "46830", "description": "vibration", "secret": "ne pas envoyer"}, conf)

    assert resultat["equipement"] == "MGB"
    assert recu["chemin"].endswith("/chat/completions")
    assert recu["requete"]["model"] == "modele-test"
    envoye = recu["requete"]["messages"][-1]["content"]
    assert "46830" in envoye and "ne pas envoyer" not in envoye


def test_service_injoignable_leve_une_erreur_lisible():
    conf = dict(config.LLM, actif=True, hors_ligne=False,
                base_url="http://127.0.0.1:1/v1", modele="x", timeout=2)
    with pytest.raises(llm_client.LlmError):
        llm_client.extraire_equipement({"code": "1"}, conf)


# ---------------------------------------------------------------------------
# Enrichissement du tableau
# ---------------------------------------------------------------------------
def test_colonnes_llm_toujours_presentes_meme_si_inactif(sandbox, monkeypatch):
    _, csv = sandbox
    monkeypatch.setattr(config, "LLM", dict(config.LLM, actif=False))
    df = dr.enrichir_avec_llm(dr.load_csv(csv))
    for champ in config.LLM["champs_produits"]:
        assert f"llm.{champ}" in df.columns
        assert (df[f"llm.{champ}"] == "").all()


def test_enrichissement_remplit_les_colonnes(sandbox, monkeypatch, faux_service):
    _, csv = sandbox
    url, _ = faux_service
    monkeypatch.setattr(config, "LLM", dict(config.LLM, actif=True, hors_ligne=False,
                                            base_url=url, modele="modele-test"))
    df = dr.enrichir_avec_llm(dr.load_csv(csv))
    assert (df["llm.equipement"] == "MGB").all()
    assert len(df) == 5


def test_panne_du_service_nempeche_pas_la_generation(sandbox, monkeypatch):
    _, csv = sandbox
    monkeypatch.setattr(config, "LLM", dict(config.LLM, actif=True, hors_ligne=False,
                                            base_url="http://127.0.0.1:1/v1",
                                            modele="x", timeout=2))
    df = dr.enrichir_avec_llm(dr.load_csv(csv))
    assert (df["llm.equipement"] == "").all()

    resultat = dr.process(csv, run_ts=datetime(2026, 9, 3, 8, 0, 0), dry_run=True)
    assert resultat.status == "ok" and resultat.rows_written == 5


def test_une_colonne_llm_peut_alimenter_le_template(sandbox, monkeypatch):
    _, csv = sandbox
    monkeypatch.setattr(config, "LLM", dict(config.LLM, actif=True, hors_ligne=True))
    mapping = dict(config.TABLE_MAPPING)
    mapping["columns"] = dict(mapping["columns"], **{"llm.equipement": "B"})
    monkeypatch.setattr(config, "TABLE_MAPPING", mapping)

    resultat = dr.process(csv, run_ts=datetime(2026, 9, 3, 8, 0, 0), dry_run=True)
    feuille = load_workbook(resultat.output_path)[config.TARGET_SHEET]
    assert feuille["B7"].value  # rempli par la réponse hors ligne


def test_colonnes_llm_hors_des_colonnes_csv_obligatoires(monkeypatch):
    mapping = dict(config.TABLE_MAPPING)
    mapping["columns"] = dict(mapping["columns"], **{"llm.equipement": "B"})
    monkeypatch.setattr(config, "TABLE_MAPPING", mapping)
    assert not any(c.startswith("llm.") for c in config.required_csv_columns())
