"""Couche texte GÉNÉRALE d'un document Word (.docx) : tout le texte visible, où qu'il soit.

Un .docx est un ensemble de fichiers XML ; le texte visible y est toujours rangé dans des paragraphes (w:p) et des
morceaux de texte (w:t), quel que soit l'endroit : corps, tableaux, contrôles de contenu, encadrés, en-têtes et pieds
de page, notes. Plutôt que d'énumérer ces endroits (et d'en oublier), on parcourt tous les paragraphes de toutes les
parties du document. Les champs de formulaire Word (cases grises) apparaissent « [champ] ».

Ce qui n'est pas du texte (cases à cocher, listes déroulantes, équations) est compté par `inventaire` pour prévenir
l'utilisateur, jamais ignoré en silence.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

from lxml import etree

W = "{http://schemas.openxmlformats.org/wordprocessingml/2006/main}"
NS = {"w": W[1:-1], "mc": "http://schemas.openxmlformats.org/markup-compatibility/2006",
      "w14": "http://schemas.microsoft.com/office/word/2010/wordml",
      "m": "http://schemas.openxmlformats.org/officeDocument/2006/math",
      "pic": "http://schemas.openxmlformats.org/drawingml/2006/picture", "v": "urn:schemas-microsoft-com:vml"}
CHAMP_FORMULAIRE = "[champ]"
_PARTIES_TEXTE = re.compile(r"^/word/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$")


def xpath(element, chemin: str) -> list:
    return etree.XPath(chemin, namespaces=NS)(element)


# --- parties du document --------------------------------------------------------------------------------------
@dataclass
class Partie:
    nom: str
    racine: object
    brute: object = None            # partie sans classe dédiée (notes) : XML relu puis réécrit à l'enregistrement


def parties(document) -> list[Partie]:
    """Le corps d'abord, puis en-têtes, pieds de page et notes. Mises en cache sur le document (une seule lecture)."""
    cache = getattr(document, "_parties_texte", None)
    if cache is not None:
        return cache
    trouvees = []
    for part in document.part.package.iter_parts():
        nom = str(part.partname)
        if not _PARTIES_TEXTE.match(nom):
            continue
        if hasattr(part, "element"):
            trouvees.append(Partie(nom, part.element))
        else:
            trouvees.append(Partie(nom, etree.fromstring(part.blob), brute=part))
    trouvees.sort(key=lambda p: (0 if p.nom.endswith("document.xml") else 1, p.nom))
    document._parties_texte = trouvees
    return trouvees


def enregistrer(document, chemin) -> None:
    """Réécrit les parties « brutes » modifiées (notes), puis enregistre le document."""
    for partie in getattr(document, "_parties_texte", None) or []:
        if partie.brute is not None:
            partie.brute._blob = etree.tostring(partie.racine, xml_declaration=True, encoding="UTF-8", standalone=True)
    document.save(str(chemin))


def paragraphes(document, secours: bool = False) -> list:
    """Tous les paragraphes (éléments w:p) de toutes les parties, dans l'ordre. `secours` ajoute les copies de secours
    des encadrés (mc:Fallback) : utiles pour remplacer partout, jamais pour lire ou compter."""
    chemin = ".//w:p" if secours else ".//w:p[not(ancestor::mc:Fallback)]"
    return [p for partie in parties(document) for p in xpath(partie.racine, chemin)]


# --- carte d'un paragraphe : ses morceaux de texte et ses champs de formulaire ---------------------------------
@dataclass
class Champ:
    textes: list = field(default_factory=list)     # w:t du résultat du champ
    runs: list = field(default_factory=list)       # w:r du résultat (pour y créer un texte s'il n'y en a pas)


def _proche(element, balise: str):
    return next(element.iterancestors(W + balise), None)


def carte(p) -> list:
    """Segments du paragraphe dans l'ordre : un w:t, ou un Champ (champ de formulaire texte). Les paragraphes imbriqués
    (encadré ancré dans ce paragraphe) n'en font pas partie : ils ont leur propre carte."""
    segments, champ, etat = [], None, None
    for el in p.iter(W + "t", W + "fldChar", W + "instrText", W + "r"):
        if _proche(el, "p") is not p:            # texte d'un paragraphe imbriqué (encadré) : il a sa propre carte
            continue
        balise = el.tag
        if balise == W + "fldChar":
            genre = el.get(W + "fldCharType")
            if genre == "begin":
                champ, etat = Champ(), "code"
            elif genre == "separate" and champ is not None:
                etat = "resultat" if etat == "formtext" else None
            elif genre == "end" and champ is not None:
                if etat == "resultat":
                    segments.append(champ)
                champ, etat = None, None
        elif balise == W + "instrText":
            if etat == "code" and "FORMTEXT" in (el.text or ""):
                etat = "formtext"
        elif balise == W + "r":
            if etat == "resultat" and el.find(W + "fldChar") is None:
                champ.runs.append(el)
        elif etat == "resultat":
            champ.textes.append(el)
        elif etat is None:
            segments.append(el)
    return segments


def texte(p) -> str:
    return "".join(CHAMP_FORMULAIRE if isinstance(s, Champ) else (s.text or "") for s in carte(p))


def remplir_champ(champ: Champ, valeur: str) -> None:
    textes = champ.textes
    if not textes:
        if not champ.runs:
            return
        t = champ.runs[0].makeelement(W + "t", {})
        champ.runs[0].append(t)
        textes = [t]
    textes[0].text = valeur
    textes[0].set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    for t in textes[1:]:
        t.text = ""


def remplacer_position(p, position: int, longueur: int, par: str) -> bool:
    """Remplace [position, position + longueur) du texte du paragraphe. Si la plage est exactement un champ de
    formulaire, le champ est rempli. Une plage qui coupe un champ n'est pas touchée (renvoie False)."""
    segments, pos = carte(p), 0
    fin = position + longueur
    touches = []                                        # (segment, début dans le segment, fin dans le segment)
    for s in segments:
        n = len(CHAMP_FORMULAIRE) if isinstance(s, Champ) else len(s.text or "")
        if pos < fin and position < pos + n:
            if isinstance(s, Champ):
                if position == pos and fin == pos + n:
                    remplir_champ(s, par)
                    return True
                return False
            touches.append((s, max(0, position - pos), min(n, fin - pos)))
        pos += n
    if not touches:
        return False
    premier, d, _ = touches[0]
    if len(touches) == 1:
        f = touches[0][2]
        premier.text = (premier.text or "")[:d] + par + (premier.text or "")[f:]
    else:
        premier.text = (premier.text or "")[:d] + par
        for s, _, _ in touches[1:-1]:
            s.text = ""
        dernier, _, f = touches[-1]
        dernier.text = (dernier.text or "")[f:]
    premier.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    return True


def remplacer_partout(p, chercher: str, par: str) -> int:
    n, depart = 0, 0
    while True:
        i = texte(p).find(chercher, depart)
        if i < 0:
            return n
        if remplacer_position(p, i, len(chercher), par):
            n += 1
            depart = i + len(par)
        else:
            depart = i + len(chercher)


def copie_de_secours(p):
    """Le même paragraphe dans la copie de secours d'un encadré (mc:Fallback), s'il y en a une."""
    choix = xpath(p, "ancestor::mc:Choice[1]")
    if not choix:
        return None
    secours = xpath(choix[0].getparent(), "mc:Fallback//w:txbxContent//w:p")
    originaux = xpath(choix[0], ".//w:txbxContent//w:p")
    rang = originaux.index(p) if p in originaux else -1
    return secours[rang] if 0 <= rang < len(secours) else None


def voisins(document) -> list:
    """[(paragraphe, texte autour)] : le paragraphe d'avant (même partie) et, dans un tableau, les autres cases de la
    ligne. C'est là qu'est le libellé d'un champ de formulaire (« Grade » | « ........ »)."""
    resultat = []
    for partie in parties(document):
        precedent = ""
        for p in xpath(partie.racine, ".//w:p[not(ancestor::mc:Fallback)]"):
            autour = ""
            case = _proche(p, "tc")
            if case is not None and _proche(case, "tr") is not None:
                autres = [c for c in _proche(case, "tr").iterchildren(W + "tc") if c is not case]
                autour = " ".join(texte(q) for c in autres for q in xpath(c, ".//w:p"))
            resultat.append((p, precedent + " " + autour))
            precedent = texte(p)
    return resultat


# --- inventaire : ce que contient un document (jamais son contenu) ---------------------------------------------
NON_PRIS_EN_CHARGE = {
    "cases_a_cocher": "cases à cocher (elles ne seront pas cochées)",
    "listes_deroulantes": "listes déroulantes (aucun choix ne sera fait)",
    "equations": "équations (leur texte n'est pas modifiable)",
}


def inventaire(document) -> dict:
    """Nombre de chaque élément présent. Ne renvoie jamais de texte du document."""
    compte: dict[str, int] = {}

    def ajouter(cle, n):
        compte[cle] = compte.get(cle, 0) + n

    for partie in parties(document):
        r = partie.racine
        zone = "corps" if partie.nom.endswith("document.xml") else (
            "entetes_pieds" if ("header" in partie.nom or "footer" in partie.nom) else "notes")
        if zone != "corps":
            ajouter(zone, 1 if any(texte(p).strip() for p in xpath(r, ".//w:p")) else 0)
        ajouter("paragraphes", len(xpath(r, ".//w:p[not(ancestor::mc:Fallback)]")))
        ajouter("tableaux", len(xpath(r, ".//w:tbl[not(ancestor::mc:Fallback)]")))
        ajouter("encadres", len(xpath(r, ".//w:txbxContent[not(ancestor::mc:Fallback)]")))
        ajouter("controles_de_contenu", len(xpath(r, ".//w:sdt")))
        ajouter("cases_a_cocher", len(xpath(r, ".//w:sdtPr/w14:checkbox | .//w:ffData/w:checkBox")))
        ajouter("listes_deroulantes", len(xpath(r, ".//w:sdtPr/w:dropDownList | .//w:sdtPr/w:comboBox | .//w:ffData/w:ddList")))
        ajouter("champs_formulaire_texte", len(xpath(r, ".//w:ffData/w:textInput")))
        ajouter("images", len(xpath(r, ".//w:drawing[.//pic:pic][not(ancestor::mc:Fallback)]"
                                       " | .//w:pict[.//v:imagedata][not(ancestor::mc:Fallback)]")))
        ajouter("equations", len(xpath(r, ".//m:oMath")))
        ajouter("modifications_suivies", len(xpath(r, ".//w:ins | .//w:del")))
        ajouter("sections", len(xpath(r, ".//w:sectPr")))
    return compte


def avertissements(inv: dict) -> list[str]:
    return [texte_ for cle, texte_ in NON_PRIS_EN_CHARGE.items() if inv.get(cle)]
