"""Exécute le banc pour un jeu de réglages et un format : indexation temporaire, recherche, réponses."""
from __future__ import annotations

import statistics
import tempfile
from dataclasses import replace
from pathlib import Path

from banc.juge import juger
from banc.mesures import evaluer_recherche, seuil_propose
from banc.questions import Question
from rag import modeles, reglages
from rag.assistant import Assistant, _extraits
from rag.base import BaseVectorielle
from rag.reglages import Reglages

DOSSIER_JEUX = Path(__file__).resolve().parent / "reglages"
SECTEUR = "banc"


def reglages_du_jeu(nom: str, llm_env: Path) -> Reglages:
    return reglages.charger({"LLM_ENV": str(llm_env), "ASSISTANT_REGLAGES": str(DOSSIER_JEUX / f"{nom}.env")})


def _arret(exc: Exception) -> SystemExit:
    return SystemExit(f"Le modèle ne répond pas ({exc}). Vérifiez EMBED_BASE_URL, EMBED_MODELE et "
                      "LLM_BASE_URL dans llm.env, puis relancez le banc.")


def executer(nom_jeu: str, r: Reglages, format_: str, questions: list[Question], dossier_corpus: Path,
             avec_reponses: bool) -> dict:
    fichiers = sorted((Path(dossier_corpus) / format_).glob(f"*.{format_}"))
    with tempfile.TemporaryDirectory(prefix="banc-") as tmp:
        # base temporaire : on n'écrit jamais dans la base du site
        r = replace(r, chroma_dir=Path(tmp))
        assistant = Assistant(r, BaseVectorielle(Path(tmp)))
        noms, tailles = {}, []
        try:
            for document_id, fichier in enumerate(fichiers, start=1):
                noms[document_id] = fichier.stem
                assistant.indexer(SECTEUR, document_id, str(fichier), fichier.name)
                tailles += [len(t) for t in assistant.base._collection(SECTEUR).get(
                    where={"document_id": document_id})["documents"]]
        except modeles.ModeleIndisponible as exc:
            raise _arret(exc) from exc

        presents, lignes = set(noms.values()), []
        utiles, absentes = [], []
        for q in questions:
            ligne = {"id": q.id, "type": q.type, "applicable": all(d in presents for d in q.documents),
                     "trouve": None, "trouve_par_mots_cles": None, "rang": None, "distance_min": None, "retrouves": "", "reponse": "",
                     "juste": None, "fidele": None, "refus_correct": None, "note": "", "justification": ""}
            lignes.append(ligne)
            if not ligne["applicable"]:
                continue
            try:
                vecteur = modeles.embedder([q.question], r)[0]
                bruts = assistant.base.chercher(SECTEUR, vecteur, r.top_k)
                passages = assistant.passages(SECTEUR, q.question)
            except modeles.ModeleIndisponible as exc:
                raise _arret(exc) from exc
            ligne.update(evaluer_recherche(q, passages, noms))
            if q.type != "absente" and ligne["trouve"]:
                # trouvé seulement grâce aux mots-clés ? on refait la mesure sans ces passages
                sans_mots = [p for p in passages if p.get("origine") != "mots_cles"]
                ligne["trouve_par_mots_cles"] = not evaluer_recherche(q, sans_mots, noms)["trouve"]
            ligne["distance_min"] = round(min((b["distance"] for b in bruts), default=9.0), 3)
            ligne["retrouves"] = " ; ".join(
                f"{p['id']}@{'—' if p['distance'] is None else round(p['distance'], 3)}"
                + (f"[{p['origine']}]" if p.get("origine") else "") for p in passages)
            (absentes if q.type == "absente" else utiles).append(ligne["distance_min"])
            if avec_reponses:
                evenements = list(assistant.repondre(SECTEUR, "BANC", q.question, []))
                erreur = next((e for e in evenements if e["type"] == "erreur"), None)
                if erreur:
                    ligne.update(note="erreur", justification=erreur.get("detail", erreur["message"]))
                    continue
                ligne["reponse"] = "".join(e["texte"] for e in evenements if e["type"] == "texte")
                ligne.update(juger(q, ligne["reponse"], _extraits(passages), r))

    return {
        "jeu": nom_jeu, "format": format_,
        "morceaux": {"nombre": len(tailles), "mediane": int(statistics.median(tailles)) if tailles else 0,
                     "petits": sum(t < r.taille_min for t in tailles)},
        "lignes": lignes,
        "seuil_propose": seuil_propose(utiles, absentes),
    }
