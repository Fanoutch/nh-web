"""Outils de gestion de l'agent : l'agent PROPOSE, l'utilisateur confirme, le site enregistre.

Le service n'a aucun accès à la base : ces outils ne modifient rien. Ils renvoient une proposition que la boucle
transmet au site ; sans « Oui » de l'utilisateur, il ne se passe rien."""
from __future__ import annotations

from .registre import Contexte, Reponse, erreur, ok, outil

PERIODICITES = ("hebdomadaire", "mensuel", "trimestriel", "aucun")
MAX_NOM, MAX_DESCRIPTION, MAX_CONSIGNES = 100, 500, 4000


def _texte(valeur, maxi: int) -> str | None:
    if not isinstance(valeur, str) or not valeur.strip():
        return None
    return valeur.strip()[:maxi]


@outil("agent_proposer",
       "Propose à l'utilisateur un changement PERMANENT de l'agent ; rien n'est enregistré tant qu'il n'a pas confirmé. "
       "type « enregistrer » : garder cet agent tel qu'il vient d'être mis au point (nom, description, consignes). "
       "type « consignes » : remplacer ses consignes permanentes. "
       "type « rappel » : régler le rappel (periodicite : hebdomadaire, mensuel, trimestriel ou aucun).",
       {"type": "object", "properties": {
           "type": {"type": "string", "enum": ["enregistrer", "consignes", "rappel"]},
           "nom": {"type": "string"}, "description": {"type": "string"},
           "consignes": {"type": "string", "description": "Texte COMPLET des consignes permanentes"},
           "periodicite": {"type": "string", "enum": list(PERIODICITES)}}, "required": ["type"]})
def agent_proposer(ctx: Contexte, type: str, nom=None, description=None, consignes=None, periodicite=None) -> Reponse:
    suite = ("La proposition est affichée à l'utilisateur avec des boutons de confirmation. Dites-lui ce qui sera "
             "enregistré et attendez sa réponse : ne dites jamais que c'est déjà enregistré.")
    if type == "enregistrer":
        n = _texte(nom, MAX_NOM)
        if n is None:
            return erreur("Il manque le nom de l'agent.", "Redemandez-le à l'utilisateur ou proposez-en un court.")
        return ok(f"Enregistrement de l'agent « {n} » proposé.",
                  {"proposition": {"type": "enregistrer", "nom": n,
                                   "description": _texte(description, MAX_DESCRIPTION) or "",
                                   "consignes": _texte(consignes, MAX_CONSIGNES) or ""}}, suite)
    if type == "consignes":
        c = _texte(consignes, MAX_CONSIGNES)
        if c is None:
            return erreur("Il manque le texte des consignes.", "Donnez le texte complet des consignes permanentes.")
        return ok("Changement des consignes proposé.", {"proposition": {"type": "consignes", "consignes": c}}, suite)
    if type == "rappel":
        if periodicite not in PERIODICITES:
            return erreur(f"La périodicité « {periodicite} » n'existe pas.", "Choisissez : " + ", ".join(PERIODICITES) + ".")
        return ok(f"Rappel « {periodicite} » proposé.", {"proposition": {"type": "rappel", "periodicite": periodicite}}, suite)
    return erreur(f"Le type « {type} » n'existe pas.", "Types possibles : enregistrer, consignes, rappel.")


@outil("agent_historique",
       "Donne les derniers lancements de cet agent (date, mode, résultat, fichiers). Utile pour répondre à « qu'as-tu "
       "fait le mois dernier ? ».",
       {"type": "object", "properties": {"nombre": {"type": "integer", "description": "Combien de lancements (10 au plus)"}}})
def agent_historique(ctx: Contexte, nombre: int = 5) -> Reponse:
    try:
        n = max(1, min(10, int(nombre)))
    except (TypeError, ValueError):
        n = 5
    historique = ctx.donnees_agent.get("historique") or []
    if not historique:
        return ok("Cet agent n'a pas encore d'historique.", {"lancements": []})
    return ok(f"{min(n, len(historique))} lancement(s) sur {len(historique)}.", {"lancements": historique[:n]})
