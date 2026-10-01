"""Base vectorielle ChromaDB : une collection par secteur, morceaux tagués par document.

Un seul processus (le service) ouvre la base. Les écritures sont sérialisées
par un verrou ; les recherches se font en parallèle.
"""
from __future__ import annotations

import re
import threading
from pathlib import Path

import chromadb
from chromadb.config import Settings

from rag.motscles import IndexMotsCles


class BaseVectorielle:
    def __init__(self, dossier: Path):
        Path(dossier).mkdir(parents=True, exist_ok=True)
        # Réseau fermé : aucune télémétrie envoyée par ChromaDB.
        self._client = chromadb.PersistentClient(path=str(dossier), settings=Settings(anonymized_telemetry=False))
        self._verrou = threading.RLock()
        self._collections: dict[str, chromadb.Collection] = {}
        self._index: dict[str, IndexMotsCles] = {}   # index de mots-clés (BM25), construit au premier usage

    def _collection(self, secteur: str):
        # Clé de cache = nom réel de la collection ChromaDB (et non le slug de secteur seul), pour ne
        # jamais collisionner avec une collection de conversation (`conv_{id}`) : un secteur nommé
        # p. ex. "conv_7" utilise ici la clé "secteur_conv_7", distincte de "conv_7".
        nom = f"secteur_{secteur}"
        if nom in self._collections:
            return self._collections[nom]
        with self._verrou:
            if nom in self._collections:
                return self._collections[nom]
            # Les vecteurs viennent toujours de nos modèles : pas de fonction d'embedding ChromaDB (pas de téléchargement).
            collection = self._client.get_or_create_collection(name=nom, embedding_function=None)
            self._collections[nom] = collection
            return collection

    def _collection_conversation(self, conversation_id: int, creer: bool):
        """Collection ChromaDB des pièces jointes d'une conversation (`conv_{id}`).

        Si `creer` est vrai, la collection est créée si besoin et mise en cache
        (elle sera réutilisée par les prochains appels, y compris en lecture).
        Sinon, on ne consulte que le cache puis `get_collection` : on ne crée ni
        ne met rien en cache pour une collection qu'on n'a pas nous-même créée,
        et une absence (ValueError ou exception propre à la version de ChromaDB)
        renvoie simplement None.
        """
        nom = f"conv_{conversation_id}"
        if nom in self._collections:
            return self._collections[nom]
        if not creer:
            try:
                return self._client.get_collection(name=nom, embedding_function=None)
            except Exception:
                return None
        with self._verrou:
            if nom in self._collections:
                return self._collections[nom]
            collection = self._client.get_or_create_collection(name=nom, embedding_function=None)
            self._collections[nom] = collection
            return collection

    def ajouter_document(self, secteur: str, document_id: int, nom: str,
                         morceaux: list[dict], vecteurs: list[list[float]]) -> None:
        if len(morceaux) != len(vecteurs):
            raise ValueError("autant de vecteurs que de morceaux attendus")
        with self._verrou:
            collection = self._collection(secteur)
            collection.delete(where={"document_id": document_id})
            nom_index, prefixe = f"secteur_{secteur}", f"doc{document_id}_m"
            self._maj_index(nom_index, prefixe, [], [])   # avant le retour anticipé : pas de morceau fantôme
            if not morceaux:
                return
            ids = [f"{prefixe}{i}" for i in range(len(morceaux))]
            textes = [m["texte"] for m in morceaux]
            collection.add(
                ids=ids,
                embeddings=vecteurs,
                documents=textes,
                metadatas=[
                    {"document_id": document_id, "nom": nom,
                     "page": m["page"] if m["page"] is not None else -1}
                    for m in morceaux
                ],
            )
            self._maj_index(nom_index, prefixe, ids, textes)

    def supprimer_document(self, secteur: str, document_id: int) -> None:
        with self._verrou:
            self._collection(secteur).delete(where={"document_id": document_id})
            self._maj_index(f"secteur_{secteur}", f"doc{document_id}_m", [], [])

    def chercher(self, secteur: str, vecteur: list[float], top_k: int,
                 document_ids: list[int] | None = None) -> list[dict]:
        collection = self._collection(secteur)
        total = collection.count()
        if total == 0:
            return []
        # Une liste vide équivaut à l'absence de filtre : filtrer sur "aucun document" n'aurait pas de sens.
        where = {"document_id": {"$in": document_ids}} if document_ids else None
        resultat = collection.query(
            query_embeddings=[vecteur],
            n_results=max(1, min(top_k, total)),
            where=where,
        )
        return [
            {
                "id": id_,
                "document_id": meta["document_id"],
                "nom": meta["nom"],
                "page": None if meta["page"] == -1 else meta["page"],
                "texte": texte,
                "distance": distance,
                "pj": False,
            }
            for id_, meta, texte, distance in zip(
                resultat["ids"][0], resultat["metadatas"][0], resultat["documents"][0], resultat["distances"][0]
            )
        ]

    _ID_MORCEAU = re.compile(r"^(doc|pj)(\d+)_m(\d+)$")

    def _ids_voisins(self, morceau_id: str, n: int) -> tuple[list[str], list[str]]:
        """Identifiants des n morceaux avant / après : l'identifiant porte l'ordre (`doc7_m3`)."""
        m = self._ID_MORCEAU.match(morceau_id)
        if n <= 0 or m is None:
            return [], []
        prefixe, rang = f"{m.group(1)}{m.group(2)}_m", int(m.group(3))
        avant = [f"{prefixe}{i}" for i in range(max(0, rang - n), rang)]
        apres = [f"{prefixe}{i}" for i in range(rang + 1, rang + n + 1)]
        return avant, apres

    @staticmethod
    def _lire_ids(collection, ids: list[str], pj: bool) -> list[dict]:
        if collection is None or not ids:
            return []
        trouves = collection.get(ids=ids, include=["documents", "metadatas"])
        par_id = {
            id_: {
                "id": id_,
                "document_id": None if pj else meta["document_id"],
                "nom": meta["nom"],
                "page": None if meta["page"] == -1 else meta["page"],
                "texte": texte,
                "distance": None,
                "pj": pj,
            }
            for id_, meta, texte in zip(trouves["ids"], trouves["metadatas"], trouves["documents"])
        }
        return [par_id[i] for i in ids if i in par_id]   # ordre du document, absents ignorés

    def voisins(self, secteur: str, morceau_id: str, n: int) -> tuple[list[dict], list[dict]]:
        """Morceaux (avant, après) voisins d'un morceau du secteur, dans l'ordre du document."""
        avant, apres = self._ids_voisins(morceau_id, n)
        if not avant and not apres:
            return [], []
        collection = self._collection(secteur)
        return self._lire_ids(collection, avant, False), self._lire_ids(collection, apres, False)

    def voisins_conversation(self, conversation_id: int, morceau_id: str, n: int) -> tuple[list[dict], list[dict]]:
        """Idem `voisins`, pour une pièce jointe de conversation."""
        avant, apres = self._ids_voisins(morceau_id, n)
        if not avant and not apres:
            return [], []
        collection = self._collection_conversation(conversation_id, creer=False)
        return self._lire_ids(collection, avant, True), self._lire_ids(collection, apres, True)

    def nb_morceaux(self, secteur: str, document_id: int) -> int:
        return len(self._collection(secteur).get(where={"document_id": document_id})["ids"])

    def ajouter_piece_jointe(self, conversation_id: int, piece_id: int, nom: str,
                             morceaux: list[dict], vecteurs: list[list[float]]) -> None:
        """Indexe une pièce jointe de conversation (même logique que ajouter_document,
        mais dans la collection de conversation `conv_{conversation_id}`)."""
        if len(morceaux) != len(vecteurs):
            raise ValueError("autant de vecteurs que de morceaux attendus")
        with self._verrou:
            collection = self._collection_conversation(conversation_id, creer=True)
            collection.delete(where={"piece_id": piece_id})
            nom_index, prefixe = f"conv_{conversation_id}", f"pj{piece_id}_m"
            self._maj_index(nom_index, prefixe, [], [])
            if not morceaux:
                return
            ids = [f"{prefixe}{i}" for i in range(len(morceaux))]
            textes = [m["texte"] for m in morceaux]
            collection.add(
                ids=ids,
                embeddings=vecteurs,
                documents=textes,
                metadatas=[
                    {"piece_id": piece_id, "nom": nom,
                     "page": m["page"] if m["page"] is not None else -1}
                    for m in morceaux
                ],
            )
            self._maj_index(nom_index, prefixe, ids, textes)

    def chercher_conversation(self, conversation_id: int, vecteur: list[float], top_k: int) -> list[dict]:
        collection = self._collection_conversation(conversation_id, creer=False)
        if collection is None:
            return []
        total = collection.count()
        if total == 0:
            return []
        resultat = collection.query(query_embeddings=[vecteur], n_results=max(1, min(top_k, total)))
        return [
            {
                "id": id_,
                "document_id": None,
                "nom": meta["nom"],
                "page": None if meta["page"] == -1 else meta["page"],
                "texte": texte,
                "distance": distance,
                "pj": True,
            }
            for id_, meta, texte, distance in zip(
                resultat["ids"][0], resultat["metadatas"][0], resultat["documents"][0], resultat["distances"][0]
            )
        ]

    def supprimer_piece_jointe(self, conversation_id: int, piece_id: int) -> None:
        """Retire les vecteurs d'UNE SEULE pièce jointe de la collection de la conversation,
        sans toucher aux autres pièces déjà indexées. Silencieux si la collection n'existe pas
        (conversation jamais indexée, ou déjà supprimée) : creer=False, comme chercher_conversation."""
        with self._verrou:
            collection = self._collection_conversation(conversation_id, creer=False)
            if collection is None:
                return
            collection.delete(where={"piece_id": piece_id})
            self._maj_index(f"conv_{conversation_id}", f"pj{piece_id}_m", [], [])

    def supprimer_conversation(self, conversation_id: int) -> None:
        nom = f"conv_{conversation_id}"
        with self._verrou:
            try:
                self._client.delete_collection(nom)
            except Exception:
                pass
            self._collections.pop(nom, None)
            self._index.pop(nom, None)

    def _index_de(self, nom: str, collection) -> IndexMotsCles:
        """Index BM25 de la collection, construit au premier usage depuis les textes rangés."""
        if nom in self._index:
            return self._index[nom]
        with self._verrou:
            if nom not in self._index:
                index = IndexMotsCles()
                donnees = collection.get(include=["documents"])
                index.ajouter(donnees["ids"], donnees["documents"])
                self._index[nom] = index
            return self._index[nom]

    def _maj_index(self, nom: str, prefixe: str, ids: list[str], textes: list[str]) -> None:
        """Tient l'index à jour s'il est déjà construit (sinon il sera lu frais au premier usage)."""
        index = self._index.get(nom)
        if index is not None:
            index.retirer(prefixe)
            index.ajouter(ids, textes)

    def _mots_cles(self, nom: str, collection, question: str, n: int, pj: bool) -> list[dict]:
        if collection is None:
            return []
        with self._verrou:   # les écritures modifient l'index : lecture sous le même verrou
            trouves = self._index_de(nom, collection).chercher(question, n)
        if not trouves:
            return []
        scores = dict(trouves)
        morceaux = self._lire_ids(collection, [i for i, _ in trouves], pj)
        return [{**m, "score_mots_cles": scores[m["id"]]} for m in morceaux]

    def chercher_mots_cles(self, secteur: str, question: str, n: int) -> list[dict]:
        """Morceaux du secteur les mieux classés par mots-clés (même forme que `chercher`, distance None)."""
        return self._mots_cles(f"secteur_{secteur}", self._collection(secteur), question, n, False)

    def chercher_mots_cles_conversation(self, conversation_id: int, question: str, n: int) -> list[dict]:
        """Idem, dans les pièces jointes d'une conversation."""
        return self._mots_cles(f"conv_{conversation_id}",
                               self._collection_conversation(conversation_id, creer=False), question, n, True)

    def mots_rares_conversation(self, conversation_id: int, question: str) -> set[str]:
        """Idem `mots_rares`, dans les pièces jointes d'une conversation (vide si aucune)."""
        with self._verrou:
            collection = self._collection_conversation(conversation_id, creer=False)
            if collection is None:
                return set()
            return self._index_de(f"conv_{conversation_id}", collection).rares(question, 0.10)

    def mots_rares(self, secteur: str, question: str) -> set[str]:
        """Mots de la question présents dans moins de 10 % des morceaux du secteur."""
        with self._verrou:
            return self._index_de(f"secteur_{secteur}", self._collection(secteur)).rares(question, 0.10)
