"""Mesure du rapprochement sur des paires fabriquées (risque noté en spec § 13).

Règle de sûreté : le code ne doit JAMAIS déclarer « sûr » un rapprochement faux ou un article absent du
catalogue ; les cas incertains vont en « douteux » pour que le modèle les arbitre."""
import openpyxl
import pytest

import outils  # noqa: F401
from outils.registre import Contexte, executer

CATALOGUE = [
    "Joint torique 12x2 mm", "Joint torique 14x2 mm", "Vis M6x20", "Vis M6x25", "Vis M8x20", "Rondelle plate 8 mm",
    "Rondelle frein 8 mm", "Écrou M6", "Écrou frein M6", "Écrou M8", "Graisse aéronautique 500 g",
    "Filtre à huile moteur", "Filtre à air moteur", "Cosse à sertir 6 mm²", "Collier de serrage 20-32",
    "Durite carburant 8 mm", "Durite hydraulique 8 mm", "Clip de fixation inox", "Ruban adhésif aluminium",
    "Fil à freiner 0,8 mm", "Goupille fendue 3x25", "Rivet pop 4x10", "Gant nitrile taille 9",
    "Chiffon d'essuyage 10 kg", "Dégraissant 5 l", "Joint plat cuivre 10x14", "Bague d'étanchéité 20x30x5",
    "Roulement 6204 2RS", "Ampoule 28 V 5 W", "Fusible 5 A",
]

# (libellé du stock, libellé attendu dans le catalogue ou None si absent du catalogue)
CAS = [
    ("JOINT TORIQUE 12X2", "Joint torique 12x2 mm"), ("joint torique 12 x 2", "Joint torique 12x2 mm"),
    ("Joint torique 14X2 MM", "Joint torique 14x2 mm"), ("Vis M6 x 20", "Vis M6x20"), ("VIS M6X25", "Vis M6x25"),
    ("Vis M8 x 20 mm", "Vis M8x20"), ("Rondelle 8 plate", "Rondelle plate 8 mm"),
    ("Rondelle frein 8", "Rondelle frein 8 mm"), ("Ecrou M6", "Écrou M6"), ("ÉCROU FREIN M6", "Écrou frein M6"),
    ("Ecrou M8", "Écrou M8"), ("Graisse aéronautique 500g", "Graisse aéronautique 500 g"),
    ("Filtre huile moteur", "Filtre à huile moteur"), ("Filtre à air  moteur", "Filtre à air moteur"),
    ("Cosse sertir 6mm²", "Cosse à sertir 6 mm²"), ("Collier serrage 20-32", "Collier de serrage 20-32"),
    ("Durite carburant 8", "Durite carburant 8 mm"), ("Durite hydraulique 8mm", "Durite hydraulique 8 mm"),
    ("Fil à freiner 0.8 mm", "Fil à freiner 0,8 mm"), ("Roulement 6204-2RS", "Roulement 6204 2RS"),
    # articles qui N'EXISTENT PAS au catalogue (dont des quasi-voisins trompeurs)
    ("Vis M6x30", None), ("Joint torique 16x2 mm", None), ("Écrou M10", None), ("Filtre à gasoil", None),
    ("Tournevis cruciforme", None), ("Peinture jaune 1 l", None), ("Rondelle plate 10 mm", None),
    ("Durite oxygène 8 mm", None), ("Batterie 24 V", None), ("Clé dynamométrique", None),
]


def executer_cas(tmp_path):
    entree = tmp_path / "e"
    entree.mkdir()
    for nom, colonne, valeurs in (("stock.xlsx", "Matériel", [c[0] for c in CAS]), ("cat.xlsx", "Désignation", CATALOGUE)):
        wb = openpyxl.Workbook()
        ws = wb.active
        ws.append([colonne])
        for v in valeurs:
            ws.append([v])
        wb.save(entree / nom)
    ctx = Contexte(entree, ["stock.xlsx", "cat.xlsx"], {"excel_rapprocher"}, tmp_path / "s")
    r = executer("excel_rapprocher", {"fichier_a": "stock.xlsx", "colonne_a": "Matériel", "fichier_b": "cat.xlsx",
                                      "colonne_b": "Désignation"}, ctx)
    assert r.statut == "ok"
    d = r.donnees
    return ({p["a"]: p["b"] for p in d["surs"]}, {p["a"]: p["b"] for p in d["douteux"]}, set(d["absents"]))


def mesurer(tmp_path):
    surs, douteux, absents = executer_cas(tmp_path)
    m = {"vrais_surs": 0, "vrais_douteux": 0, "manques": 0, "faux_surs": 0, "faux_douteux": 0, "bons_absents": 0}
    detail = []
    for a, attendu in CAS:
        if attendu is not None:
            if a in surs:
                m["vrais_surs" if surs[a] == attendu else "faux_surs"] += 1
                detail.append((a, "sûr", surs[a], surs[a] == attendu))
            elif a in douteux:
                m["vrais_douteux" if douteux[a] == attendu else "faux_douteux"] += 1
                detail.append((a, "douteux", douteux[a], douteux[a] == attendu))
            else:
                m["manques"] += 1
                detail.append((a, "absent", "", False))
        else:
            if a in surs:
                m["faux_surs"] += 1
                detail.append((a, "sûr", surs[a], False))
            elif a in douteux:
                m["faux_douteux"] += 1
                detail.append((a, "douteux", douteux[a], True))
            else:
                m["bons_absents"] += 1
                detail.append((a, "absent", "", True))
    return m, detail


def test_aucun_faux_sur_et_bon_rappel(tmp_path):
    m, detail = mesurer(tmp_path)
    vrais = sum(1 for _, att in CAS if att)
    rappel = (m["vrais_surs"] + m["vrais_douteux"]) / vrais
    print("\nMesure du rapprochement :", m, f"rappel (sûr + douteux justes) = {rappel:.0%}")
    for ligne in detail:
        print("  ", ligne)
    assert m["faux_surs"] == 0, "un rapprochement faux ne doit jamais être déclaré sûr"
    assert rappel >= 0.9
