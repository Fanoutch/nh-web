"""Réglages LLM lus dans bmn/llm.env (fichier unique, ignoré par git)."""
from pathlib import Path

import config


def test_lit_les_reglages_du_fichier(tmp_path):
    fichier = tmp_path / "llm.env"
    fichier.write_text(
        "# commentaire\n"
        "LLM_ACTIF=true\n"
        "LLM_BASE_URL=https://exemple.test/v1\n"
        "LLM_MODELE=gpt-test\n"
        'LLM_CLE_API="sk-secret"\n'
        "LLM_TIMEOUT=30\n"
        "LLM_TEMPERATURE=0.2\n",
        encoding="utf-8")

    reglages = config.lire_llm_env(fichier, environ={})

    assert reglages == {"actif": True, "base_url": "https://exemple.test/v1",
                        "modele": "gpt-test", "cle_api": "sk-secret",
                        "timeout": 30, "temperature": 0.2}


def test_fichier_absent_ne_change_rien(tmp_path):
    assert config.lire_llm_env(tmp_path / "absent.env", environ={}) == {}


def test_les_variables_denvironnement_priment(tmp_path):
    fichier = tmp_path / "llm.env"
    fichier.write_text("LLM_MODELE=depuis-fichier\n", encoding="utf-8")
    reglages = config.lire_llm_env(fichier, environ={"LLM_MODELE": "depuis-env"})
    assert reglages["modele"] == "depuis-env"


def test_valeur_vide_ignoree(tmp_path):
    fichier = tmp_path / "llm.env"
    fichier.write_text("LLM_CLE_API=\nLLM_MODELE=m\n", encoding="utf-8")
    assert "cle_api" not in config.lire_llm_env(fichier, environ={})


def test_la_cle_ne_part_jamais_dans_git():
    ignore = (Path(config.BASE_DIR) / ".gitignore").read_text(encoding="utf-8")
    assert "llm.env" in ignore.split()


def test_le_modele_de_reglage_est_fourni():
    exemple = Path(config.BASE_DIR) / "llm.env.example"
    contenu = exemple.read_text(encoding="utf-8")
    for cle in ("LLM_ACTIF", "LLM_BASE_URL", "LLM_MODELE", "LLM_CLE_API"):
        assert cle in contenu
