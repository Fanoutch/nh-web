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
from pathlib import Path
from threading import Lock

from pydantic import BaseModel

import outils  # noqa: F401 — enregistre les outils Excel
from agents.boucle import executer_boucle
from agents.contexte import analyser
from outils.registre import REGISTRE, Contexte
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
    documents_prioritaires: list[int] = []
    conversation: int | None = None


class PieceJointeEntrante(BaseModel):
    conversation_id: int
    piece_id: int
    chemin: str
    nom: str


class ExecutionEntrante(BaseModel):
    execution_id: int
    consignes: str = ""
    demande: str
    historique: list[MessageHistorique] = []
    dossier_execution: str          # fichiers déposés (références de l'agent + fichiers apportés)
    fichiers: list[str]             # leurs noms : rien d'autre n'est lisible
    dossier_sortie: str             # fichiers produits
    outils: list[str]               # outils autorisés par la recette de l'agent
    donnees_agent: dict = {}        # historique des lancements, etc. (aucun secret)


class FichiersALire(BaseModel):
    dossier: str
    fichiers: list[str]


def creer_app(reglages: Reglages | None = None, assistant=None, modele_agent=None) -> FastAPI:
    r = reglages or charger()
    moteur = assistant or Assistant(r, BaseVectorielle(r.chroma_dir))
    app = FastAPI(title="nh-assistant")
    arrets: set[int] = set()        # exécutions dont l'arrêt est demandé (un seul processus : en mémoire)
    verrou_arrets = Lock()

    def modele(messages, outils_declares):
        if modele_agent is not None:
            return modele_agent(messages, outils_declares)
        return modeles.discuter_avec_outils(messages, outils_declares, r, 0)

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
            for evenement in moteur.repondre(q.secteur, q.nom_secteur, q.question, historique,
                                             q.documents_prioritaires, q.conversation):
                yield json.dumps(evenement, ensure_ascii=False) + "\n"

        return StreamingResponse(flux(), media_type="application/x-ndjson")

    @app.post("/pieces-jointes", dependencies=protege)
    def pieces_jointes(piece: PieceJointeEntrante):
        try:
            n = moteur.indexer_piece_jointe(piece.conversation_id, piece.piece_id, piece.chemin, piece.nom)
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

    @app.delete("/pieces-jointes/{piece_id}", dependencies=protege)
    def supprimer_piece_jointe(piece_id: int, conversation_id: int):
        moteur.supprimer_piece_jointe(conversation_id, piece_id)
        return {"ok": True}

    @app.delete("/conversations/{conversation_id}", dependencies=protege)
    def supprimer_conversation(conversation_id: int):
        moteur.supprimer_conversation(conversation_id)
        return {"ok": True}

    @app.post("/agents/executions", dependencies=protege)
    def executer_agent(e: ExecutionEntrante):
        entree, sortie = Path(e.dossier_execution), Path(e.dossier_sortie)
        if not entree.is_absolute() or not sortie.is_absolute() or not entree.is_dir():
            raise HTTPException(status_code=422, detail={
                "code": "dossier", "message": "Le dossier de l'exécution est introuvable."})
        if any(Path(nom).name != nom or nom in ("", ".", "..") for nom in e.fichiers):
            raise HTTPException(status_code=422, detail={
                "code": "fichier", "message": "Un nom de fichier est invalide."})
        inconnus = sorted(set(e.outils) - set(REGISTRE))
        if inconnus:
            raise HTTPException(status_code=422, detail={
                "code": "outil", "message": "Outil inconnu : " + ", ".join(inconnus) + "."})
        contexte = Contexte(dossier_execution=entree, fichiers_autorises=list(e.fichiers),
                            outils_autorises=set(e.outils), dossier_sortie=sortie,
                            donnees_agent=e.donnees_agent)
        historique = [m.model_dump() for m in e.historique]
        with verrou_arrets:
            arrets.discard(e.execution_id)

        def flux():
            def arreter():
                with verrou_arrets:
                    return e.execution_id in arrets
            try:
                for evenement in executer_boucle(modele, e.consignes, e.demande, contexte,
                                                 arreter=arreter, historique=historique):
                    yield json.dumps(evenement, ensure_ascii=False) + "\n"
            except modeles.ModeleIndisponible as exc:
                yield json.dumps({"type": "fin", "statut": "arret", "message": MESSAGE_INDISPONIBLE,
                                  "detail": str(exc), "tours": 0, "fichiers": contexte.fichiers_produits},
                                 ensure_ascii=False) + "\n"
            except Exception as exc:  # erreur imprévue : un événement lisible, jamais un flux coupé net
                yield json.dumps({"type": "fin", "statut": "arret", "message": MESSAGE_INDISPONIBLE,
                                  "detail": f"{type(exc).__name__}: {exc}", "tours": 0,
                                  "fichiers": contexte.fichiers_produits}, ensure_ascii=False) + "\n"
            finally:
                with verrou_arrets:
                    arrets.discard(e.execution_id)

        return StreamingResponse(flux(), media_type="application/x-ndjson")

    @app.post("/agents/fichiers/lire", dependencies=protege)
    def lire_fichiers(f: FichiersALire):
        """Confirmation de lecture d'un dépôt : structure de chaque fichier (jamais de valeurs de cellules)."""
        dossier = Path(f.dossier)
        if not dossier.is_absolute() or not dossier.is_dir():
            raise HTTPException(status_code=422, detail={"code": "dossier", "message": "Le dossier est introuvable."})
        if any(Path(nom).name != nom or nom in ("", ".", "..") for nom in f.fichiers):
            raise HTTPException(status_code=422, detail={"code": "fichier", "message": "Un nom de fichier est invalide."})
        contexte = Contexte(dossier_execution=dossier, fichiers_autorises=list(f.fichiers), outils_autorises=set(),
                            dossier_sortie=dossier / "_sortie")
        return {"fichiers": [analyser(contexte, nom) for nom in f.fichiers]}

    @app.post("/agents/executions/{execution_id}/arret", dependencies=protege)
    def arreter_agent(execution_id: int):
        with verrou_arrets:
            arrets.add(execution_id)
        return {"ok": True}

    return app
