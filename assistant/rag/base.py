"""Base vectorielle ChromaDB : une collection par secteur, morceaux tagués par document.

Un seul processus (le service) ouvre la base. Les écritures sont sérialisées
par un verrou ; les recherches se font en parallèle.
"""
from __future__ import annotations

import threading
from pathlib import Path

import chromadb


class BaseVectorielle:
    def __init__(self, dossier: Path):
        Path(dossier).mkdir(parents=True, exist_ok=True)
        self._client = chromadb.PersistentClient(path=str(dossier))
        self._verrou = threading.RLock()
        self._collections: dict[str, chromadb.Collection] = {}

    def _collection(self, secteur: str):
        if secteur in self._collections:
            return self._collections[secteur]
        with self._verrou:
            if secteur in self._collections:
                return self._collections[secteur]
            collection = self._client.get_or_create_collection(name=f"secteur_{secteur}")
            self._collections[secteur] = collection
            return collection

    def ajouter_document(self, secteur: str, document_id: int, nom: str,
                         morceaux: list[dict], vecteurs: list[list[float]]) -> None:
        if len(morceaux) != len(vecteurs):
            raise ValueError("autant de vecteurs que de morceaux attendus")
        with self._verrou:
            collection = self._collection(secteur)
            collection.delete(where={"document_id": document_id})
            if not morceaux:
                return
            collection.add(
                ids=[f"doc{document_id}_m{i}" for i in range(len(morceaux))],
                embeddings=vecteurs,
                documents=[m["texte"] for m in morceaux],
                metadatas=[
                    {"document_id": document_id, "nom": nom,
                     "page": m["page"] if m["page"] is not None else -1}
                    for m in morceaux
                ],
            )

    def supprimer_document(self, secteur: str, document_id: int) -> None:
        with self._verrou:
            self._collection(secteur).delete(where={"document_id": document_id})

    def chercher(self, secteur: str, vecteur: list[float], top_k: int) -> list[dict]:
        collection = self._collection(secteur)
        total = collection.count()
        if total == 0:
            return []
        resultat = collection.query(query_embeddings=[vecteur], n_results=min(top_k, total))
        return [
            {
                "document_id": meta["document_id"],
                "nom": meta["nom"],
                "page": None if meta["page"] == -1 else meta["page"],
                "texte": texte,
                "distance": distance,
            }
            for meta, texte, distance in zip(
                resultat["metadatas"][0], resultat["documents"][0], resultat["distances"][0]
            )
        ]

    def nb_morceaux(self, secteur: str, document_id: int) -> int:
        return len(self._collection(secteur).get(where={"document_id": document_id})["ids"])
