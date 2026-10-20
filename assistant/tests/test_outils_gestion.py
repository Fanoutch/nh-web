import json

import pytest

import outils  # noqa: F401
from agents.boucle import executer_boucle
from outils.registre import REGISTRE, Contexte, executer


@pytest.fixture
def ctx(tmp_path):
    return Contexte(dossier_execution=tmp_path, fichiers_autorises=[], outils_autorises=set(REGISTRE),
                    dossier_sortie=tmp_path / "sortie",
                    donnees_agent={"historique": [{"date": f"2026-10-{d:02d}", "mode": "execution", "etat": "termine",
                                                   "resume": f"Lancement {d}"} for d in range(12, 0, -1)]})


def test_proposer_enregistrer(ctx):
    r = executer("agent_proposer", {"type": "enregistrer", "nom": "Commande", "description": "d", "consignes": "c"}, ctx)

    assert r.statut == "ok"
    assert r.donnees["proposition"] == {"type": "enregistrer", "nom": "Commande", "description": "d", "consignes": "c"}
    assert "ne dites jamais que c'est déjà enregistré" in r.suite


def test_proposer_ne_modifie_rien_et_ne_produit_aucun_fichier(ctx):
    executer("agent_proposer", {"type": "rappel", "periodicite": "mensuel"}, ctx)

    assert ctx.fichiers_produits == [] and not ctx.dossier_sortie.exists()


@pytest.mark.parametrize("arguments, attendu", [
    ({"type": "enregistrer"}, "nom de l'agent"),
    ({"type": "enregistrer", "nom": "   "}, "nom de l'agent"),
    ({"type": "consignes"}, "texte des consignes"),
    ({"type": "rappel", "periodicite": "quotidien"}, "n'existe pas"),
    ({"type": "supprimer"}, "n'existe pas"),
])
def test_proposer_refuse_les_demandes_incompletes(ctx, arguments, attendu):
    r = executer("agent_proposer", arguments, ctx)
    assert r.statut == "erreur" and attendu in r.resume and r.suite


def test_proposer_borne_les_textes(ctx):
    r = executer("agent_proposer", {"type": "consignes", "consignes": "x" * 9000}, ctx)
    assert len(r.donnees["proposition"]["consignes"]) == 4000


def test_rappel_aucun_est_permis(ctx):
    assert executer("agent_proposer", {"type": "rappel", "periodicite": "aucun"}, ctx).statut == "ok"


def test_historique_borne_a_dix_et_par_defaut_cinq(ctx):
    assert len(executer("agent_historique", {}, ctx).donnees["lancements"]) == 5
    assert len(executer("agent_historique", {"nombre": 99}, ctx).donnees["lancements"]) == 10
    assert len(executer("agent_historique", {"nombre": "abc"}, ctx).donnees["lancements"]) == 5


def test_historique_vide(ctx):
    ctx.donnees_agent = {}
    r = executer("agent_historique", {}, ctx)
    assert r.statut == "ok" and r.donnees["lancements"] == []


def test_la_boucle_transmet_la_proposition_dans_l_etape(ctx):
    appel = {"content": None, "tool_calls": [{"id": "c", "function": {"name": "agent_proposer", "arguments": json.dumps(
        {"type": "rappel", "periodicite": "mensuel"})}}]}
    reponses = iter([appel, {"content": "Je vous propose un rappel mensuel. Confirmez-vous ?"}])

    ev = list(executer_boucle(lambda m, o: next(reponses), "", "Rappelle-moi chaque mois.", ctx))

    assert ev[0]["type"] == "etape" and ev[0]["proposition"] == {"type": "rappel", "periodicite": "mensuel"}
    assert ev[-1]["statut"] == "termine"


def test_une_erreur_de_proposition_n_est_pas_transmise(ctx):
    appel = {"content": None, "tool_calls": [{"id": "c", "function": {"name": "agent_proposer", "arguments": json.dumps(
        {"type": "rappel", "periodicite": "jamais"})}}]}
    reponses = iter([appel, {"content": "Désolé."}])

    ev = list(executer_boucle(lambda m, o: next(reponses), "", "x", ctx))

    assert "proposition" not in ev[0] and ev[0]["statut"] == "erreur"
