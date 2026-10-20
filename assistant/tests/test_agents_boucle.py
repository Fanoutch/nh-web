import json

import openpyxl
import pytest

import outils  # noqa: F401
from agents import boucle
from outils.registre import Contexte

TOUS = {"excel_decrire", "excel_lire", "excel_filtrer", "excel_selectionner", "excel_colonne",
        "excel_rapprocher", "excel_creer", "excel_regenerer"}


@pytest.fixture
def ctx(tmp_path):
    entree = tmp_path / "entree"
    entree.mkdir()
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Stock"
    for l in [["Réf", "Stock"], ["A", 3], ["B", 0]]:
        ws.append(l)
    wb.save(entree / "stock.xlsx")
    return Contexte(dossier_execution=entree, fichiers_autorises=["stock.xlsx"],
                    outils_autorises=set(TOUS), dossier_sortie=tmp_path / "sortie")


class FauxModele:
    """Rejoue une liste de messages d'assistant et garde ce qu'il a reçu."""
    def __init__(self, *messages):
        self.messages = list(messages)
        self.recus = []

    def __call__(self, messages, outils):
        self.recus.append((json.loads(json.dumps(messages)), outils))
        return self.messages.pop(0)


def std(nom, arguments, ident="c"):
    return {"content": None, "tool_calls": [{"id": ident, "type": "function",
                                             "function": {"name": nom, "arguments": json.dumps(arguments)}}]}


def texte(nom, arguments):
    return {"content": "[TOOL_CALLS]" + json.dumps([{"name": nom, "arguments": arguments}])}


def lancer(modele, ctx, **kw):
    return list(boucle.executer_boucle(modele, "Consigne X.", "Que contient le stock ?", ctx, **kw))


def test_sortie_standard_jusqu_a_la_reponse(ctx):
    m = FauxModele(std("excel_lire", {"fichier": "stock.xlsx"}, "c1"), {"content": "Deux articles."})
    ev = lancer(m, ctx)
    assert [e["type"] for e in ev] == ["etape", "fin"]
    assert ev[0]["outil"] == "excel_lire" and ev[0]["statut"] == "ok"
    assert ev[1]["statut"] == "termine" and ev[1]["message"] == "Deux articles." and ev[1]["tours"] == 2
    # le résultat est renvoyé au modèle au format standard, avec l'identifiant de l'appel
    dernier = m.recus[1][0][-1]
    assert dernier["role"] == "tool" and dernier["tool_call_id"] == "c1"
    assert json.loads(dernier["content"])["statut"] == "ok"


def test_sortie_texte_tool_calls(ctx):
    m = FauxModele(texte("excel_lire", {"fichier": "stock.xlsx"}), {"content": "Fini."})
    ev = lancer(m, ctx)
    assert ev[0]["type"] == "etape" and ev[-1]["statut"] == "termine"
    dernier = m.recus[1][0][-1]
    assert dernier["role"] == "user" and dernier["content"].startswith("Résultat de l'outil excel_lire")


def test_le_modele_recoit_consignes_demande_et_outils_autorises(ctx):
    ctx.outils_autorises = {"excel_decrire"}
    m = FauxModele({"content": "Rien à faire."}, {"content": "Rien à faire."})
    lancer(m, ctx)
    messages, declares = m.recus[0]
    assert messages[0]["role"] == "system" and "Consigne X." in messages[0]["content"]
    assert "donnée" in messages[0]["content"]
    assert messages[-1]["role"] == "user" and messages[-1]["content"].startswith("Que contient le stock ?")
    assert [o["function"]["name"] for o in declares] == ["excel_decrire"]


def test_douze_tours_puis_arret(ctx):
    m = FauxModele(*[std("excel_lire", {"fichier": "stock.xlsx", "depuis": i}) for i in range(20)])
    ev = lancer(m, ctx)
    assert ev[-1]["statut"] == "arret" and "12 étapes" in ev[-1]["message"]
    assert len([e for e in ev if e["type"] == "etape"]) == 12


def test_cinq_minutes(ctx):
    temps = iter([0, 0, 301])
    m = FauxModele(std("excel_decrire", {}), {"content": "jamais"})
    ev = lancer(m, ctx, horloge=lambda: next(temps))
    assert ev[-1]["statut"] == "arret" and "5 minutes" in ev[-1]["message"]
    assert len(m.recus) == 1


def test_meme_appel_deux_fois(ctx):
    m = FauxModele(std("excel_decrire", {"fichier": "stock.xlsx"}), std("excel_decrire", {"fichier": "stock.xlsx"}))
    ev = lancer(m, ctx)
    assert ev[-1]["statut"] == "arret" and "deux fois la même" in ev[-1]["message"]
    assert len([e for e in ev if e["type"] == "etape"]) == 1


def test_meme_outil_arguments_differents_est_permis(ctx):
    m = FauxModele(std("excel_lire", {"fichier": "stock.xlsx", "depuis": 0}),
                   std("excel_lire", {"fichier": "stock.xlsx", "depuis": 1}), {"content": "ok"})
    assert lancer(m, ctx)[-1]["statut"] == "termine"


def test_deux_echecs_du_meme_outil(ctx):
    m = FauxModele(std("excel_lire", {"fichier": "absent.xlsx"}), std("excel_lire", {"fichier": "autre.xlsx"}),
                   {"content": "jamais"})
    ev = lancer(m, ctx)
    assert ev[0]["statut"] == "erreur"
    assert ev[-1]["statut"] == "arret" and "a échoué deux fois" in ev[-1]["message"]


def test_un_echec_suivi_d_un_succes_continue(ctx):
    m = FauxModele(std("excel_lire", {"fichier": "absent.xlsx"}), std("excel_decrire", {"fichier": "stock.xlsx"}),
                   {"content": "Corrigé."})
    ev = lancer(m, ctx)
    assert ev[-1]["statut"] == "termine"
    # l'erreur est renvoyée au modèle, avec comment réessayer
    retour = json.loads(m.recus[1][0][-1]["content"])
    assert retour["statut"] == "erreur" and retour["suite"]


def test_outil_hors_liste_refuse_par_le_registre(ctx):
    ctx.outils_autorises = {"excel_decrire"}
    m = FauxModele(std("excel_creer", {"nom": "x", "colonnes": ["a"], "lignes": []}), {"content": "ok"})
    ev = lancer(m, ctx)
    assert ev[0]["statut"] == "erreur" and "pas autorisé" in ev[0]["resume"]
    assert not ctx.dossier_sortie.exists() or not list(ctx.dossier_sortie.iterdir())


def test_reponse_illisible_puis_relance_reussie(ctx):
    m = FauxModele({"content": "[TOOL_CALLS][{cassé"}, std("excel_lire", {"fichier": "stock.xlsx"}),
                   {"content": "Voilà."})
    ev = lancer(m, ctx)
    assert ev[-1]["statut"] == "termine"
    assert "pas exploitable" in m.recus[1][0][-1]["content"]


def test_reponse_illisible_deux_fois_arret_propre(ctx):
    m = FauxModele({"content": "[TOOL_CALLS][{cassé"}, {"content": "[TOOL_CALLS]encore cassé"})
    ev = lancer(m, ctx)
    assert len(ev) == 1 and ev[0]["statut"] == "arret" and "relance" in ev[0]["message"]


def test_la_relance_n_est_permise_qu_une_fois_par_travail(ctx):
    m = FauxModele({"content": "[TOOL_CALLS]x"}, std("excel_decrire", {"fichier": "stock.xlsx"}),
                   {"content": "[TOOL_CALLS]y"})
    assert lancer(m, ctx)[-1]["statut"] == "arret"


def test_bouton_arreter(ctx):
    m = FauxModele(std("excel_decrire", {}), {"content": "jamais"})
    appels = iter([False, True])
    ev = lancer(m, ctx, arreter=lambda: next(appels))
    assert ev[-1]["statut"] == "arret" and ev[-1]["message"] == "Arrêt demandé."


def test_fichiers_produits_dans_la_fin(ctx):
    m = FauxModele(std("excel_creer", {"nom": "sortie.xlsx", "colonnes": ["a"], "lignes": [[1]]}),
                   {"content": "Créé."})
    fin = lancer(m, ctx)[-1]
    assert fin["statut"] == "termine" and fin["fichiers"] == ctx.fichiers_produits and fin["fichiers"]


def test_cle_et_jeton_ne_sont_jamais_dans_les_evenements(ctx):
    m = FauxModele(std("excel_lire", {"fichier": "stock.xlsx"}), {"content": "ok"})
    brut = json.dumps(lancer(m, ctx))
    assert "Authorization" not in brut and "cle" not in brut.lower().replace("clé", "")


def test_format_texte_plusieurs_appels_un_seul_message_utilisateur(ctx):
    deux = {"content": "[TOOL_CALLS]" + json.dumps([
        {"name": "excel_decrire", "arguments": {"fichier": "stock.xlsx"}},
        {"name": "excel_lire", "arguments": {"fichier": "stock.xlsx"}}])}
    m = FauxModele(deux, {"content": "Fini."})
    ev = lancer(m, ctx)
    assert ev[-1]["statut"] == "termine"
    roles = [x["role"] for x in m.recus[1][0]]
    assert roles == ["system", "user", "assistant", "user"]       # alternance stricte, jamais deux « user » de suite
    dernier = m.recus[1][0][-1]["content"]
    assert "excel_decrire" in dernier and "excel_lire" in dernier


def test_format_standard_plusieurs_appels_un_message_outil_par_appel(ctx):
    deux = {"content": None, "tool_calls": [
        {"id": "a", "function": {"name": "excel_decrire", "arguments": json.dumps({"fichier": "stock.xlsx"})}},
        {"id": "b", "function": {"name": "excel_lire", "arguments": json.dumps({"fichier": "stock.xlsx"})}}]}
    m = FauxModele(deux, {"content": "Fini."})
    lancer(m, ctx)
    assert [x["role"] for x in m.recus[1][0]] == ["system", "user", "assistant", "tool", "tool"]


def test_la_demande_est_accompagnee_de_la_fiche_des_fichiers(ctx):
    m = FauxModele({"content": "ok"}, {"content": "ok"})
    lancer(m, ctx)
    envoye = m.recus[0][0][-1]["content"]
    assert envoye.startswith("Que contient le stock ?")
    assert "stock.xlsx (classeur)" in envoye and "feuille « Stock »" in envoye and "colonnes Réf, Stock" in envoye


def test_json_nu_dans_le_texte_est_reconnu_comme_appel_d_outil(ctx):
    nu = {"content": json.dumps([{"name": "excel_lire", "arguments": {"fichier": "stock.xlsx"}}])}
    m = FauxModele(nu, {"content": "Voilà."})
    ev = lancer(m, ctx)
    assert ev[0]["type"] == "etape" and ev[0]["outil"] == "excel_lire" and ev[-1]["statut"] == "termine"


def test_json_nu_d_un_outil_inconnu_reste_un_texte(ctx):
    texte_json = json.dumps([{"name": "outil_invente", "arguments": {}}])
    m = FauxModele({"content": texte_json}, {"content": texte_json})
    ev = lancer(m, ctx)
    assert [e["type"] for e in ev] == ["fin"] and ev[0]["message"] == texte_json


def test_annonce_d_un_outil_sans_l_appeler_donne_une_relance(ctx):
    m = FauxModele({"content": "Je vais utiliser excel_filtrer pour compter. Patientez."},
                   std("excel_filtrer", {"fichier": "stock.xlsx"}), {"content": "Il y en a 2."})
    ev = lancer(m, ctx)
    assert ev[0]["outil"] == "excel_filtrer" and ev[-1]["message"] == "Il y en a 2."
    assert "sans l'appeler" in m.recus[1][0][-1]["content"]


def test_reponse_sans_avoir_lu_les_fichiers_donne_une_relance_puis_est_acceptee(ctx):
    m = FauxModele({"content": "Voici les pièces : A, B."}, {"content": "Je n'ai pas pu vérifier."})
    ev = lancer(m, ctx)
    assert [e["type"] for e in ev] == ["fin"] and ev[0]["message"] == "Je n'ai pas pu vérifier."
    assert "aucune donnée" in m.recus[1][0][-1]["content"]


def test_decrire_seul_ne_compte_pas_comme_une_lecture(ctx):
    m = FauxModele(std("excel_decrire", {"fichier": "stock.xlsx"}), {"content": "Inventé."},
                   std("excel_lire", {"fichier": "stock.xlsx"}), {"content": "Vrai."})
    ev = lancer(m, ctx)
    assert ev[-1]["message"] == "Vrai."


def test_sans_fichier_aucune_relance_de_lecture(tmp_path):
    ctx = Contexte(dossier_execution=tmp_path, fichiers_autorises=[], outils_autorises=set(TOUS),
                   dossier_sortie=tmp_path / "sortie")
    m = FauxModele({"content": "Bonjour, je sais lire Excel et Word."})
    ev = lancer(m, ctx)
    assert ev[0]["statut"] == "termine" and len(m.recus) == 1
