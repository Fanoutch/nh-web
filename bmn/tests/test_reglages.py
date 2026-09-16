"""Réglages locaux lus dans bmn/reglages.env (fichier unique, ignoré par git).

Tout ce qui change entre ce serveur et le bureau y est : template réel,
activation des blocs, connexion au LLM.
"""
import subprocess
from pathlib import Path

import config


def ecrire(tmp_path, contenu):
    fichier = tmp_path / "reglages.env"
    fichier.write_text(contenu, encoding="utf-8")
    return fichier


# ---------------------------------------------------------------------------
# Lecture
# ---------------------------------------------------------------------------
def test_lit_les_reglages_llm(tmp_path):
    fichier = ecrire(tmp_path,
        "# commentaire\n"
        "LLM_ACTIF=true\n"
        "LLM_BASE_URL=https://exemple.test/v1\n"
        "LLM_MODELE=gpt-test\n"
        'LLM_CLE_API="sk-secret"\n'
        "LLM_TIMEOUT=30\n"
        "LLM_TEMPERATURE=0.2\n")

    llm, _ = config.lire_reglages(fichier, environ={})

    assert llm == {"actif": True, "base_url": "https://exemple.test/v1",
                   "modele": "gpt-test", "cle_api": "sk-secret",
                   "timeout": 30, "temperature": 0.2}


def test_lit_les_reglages_de_dispo(tmp_path):
    fichier = ecrire(tmp_path, "DISPO_TEMPLATE=template/dispo.xlsx\nDISPO_BLOCS_ACTIF=oui\n")

    _, dispo = config.lire_reglages(fichier, environ={})

    assert dispo["template"] == config.BASE_DIR / "template" / "dispo.xlsx"   # relatif à bmn/
    assert dispo["blocs_actif"] is True


def test_chemin_de_template_absolu_conserve(tmp_path):
    fichier = ecrire(tmp_path, f"DISPO_TEMPLATE={tmp_path / 'vrai.xlsx'}\n")
    _, dispo = config.lire_reglages(fichier, environ={})
    assert dispo["template"] == tmp_path / "vrai.xlsx"


def test_fichier_absent_ne_change_rien(tmp_path):
    assert config.lire_reglages(tmp_path / "absent.env", environ={}) == ({}, {})


def test_desactivation_explicite():
    assert config.lire_reglages(None, environ={}) == ({}, {})


def test_les_variables_denvironnement_priment(tmp_path):
    fichier = ecrire(tmp_path, "LLM_MODELE=depuis-fichier\n")
    llm, _ = config.lire_reglages(fichier, environ={"LLM_MODELE": "depuis-env"})
    assert llm["modele"] == "depuis-env"


def test_valeur_vide_ignoree(tmp_path):
    fichier = ecrire(tmp_path, "LLM_CLE_API=\nLLM_MODELE=m\n")
    llm, _ = config.lire_reglages(fichier, environ={})
    assert "cle_api" not in llm


# ---------------------------------------------------------------------------
# Choix du fichier (variable BMN_REGLAGES)
# ---------------------------------------------------------------------------
def test_fichier_par_defaut():
    assert config.fichier_reglages(environ={}) == config.BASE_DIR / "reglages.env"


def test_fichier_desactive_par_variable():
    assert config.fichier_reglages(environ={"BMN_REGLAGES": "aucun"}) is None


def test_fichier_designe_par_variable(tmp_path):
    cible = tmp_path / "autre.env"
    assert config.fichier_reglages(environ={"BMN_REGLAGES": str(cible)}) == cible


# ---------------------------------------------------------------------------
# Sécurité : rien de local ne part dans git
# ---------------------------------------------------------------------------
def _ignore_par_git(chemin_relatif):
    resultat = subprocess.run(["git", "check-ignore", "-q", chemin_relatif],
                              cwd=config.BASE_DIR, capture_output=True)
    return resultat.returncode == 0


def test_reglages_et_template_reel_ignores_par_git():
    assert _ignore_par_git("reglages.env")
    assert _ignore_par_git("template/dispo.xlsx")        # template réel : données réelles
    assert not _ignore_par_git("template/template.xlsx") # template factice : versionné


def test_le_modele_de_reglage_est_fourni():
    contenu = (Path(config.BASE_DIR) / "reglages.env.example").read_text(encoding="utf-8")
    for cle in ("DISPO_TEMPLATE", "DISPO_BLOCS_ACTIF",
                "LLM_ACTIF", "LLM_BASE_URL", "LLM_MODELE", "LLM_CLE_API"):
        assert cle in contenu


def test_les_tests_ignorent_les_reglages_locaux():
    """Un reglages.env de poste (vrai template, blocs, LLM) ne doit pas fausser les tests."""
    assert config.REGLAGES_FILE is None
    assert config.BLOCS["actif"] is False
    assert config.LLM["actif"] is False
    assert config.TEMPLATE_PATH == config.BASE_DIR / "template" / "template.xlsx"
