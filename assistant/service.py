"""Service HTTP de l'assistant IA (nh-assistant), appelé uniquement par Laravel.

Lancement (un seul processus : requêtes servies en parallèle par des threads,
un seul programme accède à ChromaDB) :

    .venv/bin/uvicorn service:creer_app --factory --host 127.0.0.1 --port 8765

Toutes les routes exigent l'en-tête X-Assistant-Jeton = ASSISTANT_JETON.
"""
from __future__ import annotations

import hmac
import json

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from rag import lecteurs, modeles
from rag.assistant import MESSAGE_INDISPONIBLE, Assistant
from rag.base import BaseVectorielle
from rag.reglages import Reglages, charger


class DocumentEntrant(BaseModel):
    secteur: str
    document_id: int
    chemin: str
    nom: str


class MessageHistorique(BaseModel):
    role: str
    content: str


class QuestionEntrante(BaseModel):
    secteur: str
    nom_secteur: str
    question: str
    historique: list[MessageHistorique] = []


def creer_app(reglages: Reglages | None = None, assistant=None) -> FastAPI:
    r = reglages or charger()
    moteur = assistant or Assistant(r, BaseVectorielle(r.chroma_dir))
    app = FastAPI(title="nh-assistant")

    def verifier_jeton(x_assistant_jeton: str | None = Header(default=None)) -> None:
        # Comparaison en temps constant ; jeton configuré vide = tout refusé.
        if not r.jeton or x_assistant_jeton is None or not hmac.compare_digest(
                x_assistant_jeton.encode("utf-8", "surrogateescape"), r.jeton.encode("utf-8")):
            raise HTTPException(status_code=401, detail="Jeton invalide")

    protege = [Depends(verifier_jeton)]

    @app.get("/formats", dependencies=protege)
    def formats():
        return {"formats": lecteurs.formats()}

    @app.get("/sante", dependencies=protege)
    def sante():
        etat = {"service": "ok", "embedding": "ok", "llm": "ok" if r.llm_actif else "désactivé"}
        try:
            modeles.embedder(["test"], r)
        except modeles.ModeleIndisponible as exc:
            etat["embedding"] = str(exc)
        if r.llm_actif:
            try:
                modeles.discuter([{"role": "user", "content": "Réponds seulement : OK"}], r, 0)
            except modeles.ModeleIndisponible as exc:
                etat["llm"] = str(exc)
        return etat

    @app.post("/documents", dependencies=protege)
    def indexer(document: DocumentEntrant):
        try:
            n = moteur.indexer(document.secteur, document.document_id, document.chemin, document.nom)
        except lecteurs.LectureImpossible as exc:
            cause = exc.__cause__
            raise HTTPException(status_code=422, detail={
                "code": "lecture", "message": str(exc),
                "detail": f"{type(cause).__name__}: {cause}" if cause else str(exc),
            })
        except modeles.ModeleIndisponible as exc:
            raise HTTPException(status_code=503, detail={
                "code": "modele", "message": MESSAGE_INDISPONIBLE, "detail": str(exc),
            })
        except Exception as exc:  # erreur imprévue (base, disque…) : réponse JSON lisible par Laravel
            raise HTTPException(status_code=503, detail={
                "code": "interne", "message": MESSAGE_INDISPONIBLE, "detail": f"{type(exc).__name__}: {exc}",
            })
        return {"nb_morceaux": n}

    @app.delete("/documents/{document_id}", dependencies=protege)
    def supprimer(document_id: int, secteur: str):
        moteur.supprimer(secteur, document_id)
        return {"ok": True}

    @app.post("/questions", dependencies=protege)
    def questions(q: QuestionEntrante):
        historique = [m.model_dump() for m in q.historique]

        def flux():
            for evenement in moteur.repondre(q.secteur, q.nom_secteur, q.question, historique):
                yield json.dumps(evenement, ensure_ascii=False) + "\n"

        return StreamingResponse(flux(), media_type="application/x-ndjson")

    return app
