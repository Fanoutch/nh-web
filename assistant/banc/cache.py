"""Cache disque des vecteurs : un texte déjà vectorisé ne repasse pas par le modèle d'embedding."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Callable


def envelopper_embedder(embedder: Callable, dossier: Path, modele: str) -> Callable:
    dossier = Path(dossier)
    dossier.mkdir(parents=True, exist_ok=True)
    fichier = dossier / f"vecteurs-{hashlib.sha256(modele.encode()).hexdigest()[:12]}.jsonl"
    memoire: dict[str, list[float]] = {}
    if fichier.exists():
        for ligne in fichier.read_text(encoding="utf-8").splitlines():
            entree = json.loads(ligne)
            memoire[entree["cle"]] = entree["vecteur"]

    def embedder_en_cache(textes: list[str], r) -> list[list[float]]:
        cles = [hashlib.sha256(t.encode("utf-8")).hexdigest() for t in textes]
        manquants = [(c, t) for c, t in zip(cles, textes) if c not in memoire]
        if manquants:
            uniques = dict(manquants)
            vecteurs = embedder(list(uniques.values()), r)
            with fichier.open("a", encoding="utf-8") as f:
                for cle, vecteur in zip(uniques, vecteurs):
                    memoire[cle] = vecteur
                    f.write(json.dumps({"cle": cle, "vecteur": vecteur}) + "\n")
        return [memoire[c] for c in cles]

    return embedder_en_cache
