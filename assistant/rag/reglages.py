"""Réglages de l'assistant IA.

Deux fichiers, comme pour la dispo (bmn/) :
- llm.env, à la RACINE de nh-web : connexions aux modèles (LLM partagé avec
  les HIL + embedding EMBED_*) ;
- assistant/reglages.env : service et recherche (ASSISTANT_*).

Priorité : variable d'environnement > fichier > valeur par défaut.
LLM_ENV désigne un autre llm.env, ASSISTANT_REGLAGES un autre reglages.env ;
ASSISTANT_REGLAGES=aucun ignore les deux fichiers (tests).
LLM_TEMPERATURE n'est volontairement pas lue : elle règle les HIL.
"""
from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping

DOSSIER_ASSISTANT = Path(__file__).resolve().parents[1]
RACINE_NH_WEB = DOSSIER_ASSISTANT.parent


def _booleen(valeur: str) -> bool:
    return str(valeur).strip().lower() in ("1", "true", "oui", "yes", "vrai")


def _chemin(valeur: str) -> Path:
    chemin = Path(valeur)
    return chemin if chemin.is_absolute() else (DOSSIER_ASSISTANT / chemin).resolve()


@dataclass(frozen=True)
class Reglages:
    llm_actif: bool = False
    llm_base_url: str = ""
    llm_modele: str = ""
    llm_cle_api: str = ""
    llm_timeout: int = 120
    ca_bundle: str = ""
    verif_tls: bool = True
    embed_base_url: str = ""
    embed_modele: str = "BAAI/bge-m3"
    embed_cle_api: str = ""
    embed_format: str = "openai"
    hote: str = "127.0.0.1"
    port: int = 8765
    jeton: str = ""
    chroma_dir: Path = RACINE_NH_WEB / "storage" / "app" / "assistant" / "chroma"
    top_k: int = 6
    top_k_dossier: int = 3
    top_k_pj: int = 3
    seuil_distance: float = 1.0
    temperature: float = 0.3
    historique_tours: int = 3
    taille_morceau: int = 600
    taille_min: int = 150
    fusion_sections: bool = True
    par_page: bool = False
    chevauchement: int = 150
    voisins: int = 1
    mots_cles: bool = True
    top_k_mots_cles: int = 8
    plafond_caracteres: int = 10000

    @property
    def taille_max(self) -> int:
        """Au-delà, un morceau est redécoupé (1125 pour 600)."""
        return int(self.taille_morceau * 1.875)


_CLES = {  # variable -> (champ de Reglages, conversion)
    "LLM_ACTIF": ("llm_actif", _booleen),
    "LLM_BASE_URL": ("llm_base_url", str),
    "LLM_MODELE": ("llm_modele", str),
    "LLM_CLE_API": ("llm_cle_api", str),
    "LLM_TIMEOUT": ("llm_timeout", lambda v: int(float(v))),
    "LLM_CA_BUNDLE": ("ca_bundle", str),
    "LLM_VERIF_TLS": ("verif_tls", _booleen),
    "EMBED_BASE_URL": ("embed_base_url", str),
    "EMBED_MODELE": ("embed_modele", str),
    "EMBED_CLE_API": ("embed_cle_api", str),
    "EMBED_FORMAT": ("embed_format", lambda v: v.strip().lower()),
    "ASSISTANT_HOTE": ("hote", str),
    "ASSISTANT_PORT": ("port", int),
    "ASSISTANT_JETON": ("jeton", str),
    "ASSISTANT_CHROMA_DIR": ("chroma_dir", _chemin),
    "ASSISTANT_TOP_K": ("top_k", int),
    "ASSISTANT_TOP_K_DOSSIER": ("top_k_dossier", int),
    "ASSISTANT_TOP_K_PJ": ("top_k_pj", int),
    "ASSISTANT_SEUIL_DISTANCE": ("seuil_distance", float),
    "ASSISTANT_TEMPERATURE": ("temperature", float),
    "ASSISTANT_HISTORIQUE_TOURS": ("historique_tours", int),
    "ASSISTANT_TAILLE_MORCEAU": ("taille_morceau", int),
    "ASSISTANT_TAILLE_MIN": ("taille_min", int),
    "ASSISTANT_FUSION_SECTIONS": ("fusion_sections", _booleen),
    "ASSISTANT_PAR_PAGE": ("par_page", _booleen),
    "ASSISTANT_CHEVAUCHEMENT": ("chevauchement", int),
    "ASSISTANT_VOISINS": ("voisins", int),
    "ASSISTANT_MOTS_CLES": ("mots_cles", _booleen),
    "ASSISTANT_TOP_K_MOTS_CLES": ("top_k_mots_cles", int),
    "ASSISTANT_PLAFOND_CARACTERES": ("plafond_caracteres", int),
}


def lire_fichier_env(chemin: Path | None) -> dict[str, str]:
    """CLE=valeur par ligne ; # commentaires ; guillemets et « # fin de ligne » retirés."""
    brut: dict[str, str] = {}
    if chemin is None or not chemin.is_file():
        return brut
    for ligne in chemin.read_text(encoding="utf-8-sig").splitlines():
        ligne = ligne.strip()
        if not ligne or ligne.startswith("#") or "=" not in ligne:
            continue
        cle, valeur = ligne.split("=", 1)
        valeur = valeur.split(" #", 1)[0]
        brut[cle.strip()] = valeur.strip().strip('"').strip("'")
    return brut


def fichiers(environ: Mapping[str, str]) -> tuple[Path | None, Path | None]:
    """(llm.env, reglages.env) à lire, ou (None, None) si ASSISTANT_REGLAGES=aucun."""
    choix = (environ.get("ASSISTANT_REGLAGES") or "").strip()
    if choix.lower() in ("aucun", "none", "0"):
        return None, None
    llm = (environ.get("LLM_ENV") or "").strip()
    return (
        Path(llm) if llm else RACINE_NH_WEB / "llm.env",
        Path(choix) if choix else DOSSIER_ASSISTANT / "reglages.env",
    )


def charger(environ: Mapping[str, str] | None = None) -> Reglages:
    env = os.environ if environ is None else environ
    llm_env, reglages_env = fichiers(env)
    brut = {**lire_fichier_env(llm_env), **lire_fichier_env(reglages_env)}
    for cle in _CLES:
        if env.get(cle):
            brut[cle] = env[cle]
    valeurs = {}
    for cle, (champ, conversion) in _CLES.items():
        if brut.get(cle, "") == "":
            continue
        try:
            valeurs[champ] = conversion(brut[cle])
        except ValueError:
            raise ValueError(f"{cle} doit être un nombre (valeur lue : « {brut[cle]} »).") from None
    r = Reglages(**valeurs)
    _verifier(r)
    return r


def _verifier(r: Reglages) -> None:
    """Refuse des tailles de découpage incohérentes, avec un message qui nomme la variable."""
    if r.taille_morceau <= 0:
        raise ValueError(f"ASSISTANT_TAILLE_MORCEAU ({r.taille_morceau}) doit être supérieur à 0.")
    if r.taille_min < 0:
        raise ValueError(f"ASSISTANT_TAILLE_MIN ({r.taille_min}) doit être supérieur ou égal à 0.")
    if r.taille_min >= r.taille_morceau:
        raise ValueError(f"ASSISTANT_TAILLE_MIN ({r.taille_min}) doit être inférieur à "
                         f"ASSISTANT_TAILLE_MORCEAU ({r.taille_morceau}).")
    if r.chevauchement < 0:
        raise ValueError(f"ASSISTANT_CHEVAUCHEMENT ({r.chevauchement}) doit être supérieur ou égal à 0.")
    if r.voisins < 0:
        raise ValueError(f"ASSISTANT_VOISINS ({r.voisins}) doit être supérieur ou égal à 0.")
    if r.top_k_mots_cles < 1:
        raise ValueError(f"ASSISTANT_TOP_K_MOTS_CLES ({r.top_k_mots_cles}) doit être supérieur ou égal à 1.")
    if r.plafond_caracteres < 0:
        raise ValueError(f"ASSISTANT_PLAFOND_CARACTERES ({r.plafond_caracteres}) doit être supérieur ou égal "
                         f"à 0 (0 = sans plafond).")
