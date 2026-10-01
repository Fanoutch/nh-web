import pytest
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


def test_reglages_tls_lus_dans_llm_env(tmp_path):
    llm = tmp_path / "llm.env"
    llm.write_text("LLM_CA_BUNDLE=/etc/pki/tls/certs/ca-bundle.crt\nLLM_VERIF_TLS=false\n")

    r = reglages.charger({"LLM_ENV": str(llm), "ASSISTANT_REGLAGES": str(tmp_path / "absent.env")})

    assert r.ca_bundle == "/etc/pki/tls/certs/ca-bundle.crt"
    assert r.verif_tls is False
    assert reglages.charger({"ASSISTANT_REGLAGES": "aucun"}).verif_tls is True


def test_reglages_decoupage_par_defaut():
    r = reglages.charger({"ASSISTANT_REGLAGES": "aucun"})

    assert (r.taille_morceau, r.taille_min, r.taille_max) == (600, 150, 1125)
    assert r.fusion_sections is True
    assert r.par_page is False
    assert (r.chevauchement, r.voisins) == (150, 1)


def test_reglages_decoupage_lus_dans_reglages_env(tmp_path):
    fichier = tmp_path / "reglages.env"
    fichier.write_text(
        "ASSISTANT_TAILLE_MORCEAU=1000\nASSISTANT_TAILLE_MIN=250\nASSISTANT_FUSION_SECTIONS=non\n"
        "ASSISTANT_PAR_PAGE=oui\nASSISTANT_CHEVAUCHEMENT=40\nASSISTANT_VOISINS=2\n",
        encoding="utf-8",
    )

    r = reglages.charger({"ASSISTANT_REGLAGES": str(fichier), "LLM_ENV": str(tmp_path / "absent.env")})

    assert (r.taille_morceau, r.taille_min, r.taille_max) == (1000, 250, 1875)
    assert r.fusion_sections is False
    assert r.par_page is True
    assert (r.chevauchement, r.voisins) == (40, 2)


def _env(tmp_path, **valeurs):
    return {"ASSISTANT_REGLAGES": "aucun", **{f"ASSISTANT_{k}": str(v) for k, v in valeurs.items()}}


@pytest.mark.parametrize("valeurs, variable", [
    ({"TAILLE_MORCEAU": 0}, "ASSISTANT_TAILLE_MORCEAU"),
    ({"TAILLE_MORCEAU": 200, "TAILLE_MIN": 300}, "ASSISTANT_TAILLE_MIN"),
    ({"TAILLE_MIN": -1}, "ASSISTANT_TAILLE_MIN"),
    ({"CHEVAUCHEMENT": -5}, "ASSISTANT_CHEVAUCHEMENT"),
    ({"VOISINS": -1}, "ASSISTANT_VOISINS"),
])
def test_reglages_invalides_message_francais_nommant_la_variable(tmp_path, valeurs, variable):
    with pytest.raises(ValueError) as exc:
        reglages.charger(_env(tmp_path, **valeurs))
    assert variable in str(exc.value)
    assert "doit" in str(exc.value)


def test_reglages_taille_min_trop_grande_message_exact(tmp_path):
    with pytest.raises(ValueError) as exc:
        reglages.charger(_env(tmp_path, TAILLE_MORCEAU=200, TAILLE_MIN=300))
    assert str(exc.value) == ("ASSISTANT_TAILLE_MIN (300) doit être inférieur à "
                              "ASSISTANT_TAILLE_MORCEAU (200).")


def test_reglages_valeur_non_numerique_message_francais(tmp_path):
    with pytest.raises(ValueError) as exc:
        reglages.charger(_env(tmp_path, TAILLE_MORCEAU="abc"))
    assert str(exc.value) == "ASSISTANT_TAILLE_MORCEAU doit être un nombre (valeur lue : « abc »)."


def test_reglages_mots_cles_defauts_et_lecture(tmp_path):
    r = reglages.charger({"ASSISTANT_REGLAGES": "aucun"})
    assert r.mots_cles is True and r.top_k_mots_cles == 8
    f = tmp_path / "reglages.env"
    f.write_text("ASSISTANT_MOTS_CLES=oui\nASSISTANT_TOP_K_MOTS_CLES=4\n", encoding="utf-8")
    r = reglages.charger({"ASSISTANT_REGLAGES": str(f), "LLM_ENV": str(tmp_path / "absent.env")})
    assert r.mots_cles is True and r.top_k_mots_cles == 4


def test_reglages_top_k_mots_cles_invalide(tmp_path):
    f = tmp_path / "reglages.env"
    f.write_text("ASSISTANT_TOP_K_MOTS_CLES=0\n", encoding="utf-8")
    with pytest.raises(ValueError, match="ASSISTANT_TOP_K_MOTS_CLES"):
        reglages.charger({"ASSISTANT_REGLAGES": str(f), "LLM_ENV": str(tmp_path / "absent.env")})


def test_reglages_plafond_caracteres_defaut_et_lecture(tmp_path):
    assert reglages.charger({"ASSISTANT_REGLAGES": "aucun"}).plafond_caracteres == 10000
    f = tmp_path / "reglages.env"
    f.write_text("ASSISTANT_PLAFOND_CARACTERES=0\n", encoding="utf-8")
    r = reglages.charger({"ASSISTANT_REGLAGES": str(f), "LLM_ENV": str(tmp_path / "absent.env")})
    assert r.plafond_caracteres == 0


def test_reglages_plafond_caracteres_negatif_refuse(tmp_path):
    f = tmp_path / "reglages.env"
    f.write_text("ASSISTANT_PLAFOND_CARACTERES=-5\n", encoding="utf-8")
    with pytest.raises(ValueError) as exc:
        reglages.charger({"ASSISTANT_REGLAGES": str(f), "LLM_ENV": str(tmp_path / "absent.env")})
    assert str(exc.value) == "ASSISTANT_PLAFOND_CARACTERES (-5) doit être supérieur ou égal à 0 (0 = sans plafond)."
