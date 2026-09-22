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
3. Réponds clairement, en quelques phrases, sans recopier les en-têtes des extraits et sans citer les sources : elles sont affichées à part."""

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
        pages = lecteurs.lire(chemin)
        docs = [{"source": nom, "page": p["page"], "text": p["texte"]} for p in pages]
        morceaux = [{"texte": m["text"], "page": m["page"]} for m in chunk_documents(docs)]
        if not morceaux:
            raise lecteurs.LectureImpossible("Aucun texte trouvé dans ce document.")
        vecteurs = modeles.embedder([m["texte"] for m in morceaux], self.r)
        self.base.ajouter_document(secteur, document_id, nom, morceaux, vecteurs)
        return len(morceaux)

    def supprimer(self, secteur: str, document_id: int) -> None:
        self.base.supprimer_document(secteur, document_id)

    def repondre(self, secteur: str, nom_secteur: str, question: str,
                 historique: list[dict]) -> Iterator[dict]:
        historique = historique[-2 * self.r.historique_tours:] if self.r.historique_tours > 0 else []
        try:
            if not self.r.llm_actif:
                yield from _reponse_fixe(REPONSE_DESACTIVE)
                return
            recherche = self._reformuler(question, historique) if historique else question
            vecteur = modeles.embedder([recherche], self.r)[0]
            trouves = [m for m in self.base.chercher(secteur, vecteur, self.r.top_k)
                       if m["distance"] <= self.r.seuil_distance]
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
    vues, sources = set(), []
    for m in morceaux:
        cle = (m["document_id"], m["page"])
        if cle not in vues:
            vues.add(cle)
            sources.append({"document_id": m["document_id"], "nom": m["nom"], "page": m["page"]})
    return sources


def _extraits(morceaux: list[dict]) -> str:
    blocs = []
    for i, m in enumerate(morceaux, start=1):
        page = f", page {m['page']}" if m["page"] is not None else ""
        blocs.append(f"--- Extrait {i} ({m['nom']}{page}) ---\n{m['texte']}")
    return "\n\n".join(blocs)
