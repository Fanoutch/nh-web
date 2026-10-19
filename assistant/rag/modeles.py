"""Appels aux modèles : embedding (bge-m3) et LLM (API chat OpenAI-compatible).

Connexions lues dans llm.env (voir rag.reglages). Le LLM est le même que
celui des HIL de la dispo.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Iterator

import requests

from rag.reglages import Reglages

TAILLE_LOT_EMBEDDING = 32


class ModeleIndisponible(Exception):
    """Le modèle n'a pas pu être interrogé (configuration, réseau, délai, réponse)."""


def _entetes(cle: str) -> dict:
    entetes = {"Content-Type": "application/json"}
    if cle:
        entetes["Authorization"] = f"Bearer {cle}"
    return entetes


def _verification(r: Reglages):
    """Vérification TLS des appels (LLM et embedding), comme pour les HIL de la dispo.

    requests n'utilise pas les autorités du système : un service interne à certificat
    maison échoue (CERTIFICATE_VERIFY_FAILED). Réglages dans llm.env : LLM_CA_BUNDLE
    (fichier d'autorités à croire, recommandé) ou LLM_VERIF_TLS=false (plus de contrôle).
    """
    autorite = r.ca_bundle.strip()
    if autorite:
        if not Path(autorite).is_file():
            raise ModeleIndisponible(f"Autorité de certification introuvable : {autorite} "
                                     "(réglage LLM_CA_BUNDLE de llm.env)")
        return autorite
    return r.verif_tls


def _post(url: str, cle: str, charge: dict, r: Reglages, stream: bool = False):
    verification = _verification(r)
    try:
        reponse = requests.post(url, headers=_entetes(cle), json=charge, timeout=r.llm_timeout,
                                stream=stream, verify=verification)
    except requests.RequestException as exc:
        raise ModeleIndisponible(f"Service injoignable sur {url} : {exc}") from exc
    if not reponse.ok:
        raise ModeleIndisponible(f"{url} a répondu {reponse.status_code} : {reponse.text[:300]}")
    return reponse


def _json(reponse, url: str):
    """Parse JSON response, converting parse errors to ModeleIndisponible."""
    try:
        return reponse.json()
    except ValueError as exc:
        raise ModeleIndisponible(f"Réponse non JSON de {url} : {exc}") from exc


def _embedder_lot(textes: list[str], r: Reglages) -> list[list[float]]:
    if r.embed_format == "hf":
        url, charge = r.embed_base_url, {"inputs": textes}
    else:
        url, charge = r.embed_base_url.rstrip("/") + "/embeddings", {"input": textes, "model": r.embed_modele}
    donnees = _json(_post(url, r.embed_cle_api, charge, r), url)
    try:
        if r.embed_format == "hf":
            if not isinstance(donnees, list):
                raise TypeError("HF format response must be a list")
            vecteurs = donnees
        else:
            data = donnees["data"]
            if all(isinstance(d, dict) and "index" in d for d in data):
                data = sorted(data, key=lambda d: d["index"])  # l'ordre de la liste n'est pas garanti
            vecteurs = [d["embedding"] for d in data]
    except (KeyError, TypeError) as exc:
        raise ModeleIndisponible("Réponse d'embedding inattendue : " + json.dumps(donnees)[:300]) from exc
    if len(vecteurs) != len(textes):
        raise ModeleIndisponible(f"{len(vecteurs)} vecteurs reçus pour {len(textes)} textes")
    return vecteurs


def embedder(textes: list[str], r: Reglages) -> list[list[float]]:
    """Un vecteur par texte, dans l'ordre. Envoi par lots de 32."""
    if not textes:
        return []
    if not r.embed_base_url:
        raise ModeleIndisponible("EMBED_BASE_URL manquant dans llm.env")
    if r.embed_format not in ("hf", "openai"):
        raise ModeleIndisponible(f"EMBED_FORMAT inconnu : « {r.embed_format} » (attendu : hf ou openai)")
    vecteurs: list[list[float]] = []
    for debut in range(0, len(textes), TAILLE_LOT_EMBEDDING):
        vecteurs.extend(_embedder_lot(textes[debut:debut + TAILLE_LOT_EMBEDDING], r))
    return vecteurs


def _url_chat(r: Reglages) -> str:
    if not r.llm_base_url or not r.llm_modele:
        raise ModeleIndisponible("LLM_BASE_URL ou LLM_MODELE manquant dans llm.env")
    return r.llm_base_url.rstrip("/") + "/chat/completions"


def _charge_chat(messages: list[dict], r: Reglages, temperature: float, flux: bool) -> dict:
    return {"model": r.llm_modele, "messages": messages, "temperature": temperature, "stream": flux}


def discuter(messages: list[dict], r: Reglages, temperature: float) -> str:
    url = _url_chat(r)
    donnees = _json(_post(url, r.llm_cle_api, _charge_chat(messages, r, temperature, False), r), url)
    try:
        return donnees["choices"][0]["message"]["content"] or ""
    except (KeyError, IndexError, TypeError) as exc:
        raise ModeleIndisponible("Réponse du LLM inattendue : " + json.dumps(donnees)[:300]) from exc


def discuter_avec_outils(messages: list[dict], outils: list[dict], r: Reglages, temperature: float) -> dict:
    """Un tour d'agent : renvoie le message de l'assistant tel quel (`content`, éventuellement `tool_calls`)."""
    url = _url_chat(r)
    charge = _charge_chat(messages, r, temperature, False)
    if outils:
        charge["tools"] = outils
    donnees = _json(_post(url, r.llm_cle_api, charge, r), url)
    try:
        message = donnees["choices"][0]["message"]
    except (KeyError, IndexError, TypeError) as exc:
        raise ModeleIndisponible("Réponse du LLM inattendue : " + json.dumps(donnees)[:300]) from exc
    return message if isinstance(message, dict) else {"content": str(message)}


def discuter_en_flux(messages: list[dict], r: Reglages, temperature: float) -> Iterator[str]:
    """Fragments de texte au fil de l'eau (format « data: {...} » d'OpenAI)."""
    reponse = _post(_url_chat(r), r.llm_cle_api, _charge_chat(messages, r, temperature, True),
                    r, stream=True)
    reponse.encoding = "utf-8"
    try:
        for ligne in reponse.iter_lines(decode_unicode=True):
            if not ligne or not ligne.startswith("data:"):
                continue
            donnee = ligne[len("data:"):].strip()
            if donnee == "[DONE]":
                break
            try:
                fragment = json.loads(donnee)["choices"][0].get("delta", {}).get("content")
            except (json.JSONDecodeError, KeyError, IndexError, TypeError, AttributeError):
                continue
            if fragment:
                yield fragment
    except requests.RequestException as exc:
        raise ModeleIndisponible(f"Réponse du LLM interrompue : {exc}") from exc
    finally:
        reponse.close()
