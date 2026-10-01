"""Cœur de l'assistant : indexer un document, répondre à une question.

La réponse est une suite d'événements (sources, fragments de texte, fin ou
erreur) que le service transmet tels quels à Laravel.
"""
from __future__ import annotations

import logging
from typing import Iterator

from rag import lecteurs, modeles
from rag.base import BaseVectorielle
from rag.decoupage import chunk_documents
from rag.motscles import _ascii, _MOT, mots
from rag.reglages import Reglages

log = logging.getLogger("assistant")
RRF_K = 60  # constante de la fusion par rang (RRF)

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
        """Découpe le document selon les réglages (par page ou document entier)."""
        pages = lecteurs.lire(chemin)
        if self.r.par_page:
            docs = [{"source": nom, "page": p["page"], "text": p["texte"]} for p in pages]
            reperes = None
        else:
            texte, reperes = _pages_bout_a_bout(pages)
            docs = [{"source": nom, "page": reperes[0][1] if reperes else None, "text": texte}]
        morceaux = [
            {"texte": m["text"], "page": _page_a(reperes, m["debut"]) if reperes else m["page"]}
            for m in chunk_documents(
                docs, target_size=self.r.taille_morceau, min_size=self.r.taille_min,
                max_size=self.r.taille_max, fusion_sections=self.r.fusion_sections,
                chevauchement=self.r.chevauchement,
            )
        ]
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
            trouves = self.passages(secteur, recherche, documents_prioritaires, conversation)
            if not trouves:
                yield from _reponse_fixe(REPONSE_INTROUVABLE.format(secteur=nom_secteur))
                return
            yield {"type": "sources", "sources": _sources([m for m in trouves if not m.get("voisin")])}
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

    def _rassembler(self, secteur: str, question: str, vecteur: list[float],
                    documents_prioritaires: list[int] | None, conversation: int | None) -> list[dict]:
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
        limite = self.r.top_k + self.r.top_k_dossier + self.r.top_k_pj
        if not self.r.mots_cles:
            return fusion[:limite]
        try:
            par_mots = self.base.chercher_mots_cles(secteur, question, self.r.top_k_mots_cles)
            if conversation is not None:
                par_mots += self.base.chercher_mots_cles_conversation(conversation, question, self.r.top_k_mots_cles)
            rares = self.base.mots_rares(secteur, question)
        except Exception as exc:
            log.warning("recherche par mots-clés indisponible, recherche vectorielle seule : %s", exc)
            return fusion[:limite]
        return _fusionner(fusion, par_mots, rares)[:limite]

    def passages(self, secteur: str, question: str, documents_prioritaires: list[int] | None = None,
                 conversation: int | None = None) -> list[dict]:
        """Morceaux transmis au LLM pour cette question (utilisé aussi par le banc d'évaluation)."""
        vecteur = modeles.embedder([question], self.r)[0]
        trouves = self._rassembler(secteur, question, vecteur, documents_prioritaires, conversation)
        return self._avec_voisins(secteur, trouves, conversation)

    def _avec_voisins(self, secteur: str, trouves: list[dict], conversation: int | None) -> list[dict]:
        """Ajoute autour de chaque morceau retenu ses voisins du même document, dans l'ordre du document.

        `vus` contient dès le départ tous les morceaux retrouvés : l'un d'eux n'est jamais ajouté
        une seconde fois comme voisin d'un autre, il reste à sa place."""
        if self.r.voisins <= 0 or not trouves:
            return trouves
        vus, resultat = {m["id"] for m in trouves}, []
        for m in trouves:
            if m.get("pj"):
                avant, apres = self.base.voisins_conversation(conversation, m["id"], self.r.voisins)
            else:
                avant, apres = self.base.voisins(secteur, m["id"], self.r.voisins)
            for voisin in avant:
                if voisin["id"] not in vus:
                    vus.add(voisin["id"])
                    resultat.append({**voisin, "voisin": True, "distance": m["distance"],
                                     **({"origine": m["origine"]} if "origine" in m else {})})
            resultat.append(m)
            for voisin in apres:
                if voisin["id"] not in vus:
                    vus.add(voisin["id"])
                    resultat.append({**voisin, "voisin": True, "distance": m["distance"],
                                     **({"origine": m["origine"]} if "origine" in m else {})})
        return resultat

    def _reformuler(self, question: str, historique: list[dict]) -> str:
        texte = "\n".join(
            f"{'Utilisateur' if m['role'] == 'user' else 'Assistant'} : {m['content']}" for m in historique
        )
        prompt = PROMPT_REFORMULATION.format(historique=texte, question=question)
        reformulee = modeles.discuter([{"role": "user", "content": prompt}], self.r, 0).strip()
        return reformulee or question


def _mots_du_morceau(texte: str) -> set[str]:
    """Mots d'un morceau pour le garde-fou : ceux de l'index, plus les mots collés tels qu'écrits
    (« HYD112 » sans tiret n'est indexé qu'en « hyd » + « 112 », mais reste un code à reconnaître)."""
    return set(mots(texte)) | set(_MOT.findall(_ascii(texte)))


def _fusionner(vecteurs: list[dict], par_mots: list[dict], rares: set[str]) -> list[dict]:
    """Fusion par rang (RRF). Un morceau trouvé seulement par mots-clés n'est gardé que s'il
    contient un mot rare de la question (code, sigle) : protège les refus des questions sans réponse."""
    scores: dict[str, float] = {}
    morceaux: dict[str, dict] = {}
    origines: dict[str, set[str]] = {}
    for rang, m in enumerate(vecteurs, start=1):
        scores[m["id"]] = scores.get(m["id"], 0.0) + 1 / (RRF_K + rang)
        morceaux.setdefault(m["id"], m)
        origines.setdefault(m["id"], set()).add("vecteurs")
    for rang, m in enumerate(par_mots, start=1):
        if m["id"] not in morceaux and not (_mots_du_morceau(m["texte"]) & rares):
            continue
        scores[m["id"]] = scores.get(m["id"], 0.0) + 1 / (RRF_K + rang)
        morceaux.setdefault(m["id"], m)
        origines.setdefault(m["id"], set()).add("mots_cles")
    ordre = sorted(scores, key=lambda i: (-scores[i], i))
    return [{**morceaux[i], "origine": "les_deux" if len(origines[i]) == 2 else next(iter(origines[i]))}
            for i in ordre]


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


def _pages_bout_a_bout(pages: list[dict]) -> tuple[str, list[tuple[int, int | None]]]:
    """Met les pages bout à bout (séparées par une ligne vide) et note où commence chacune."""
    morceaux, reperes, position = [], [], 0
    for p in pages:
        reperes.append((position, p["page"]))
        morceaux.append(p["texte"])
        position += len(p["texte"]) + 2
    return "\n\n".join(morceaux), reperes


def _page_a(reperes: list[tuple[int, int | None]], position: int) -> int | None:
    """Numéro de la page qui contient `position` dans le texte mis bout à bout."""
    page = reperes[0][1]
    for debut, numero in reperes:
        if debut > position:
            break
        page = numero
    return page
