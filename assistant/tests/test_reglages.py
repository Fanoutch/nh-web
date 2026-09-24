from pathlib import Path

from rag import reglages


def ecrire(dossier: Path, nom: str, contenu: str) -> Path:
    chemin = dossier / nom
    chemin.write_text(contenu, encoding="utf-8")
    return chemin


def test_defauts_sans_fichiers():
    r = reglages.charger({"ASSISTANT_REGLAGES": "aucun"})

    assert r.llm_actif is False
    assert r.top_k == 6
    assert r.top_k_dossier == 3
    assert r.top_k_pj == 3
    assert r.seuil_distance == 1.0
    assert r.temperature == 0.3
    assert r.historique_tours == 3
    assert r.port == 8765
    assert r.hote == "127.0.0.1"
    assert r.jeton == ""
    assert r.embed_format == "openai"
    assert r.chroma_dir == reglages.RACINE_NH_WEB / "storage" / "app" / "assistant" / "chroma"


def test_lit_llm_env_et_reglages_env(tmp_path):
    llm = ecrire(tmp_path, "llm.env", (
        "LLM_ACTIF=true\n"
        "LLM_BASE_URL=https://llm.example/v1\n"
        "LLM_MODELE=mistral # commentaire\n"
        "LLM_TEMPERATURE=0\n"
        "EMBED_BASE_URL=https://hf.example/bge\n"
        "EMBED_FORMAT=HF\n"
    ))
    reg = ecrire(tmp_path, "reglages.env", (
        "ASSISTANT_TOP_K=4\n"
        "ASSISTANT_TOP_K_DOSSIER=2\n"
        "ASSISTANT_TOP_K_PJ=1\n"
        "ASSISTANT_JETON='secret'\n"
        "ASSISTANT_CHROMA_DIR=/tmp/chroma-test\n"
    ))

    r = reglages.charger({"LLM_ENV": str(llm), "ASSISTANT_REGLAGES": str(reg)})

    assert r.llm_actif is True
    assert r.llm_base_url == "https://llm.example/v1"
    assert r.llm_modele == "mistral"
    assert r.embed_base_url == "https://hf.example/bge"
    assert r.embed_format == "hf"
    assert r.top_k == 4
    assert r.top_k_dossier == 2
    assert r.top_k_pj == 1
    assert r.jeton == "secret"
    assert r.chroma_dir == Path("/tmp/chroma-test")
    assert r.temperature == 0.3  # LLM_TEMPERATURE (HIL) n'est pas lue


def test_variable_environnement_prioritaire(tmp_path):
    reg = ecrire(tmp_path, "reglages.env", "ASSISTANT_TOP_K=4\n")

    r = reglages.charger({
        "ASSISTANT_REGLAGES": str(reg),
        "LLM_ENV": str(tmp_path / "absent.env"),
        "ASSISTANT_TOP_K": "9",
    })

    assert r.top_k == 9


def test_chemin_chroma_relatif_au_dossier_assistant(tmp_path):
    reg = ecrire(tmp_path, "reglages.env", "ASSISTANT_CHROMA_DIR=../storage/x\n")

    r = reglages.charger({"ASSISTANT_REGLAGES": str(reg), "LLM_ENV": str(tmp_path / "absent.env")})

    assert r.chroma_dir == (reglages.DOSSIER_ASSISTANT / "../storage/x").resolve()
