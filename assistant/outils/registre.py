"""Registre des outils de l'agent : réponse uniforme, contexte d'exécution, appel contrôlé.

Tout outil renvoie une `Reponse` `{statut, resume, donnees, suite, artefacts}`. Un outil ne lève jamais
d'exception vers la boucle : une erreur est une réponse qui dit la cause et comment réessayer."""
from __future__ import annotations

import logging
from dataclasses import dataclass, field
from pathlib import Path
from typing import Callable

log = logging.getLogger("assistant.outils")


@dataclass
class Reponse:
    statut: str
    resume: str
    donnees: dict = field(default_factory=dict)
    suite: str = ""
    artefacts: list[dict] = field(default_factory=list)

    def en_dict(self) -> dict:
        return {"statut": self.statut, "resume": self.resume, "donnees": self.donnees,
                "suite": self.suite, "artefacts": self.artefacts}


def ok(resume: str, donnees: dict | None = None, suite: str = "", artefacts: list[dict] | None = None) -> Reponse:
    return Reponse("ok", resume, donnees or {}, suite, artefacts or [])


def erreur(cause: str, comment_reessayer: str = "") -> Reponse:
    return Reponse("erreur", cause, {}, comment_reessayer, [])


@dataclass
class Contexte:
    """Ce à quoi les outils ont droit pendant UNE exécution."""
    dossier_execution: Path          # fichiers déposés (références de l'agent + fichiers apportés)
    fichiers_autorises: list[str]    # leurs noms : rien d'autre n'est lisible
    outils_autorises: set[str]       # outils que la recette de l'agent autorise
    dossier_sortie: Path             # fichiers produits pendant l'exécution
    fichiers_produits: list[str] = field(default_factory=list)
    donnees_agent: dict = field(default_factory=dict)   # ce que le site transmet de l'agent (historique…) ; jamais de secret


REGISTRE: dict[str, tuple[Callable, dict]] = {}


def outil(nom: str, description: str, parametres: dict):
    """Déclare un outil : `fonction(contexte, **arguments) -> Reponse`."""
    def decorateur(fonction: Callable) -> Callable:
        REGISTRE[nom] = (fonction, {"name": nom, "description": description, "parameters": parametres})
        return fonction
    return decorateur


def declaration_outils(noms: set[str] | list[str]) -> list[dict]:
    """Déclaration des outils demandés, au format « fonctions » des LLM, dans l'ordre alphabétique."""
    return [{"type": "function", "function": REGISTRE[n][1]} for n in sorted(noms) if n in REGISTRE]


def executer(nom: str, arguments: dict, contexte: Contexte) -> Reponse:
    if nom not in REGISTRE:
        return erreur(f"L'outil « {nom} » n'existe pas.",
                      "Choisissez un des outils proposés : " + ", ".join(sorted(contexte.outils_autorises)) + ".")
    if nom not in contexte.outils_autorises:
        return erreur(f"L'outil « {nom} » n'est pas autorisé pour cet agent.",
                      "Choisissez un des outils proposés : " + ", ".join(sorted(contexte.outils_autorises)) + ".")
    fonction, spec = REGISTRE[nom]
    arguments = arguments if isinstance(arguments, dict) else {}
    connus = set(spec["parameters"].get("properties", {}))
    inconnus = sorted(set(arguments) - connus)
    if inconnus:
        return erreur(f"Argument inconnu pour « {nom} » : {', '.join(inconnus)}.",
                      "Arguments acceptés : " + ", ".join(sorted(connus)) + ".")
    manquants = [p for p in spec["parameters"].get("required", []) if p not in arguments]
    if manquants:
        return erreur(f"Il manque l'argument « {manquants[0]} » pour « {nom} ».",
                      "Relancez l'outil en le précisant.")
    try:
        return fonction(contexte, **arguments)
    except Exception:  # noqa: BLE001 — un outil ne doit jamais faire tomber la boucle
        log.exception("outil %s en échec", nom)
        return erreur(f"L'outil « {nom} » a rencontré un problème inattendu.",
                      "Vérifiez les arguments, ou essayez une autre approche.")
