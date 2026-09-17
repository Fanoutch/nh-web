"""Réglages locaux, ignorés par git.

- llm.env, à la RACINE de nh-web : tout ce qui concerne le LLM, partagé entre
  l'envoi des HIL (ce pipeline) et l'assistant IA du site ;
- bmn/reglages.env : ce qui concerne la dispo (template réel, blocs).
"""
import subprocess
from pathlib import Path

import config

RACINE = Path(config.BASE_DIR).parent


def ecrire(dossier, nom, contenu):
    fichier = dossier / nom
    fichier.write_text(contenu, encoding="utf-8")
    return fichier


# ---------------------------------------------------------------------------
# llm.env (racine du projet)
# ---------------------------------------------------------------------------
def test_lit_les_reglages_llm(tmp_path):
    fichier = ecrire(tmp_path, "llm.env",
        "# commentaire\n"
        "LLM_ACTIF=true\n"
        "LLM_BASE_URL=https://exemple.test/v1\n"
        "LLM_MODELE=gpt-test\n"
        'LLM_CLE_API="sk-secret"\n'
        "LLM_TIMEOUT=30\n"
        "LLM_TEMPERATURE=0.2\n")

    assert config.reglages_llm(fichier, environ={}) == {
        "actif": True, "base_url": "https://exemple.test/v1", "modele": "gpt-test",
        "cle_api": "sk-secret", "timeout": 30, "temperature": 0.2}


def test_llm_env_est_a_la_racine_du_projet():
    assert config.fichier_llm(environ={}) == RACINE / "llm.env"


def test_llm_env_designe_par_variable(tmp_path):
    cible = tmp_path / "autre.env"
    assert config.fichier_llm(environ={"LLM_ENV": str(cible)}) == cible


def test_les_variables_denvironnement_priment(tmp_path):
    fichier = ecrire(tmp_path, "llm.env", "LLM_MODELE=depuis-fichier\n")
    assert config.reglages_llm(fichier, environ={"LLM_MODELE": "depuis-env"})["modele"] == "depuis-env"


def test_valeur_vide_ignoree(tmp_path):
    fichier = ecrire(tmp_path, "llm.env", "LLM_CLE_API=\nLLM_MODELE=m\n")
    assert "cle_api" not in config.reglages_llm(fichier, environ={})


def test_les_reglages_dispo_ne_sont_pas_lus_dans_llm_env(tmp_path):
    fichier = ecrire(tmp_path, "llm.env", "DISPO_BLOCS_ACTIF=true\nLLM_MODELE=m\n")
    assert config.reglages_llm(fichier, environ={}) == {"modele": "m"}


# ---------------------------------------------------------------------------
# bmn/reglages.env (dispo)
# ---------------------------------------------------------------------------
def test_lit_les_reglages_de_dispo(tmp_path):
    fichier = ecrire(tmp_path, "reglages.env", "DISPO_TEMPLATE=template/dispo.xlsx\nDISPO_BLOCS_ACTIF=oui\n")
    dispo = config.reglages_dispo(fichier, environ={})
    assert dispo["template"] == config.BASE_DIR / "template" / "dispo.xlsx"
    assert dispo["blocs_actif"] is True


def test_chemin_de_template_absolu_conserve(tmp_path):
    fichier = ecrire(tmp_path, "reglages.env", f"DISPO_TEMPLATE={tmp_path / 'vrai.xlsx'}\n")
    assert config.reglages_dispo(fichier, environ={})["template"] == tmp_path / "vrai.xlsx"


def test_les_reglages_llm_ne_sont_pas_lus_dans_reglages_env(tmp_path):
    fichier = ecrire(tmp_path, "reglages.env", "LLM_MODELE=m\nDISPO_BLOCS_ACTIF=true\n")
    assert config.reglages_dispo(fichier, environ={}) == {"blocs_actif": True}


def test_fichier_reglages_par_defaut():
    assert config.fichier_reglages(environ={}) == config.BASE_DIR / "reglages.env"


# ---------------------------------------------------------------------------
# Désactivation (tests du site et tests Python)
# ---------------------------------------------------------------------------
def test_aucun_desactive_les_deux_fichiers():
    environ = {"BMN_REGLAGES": "aucun"}
    assert config.fichier_reglages(environ=environ) is None
    assert config.fichier_llm(environ=environ) is None


def test_fichier_absent_ne_change_rien(tmp_path):
    assert config.reglages_llm(tmp_path / "absent.env", environ={}) == {}
    assert config.reglages_dispo(None, environ={}) == {}


def test_les_tests_ignorent_les_reglages_locaux():
    """Un llm.env ou reglages.env de poste ne doit pas fausser les tests."""
    assert config.REGLAGES_FILE is None and config.LLM_FILE is None
    assert config.BLOCS["actif"] is False
    assert config.LLM["actif"] is False
    assert config.TEMPLATE_PATH == config.BASE_DIR / "template" / "template.xlsx"


# ---------------------------------------------------------------------------
# Sécurité : rien de local ne part dans git
# ---------------------------------------------------------------------------
def _ignore_par_git(chemin, dossier):
    return subprocess.run(["git", "check-ignore", "-q", chemin],
                          cwd=dossier, capture_output=True).returncode == 0


def test_fichiers_locaux_ignores_par_git():
    assert _ignore_par_git("llm.env", RACINE)
    assert _ignore_par_git("reglages.env", config.BASE_DIR)
    assert _ignore_par_git("template/dispo.xlsx", config.BASE_DIR)
    assert not _ignore_par_git("template/template.xlsx", config.BASE_DIR)
    assert not _ignore_par_git("llm.env.example", RACINE)


def test_les_modeles_de_reglage_sont_fournis():
    llm = (RACINE / "llm.env.example").read_text(encoding="utf-8")
    for cle in ("LLM_ACTIF", "LLM_BASE_URL", "LLM_MODELE", "LLM_CLE_API"):
        assert cle in llm
    dispo = (Path(config.BASE_DIR) / "reglages.env.example").read_text(encoding="utf-8")
    assert "DISPO_TEMPLATE" in dispo and "DISPO_BLOCS_ACTIF" in dispo
    assert "LLM_CLE_API" not in dispo
