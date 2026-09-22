"""Cœur de l'assistant : indexer un document, répondre à une question.

La réponse est une suite d'événements (sources, fragments de texte, fin ou
erreur) que le service transmet tels quels à Laravel.
"""
from __future__ import annotations

from typing import Iterator

from rag import lecteurs, modeles
from rag.base import BaseVectorielle
from rag.decoupage import chunk_documents
from rag.reglages import Reglages

PROMPT_SYSTEME = """Tu es l'assistant IA du secteur {secteur}. Tu réponds en français, uniquement à partir des extraits de documents fournis avec la question.
Règles :
1. Appuie-toi seulement sur ces extraits ; n'utilise jamais tes connaissances générales.
2. Si la réponse n'est pas dans les extraits, réponds exactement : « Je ne trouve pas cette information dans les documents du secteur {secteur}. »
3. Réponds clairement, en quelques phrases, sans recopier les en-têtes des extraits et sans citer les sources : elles sont affichées à part.
4. Structure ta réponse en Markdown simple quand c'est utile : listes à puces ou numérotées, **gras** pour les points importants. Pas de titres, pas de tableaux."""

PROMPT_QUESTION = """EXTRAITS :
{extraits}

QUESTION : {question}"""

PROMPT_REFORMULATION = """Réécris la dernière question pour qu'elle soit compréhensible seule, sans l'historique. Garde le même sens et les mêmes termes techniques. Réponds uniquement par la question réécrite.

HISTORIQUE :
{historique}

DERNIÈRE QUESTION : {question}"""

REPONSE_INTROUVABLE = "Je ne trouve pas cette information dans les documents du secteur {secteur}."
REPONSE_DESACTIVE = "L'assistant IA est désactivé (LLM_ACTIF=false dans llm.env)."
MESSAGE_INDISPONIBLE = "L'assistant est indisponible pour le moment, réessayez plus tard."


class Assistant:
    def __init__(self, reglages: Reglages, base: BaseVectorielle):
        self.r = reglages
        self.base = base

    def indexer(self, secteur: str, document_id: int, chemin: str, nom: str) -> int:
        morceaux = self._decouper(chemin, nom)
        vecteurs = modeles.embedder([m["texte"] for m in morceaux], self.r)
        self.base.ajouter_document(secteur, document_id, nom, morceaux, vecteurs)
        return len(morceaux)

    def supprimer(self, secteur: str, document_id: int) -> None:
        self.base.supprimer_document(secteur, document_id)

    def indexer_piece_jointe(self, conversation_id: int, piece_id: int, chemin: str, nom: str) -> int:
        morceaux = self._decouper(chemin, nom)
        vecteurs = modeles.embedder([m["texte"] for m in morceaux], self.r)
        self.base.ajouter_piece_jointe(conversation_id, piece_id, nom, morceaux, vecteurs)
        return len(morceaux)

    def supprimer_piece_jointe(self, conversation_id: int, piece_id: int) -> None:
        self.base.supprimer_piece_jointe(conversation_id, piece_id)

    def supprimer_conversation(self, conversation_id: int) -> None:
        self.base.supprimer_conversation(conversation_id)

    def _decouper(self, chemin: str, nom: str) -> list[dict]:
        pages = lecteurs.lire(chemin)
        docs = [{"source": nom, "page": p["page"], "text": p["texte"]} for p in pages]
        morceaux = [{"texte": m["text"], "page": m["page"]} for m in chunk_documents(docs)]
        if not morceaux:
            raise lecteurs.LectureImpossible("Aucun texte trouvé dans ce document.")
        return morceaux

    def repondre(self, secteur: str, nom_secteur: str, question: str, historique: list[dict],
                 documents_prioritaires: list[int] | None = None,
                 conversation: int | None = None) -> Iterator[dict]:
        historique = historique[-2 * self.r.historique_tours:] if self.r.historique_tours > 0 else []
        try:
            if not self.r.llm_actif:
                yield from _reponse_fixe(REPONSE_DESACTIVE)
                return
            recherche = self._reformuler(question, historique) if historique else question
            vecteur = modeles.embedder([recherche], self.r)[0]
            trouves = self._rassembler(secteur, vecteur, documents_prioritaires, conversation)
            if not trouves:
                yield from _reponse_fixe(REPONSE_INTROUVABLE.format(secteur=nom_secteur))
                return
            yield {"type": "sources", "sources": _sources(trouves)}
            messages = [
                {"role": "system", "content": PROMPT_SYSTEME.format(secteur=nom_secteur)},
                *historique,
                {"role": "user", "content": PROMPT_QUESTION.format(extraits=_extraits(trouves), question=question)},
            ]
            for fragment in modeles.discuter_en_flux(messages, self.r, self.r.temperature):
                yield {"type": "texte", "texte": fragment}
            yield {"type": "fin"}
        except modeles.ModeleIndisponible as exc:
            yield {"type": "erreur", "message": MESSAGE_INDISPONIBLE, "detail": str(exc)}
        except Exception as exc:
            yield {"type": "erreur", "message": MESSAGE_INDISPONIBLE,
                   "detail": f"{type(exc).__name__}: {exc}"}

    def _rassembler(self, secteur: str, vecteur: list[float], documents_prioritaires: list[int] | None,
                     conversation: int | None) -> list[dict]:
        """Recherche fusionnée : la recherche principale porte toujours sur tout le
        secteur ; les documents prioritaires et la pièce jointe de conversation ne
        font qu'AJOUTER des passages, jamais restreindre la recherche principale.
        Dédoublonnage sur `id` (identifiant ChromaDB du morceau), pas sur
        `(document_id, page)` : deux morceaux différents d'une même page peuvent
        coexister ici (le dédoublonnage par page reste celui de `_sources()`).
        """
        trouves = self.base.chercher(secteur, vecteur, self.r.top_k)
        if documents_prioritaires:
            trouves += self.base.chercher(secteur, vecteur, self.r.top_k_dossier,
                                          document_ids=documents_prioritaires)
        if conversation is not None:
            trouves += self.base.chercher_conversation(conversation, vecteur, self.r.top_k_pj)
        vus, fusion = set(), []
        for m in sorted(trouves, key=lambda m: m["distance"]):
            if m["id"] in vus or m["distance"] > self.r.seuil_distance:
                continue
            vus.add(m["id"])
            fusion.append(m)
        return fusion[:self.r.top_k + self.r.top_k_dossier + self.r.top_k_pj]

    def _reformuler(self, question: str, historique: list[dict]) -> str:
        texte = "\n".join(
            f"{'Utilisateur' if m['role'] == 'user' else 'Assistant'} : {m['content']}" for m in historique
        )
        prompt = PROMPT_REFORMULATION.format(historique=texte, question=question)
        reformulee = modeles.discuter([{"role": "user", "content": prompt}], self.r, 0).strip()
        return reformulee or question


def _reponse_fixe(texte: str) -> Iterator[dict]:
    yield {"type": "sources", "sources": []}
    yield {"type": "texte", "texte": texte}
    yield {"type": "fin"}


def _sources(morceaux: list[dict]) -> list[dict]:
    """Une source par morceau cité, dédoublonnée sur `(document_id, page)`. Les pièces jointes ont
    toutes `document_id = None` : sans le nom dans la clé, deux pièces jointes différentes citées à
    la même page s'écraseraient l'une l'autre alors qu'elles ont toutes deux servi — on ajoute donc
    le nom à la clé dans ce cas (jamais nécessaire pour un document du secteur, dont l'identifiant
    suffit déjà à distinguer deux documents)."""
    vues, sources = set(), []
    for m in morceaux:
        cle = (m["document_id"], m["page"], m["nom"]) if m.get("pj", False) else (m["document_id"], m["page"])
        if cle not in vues:
            vues.add(cle)
            sources.append({
                "document_id": m["document_id"], "nom": m["nom"], "page": m["page"],
                "extrait": _couper(m["texte"], 600), "pj": m.get("pj", False),
            })
    return sources


def _couper(texte: str, longueur: int) -> str:
    """Coupe `texte` sur la dernière espace avant `longueur` caractères et ajoute « … »."""
    if len(texte) <= longueur:
        return texte
    coupe = texte[:longueur]
    espace = coupe.rfind(" ")
    if espace > 0:
        coupe = coupe[:espace]
    return coupe + "…"


def _extraits(morceaux: list[dict]) -> str:
    blocs = []
    for i, m in enumerate(morceaux, start=1):
        page = f", page {m['page']}" if m["page"] is not None else ""
        blocs.append(f"--- Extrait {i} ({m['nom']}{page}) ---\n{m['texte']}")
    return "\n\n".join(blocs)
