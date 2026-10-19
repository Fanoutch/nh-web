"""Décodage de la réponse du LLM : demande d'outil au format standard (`tool_calls`) ou en texte
(`[TOOL_CALLS][{...}]`, format de certains modèles Mistral servis tels quels).

Trois issues : des appels, aucune demande (réponse finale), ou un texte illisible (marqueur présent, JSON cassé)."""
from __future__ import annotations

import json
from dataclasses import dataclass, field

MARQUEUR = "[TOOL_CALLS]"


@dataclass
class Appel:
    nom: str
    arguments: dict
    identifiant: str = ""


@dataclass
class Decodage:
    appels: list[Appel] = field(default_factory=list)
    texte: str = ""                 # texte de la réponse, sans la demande d'outil
    format: str = ""                # "standard" | "texte" | ""
    illisible: bool = False
    raison: str = ""


def _arguments(brut) -> dict | None:
    if brut is None or brut == "":
        return {}
    if isinstance(brut, dict):
        return brut
    if isinstance(brut, str):
        try:
            valeur = json.loads(brut)
        except json.JSONDecodeError:
            return None
        return valeur if isinstance(valeur, dict) else None
    return None


def _depuis_standard(tool_calls: list) -> Decodage:
    appels = []
    for i, tc in enumerate(tool_calls):
        fonction = tc.get("function", tc) if isinstance(tc, dict) else None
        if not isinstance(fonction, dict) or not isinstance(fonction.get("name"), str):
            return Decodage(illisible=True, format="standard", raison="Une demande d'outil n'a pas de nom.")
        arguments = _arguments(fonction.get("arguments"))
        if arguments is None:
            return Decodage(illisible=True, format="standard",
                            raison=f"Les arguments de « {fonction['name']} » ne sont pas un JSON valide.")
        appels.append(Appel(fonction["name"], arguments, str(tc.get("id") or f"appel_{i}")))
    return Decodage(appels=appels, format="standard")


def _depuis_texte(contenu: str) -> Decodage:
    debut = contenu.index(MARQUEUR)
    avant = contenu[:debut].strip()
    reste = contenu[debut + len(MARQUEUR):].lstrip()
    try:
        valeur, _ = json.JSONDecoder().raw_decode(reste)
    except json.JSONDecodeError:
        return Decodage(illisible=True, format="texte", texte=avant,
                        raison="Le texte après [TOOL_CALLS] n'est pas un JSON valide.")
    liste = [valeur] if isinstance(valeur, dict) else valeur
    if not isinstance(liste, list) or not liste:
        return Decodage(illisible=True, format="texte", texte=avant, raison="Aucune demande d'outil exploitable.")
    appels = []
    for i, element in enumerate(liste):
        if not isinstance(element, dict) or not isinstance(element.get("name"), str):
            return Decodage(illisible=True, format="texte", texte=avant, raison="Une demande d'outil n'a pas de nom.")
        arguments = _arguments(element.get("arguments", element.get("parameters")))
        if arguments is None:
            return Decodage(illisible=True, format="texte", texte=avant,
                            raison=f"Les arguments de « {element['name']} » ne sont pas un JSON valide.")
        appels.append(Appel(element["name"], arguments, f"appel_{i}"))
    return Decodage(appels=appels, texte=avant, format="texte")


def decoder(message: dict) -> Decodage:
    """`message` = le message de l'assistant renvoyé par le LLM (`content`, éventuellement `tool_calls`)."""
    contenu = message.get("content") or ""
    if message.get("tool_calls"):
        decode = _depuis_standard(message["tool_calls"])
        decode.texte = contenu.strip()
        return decode
    if MARQUEUR in contenu:
        return _depuis_texte(contenu)
    return Decodage(texte=contenu.strip())
