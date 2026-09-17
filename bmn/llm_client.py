# %% [markdown]
# # Appel du LLM : extraction de l'équipement incriminé
#
# Envoie un enregistrement (une ligne du CSV) à un modèle et récupère les
# champs `equipement`, `indice`, `justification`, qui deviennent ensuite des
# colonnes `llm.*` remplissables dans le template (cf. `config.LLM`).
#
# Le format de requête est celui de l'API OpenAI (`/v1/chat/completions`),
# parlé par Ollama, LM Studio, vLLM et llama.cpp.
#
# **Si l'API du bureau est différente, une seule fonction est à réécrire :
# `appeler_modele`.** Elle reçoit les messages et la config, elle retourne le
# texte brut de la réponse. Ne pas toucher au reste ni aux noms de fonctions.
#
# Réglages de connexion (adresse, modèle, clé) : fichier `llm.env` à la RACINE de
# nh-web (partagé avec l'assistant IA), à créer depuis `llm.env.example`.
# Prompt et champs : bloc `LLM` de `config.py`.
# Vérification rapide du service :
#
#     python llm_client.py --test                 # avec le vrai modèle
#     python llm_client.py --test --hors-ligne    # sans modèle
#
# Bibliothèque standard uniquement : rien à installer.

# %%
from __future__ import annotations

import argparse
import json
import logging
import urllib.error
import urllib.request

import config

log = logging.getLogger("bmn.llm")


class LlmError(RuntimeError):
    """Le modèle n'a pas pu être interrogé (service, réseau, réponse illisible)."""


# ---------------------------------------------------------------------------
# Prompt
# ---------------------------------------------------------------------------
def construire_messages(enregistrement: dict, conf: dict) -> list[dict]:
    charge = selectionner_champs(enregistrement, conf.get("champs_envoyes") or [])
    contenu = json.dumps(charge, ensure_ascii=False, indent=2, default=str)
    return [
        {"role": "system", "content": _prompt_systeme(conf)},
        {"role": "user", "content": conf["gabarit_utilisateur"].format(enregistrement=contenu)},
    ]


def _prompt_systeme(conf: dict) -> str:
    """Prompt système, avec les exemples de libellés qui calent le style."""
    exemples = conf.get("exemples") or []
    return conf["prompt_systeme"].format(
        exemples=", ".join(f"« {e} »" for e in exemples) or "aucun")


def selectionner_champs(enregistrement: dict, champs: list) -> dict:
    """Champs envoyés au modèle. Liste vide = l'enregistrement entier."""
    if not champs:
        return dict(enregistrement)
    return {c: enregistrement.get(c) for c in champs}


# ---------------------------------------------------------------------------
# Appel du service — LA fonction à réécrire si l'API diffère
# ---------------------------------------------------------------------------
def appeler_modele(messages: list[dict], conf: dict) -> str:
    """Envoie les messages au service et retourne le texte brut de la réponse."""
    url = conf["base_url"].rstrip("/") + "/chat/completions"
    charge = {
        "model": conf["modele"],
        "messages": messages,
        "temperature": conf.get("temperature", 0),
        "stream": False,
    }
    entetes = {"Content-Type": "application/json"}
    if conf.get("cle_api"):
        entetes["Authorization"] = "Bearer " + conf["cle_api"]

    requete = urllib.request.Request(
        url, data=json.dumps(charge).encode("utf-8"), headers=entetes, method="POST"
    )
    try:
        with urllib.request.urlopen(requete, timeout=conf.get("timeout", 120)) as reponse:
            corps = json.loads(reponse.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode("utf-8", "replace")[:300]
        raise LlmError(f"{url} a répondu {exc.code} : {detail}") from exc
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise LlmError(f"Service injoignable sur {url} : {exc}") from exc
    except json.JSONDecodeError as exc:
        raise LlmError(f"Réponse non JSON de {url} : {exc}") from exc

    try:
        return corps["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise LlmError("Réponse inattendue : " + json.dumps(corps)[:300]) from exc


def reponse_factice(enregistrement: dict, conf: dict) -> str:
    """Mode hors ligne : réponse simulée, pour tester sans modèle."""
    premier = next((str(v) for v in enregistrement.values() if v not in (None, "")), "")
    return json.dumps({
        "equipement": "A_DEFINIR (hors ligne)",
        "indice": "faible",
        "justification": f"Réponse simulée à partir de « {premier[:40]} ».",
    }, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Lecture de la réponse
# ---------------------------------------------------------------------------
def extraire_json(texte: str) -> dict:
    """Récupère l'objet JSON même si le modèle l'entoure de texte ou de ```."""
    nettoye = (texte or "").strip()
    if nettoye.startswith("```"):
        morceaux = nettoye.split("```")
        if len(morceaux) > 1:
            nettoye = morceaux[1]
            if nettoye.lstrip().lower().startswith("json"):
                nettoye = nettoye.lstrip()[4:]
    debut, fin = nettoye.find("{"), nettoye.rfind("}")
    if debut == -1 or fin <= debut:
        return {"erreur": "pas de JSON dans la réponse", "brut": (texte or "")[:200]}
    try:
        return json.loads(nettoye[debut:fin + 1])
    except json.JSONDecodeError as exc:
        return {"erreur": f"JSON invalide ({exc.msg})", "brut": (texte or "")[:200]}


# ---------------------------------------------------------------------------
# Point d'entrée
# ---------------------------------------------------------------------------
def extraire_equipement(enregistrement: dict, conf: dict | None = None) -> dict:
    """Retourne les champs produits par le modèle pour un enregistrement.

    Lève `LlmError` si le service est injoignable. Une réponse illisible n'est
    pas une erreur : les champs attendus sont alors vides.
    """
    conf = conf or config.LLM
    if conf.get("hors_ligne"):
        brut = reponse_factice(selectionner_champs(enregistrement,
                                                   conf.get("champs_envoyes") or []), conf)
    else:
        brut = appeler_modele(construire_messages(enregistrement, conf), conf)

    donnees = extraire_json(brut)
    if "erreur" in donnees:
        log.warning("Réponse du modèle illisible : %s", donnees["erreur"])
    return {champ: donnees.get(champ, "") for champ in conf["champs_produits"]}


def exemple_de_test(conf: dict) -> dict:
    """Enregistrement d'exemple pour --test : une saisie HIL réaliste.

    Les champs envoyés au modèle (conf["champs_envoyes"]) sont tous renseignés,
    pour tester une vraie extraction et pas un enregistrement vide.
    """
    exemple = {
        "travail_demande": "voyant ENG ANTI ICE allumé au demarrage",
        "travail_effectue": "test syst KO defaut confirmé anti givrage moteur. "
                            "Mise en hil eng anti icing attente piece",
    }
    for champ in conf.get("champs_envoyes") or []:
        exemple.setdefault(champ, "mise en HIL PDU, jeu constaté au contrôle")
    return exemple


def main() -> int:
    p = argparse.ArgumentParser(description="Vérifie l'appel au LLM configuré.")
    p.add_argument("--test", action="store_true",
                   help="envoie un enregistrement d'exemple et affiche la réponse")
    p.add_argument("--hors-ligne", action="store_true", help="réponse factice, sans modèle")
    args = p.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(levelname)s | %(message)s")
    conf = dict(config.LLM)
    if args.hors_ligne:
        conf["hors_ligne"] = True

    if not args.test:
        p.print_help()
        return 0

    if not conf.get("hors_ligne"):
        if conf.get("modele") in (None, "", "A_DEFINIR"):
            print(f"[KO] Modèle non renseigné : remplir LLM_MODELE dans "
                  f"{config.LLM_FILE or config.BASE_DIR.parent / 'llm.env'}"
                  " (copier llm.env.example si le fichier n'existe pas).")
            return 2
        print(f"Modèle : {conf['modele']} sur {conf['base_url']}")
    exemple = exemple_de_test(conf)
    try:
        resultat = extraire_equipement(exemple, conf)
    except LlmError as exc:
        print(f"[KO] {exc}")
        return 2
    print(json.dumps(resultat, ensure_ascii=False, indent=2))
    manquants = [c for c, v in resultat.items() if v in (None, "")]
    if manquants:
        print(f"[WARN] champs vides : {manquants} — ajuster prompt_systeme dans config.py")
        return 1
    print("[OK] le modèle répond au format attendu.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
