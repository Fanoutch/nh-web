"""Recherche par mots-clés (BM25) : retrouve les codes, sigles et références que la
recherche vectorielle rate. Bibliothèque standard uniquement (miroir pip du bureau)."""
from __future__ import annotations

import math
import re
import unicodedata
from collections import Counter

K1 = 1.5
B = 0.75

MOTS_VIDES = frozenset(
    "le la les de des du un une et ou a au aux en est sont pour par sur dans que qui quel quelle "
    "quels quelles ce cette il elle on se ne pas plus avec son sa ses leur leurs d l".split()
)
_CODE = re.compile(r"[a-z0-9]+(?:[-./][a-z0-9]+)+")
_MOT = re.compile(r"[a-z0-9]+")
_PARTIE = re.compile(r"[a-z]+|[0-9]+")
_SEPARATEURS = re.compile(r"[-./]")


def _ascii(texte: str) -> str:
    # Apostrophes typographiques (Word produit des U+2019) remplacées par une espace, comme la droite
    # U+0027 : sinon « l\u2019huile » donnerait « lhuile ». Écrites en échappements, jamais en littéraux.
    for apostrophe in ("'", "\u2019", "\u2018", "\u00b4", "\u02bc"):
        texte = texte.replace(apostrophe, " ")
    # Convertir les ligatures avant NFKD pour éviter qu'elles disparaissent
    texte = texte.replace("\u0153", "oe").replace("\u0152", "oe").replace("\u00e6", "ae").replace("\u00c6", "ae")
    return unicodedata.normalize("NFKD", texte.lower()).encode("ascii", "ignore").decode()


def mots(texte: str) -> list[str]:
    """Mots utiles d'un texte : codes collés d'abord (« hyd-112 » → « hyd112 »), puis
    chaque mot, lettres et chiffres séparés (« pales100 » → « pales », « 100 »)."""
    t = _ascii(texte)
    resultat = [_SEPARATEURS.sub("", code) for code in _CODE.findall(t)]
    for brut in _MOT.findall(t):
        resultat.extend(p for p in _PARTIE.findall(brut) if p not in MOTS_VIDES)
    return resultat


def codes(texte: str) -> set[str]:
    """Codes du texte (mots reliés par tiret, point ou barre) sous leur forme collée : « HYD-112 » → « hyd112 »."""
    return {_SEPARATEURS.sub("", code) for code in _CODE.findall(_ascii(texte))}


def _fragments_de_codes(texte: str) -> set[str]:
    """Morceaux des codes du texte (« hyd », « 112 » pour « HYD-112 »)."""
    return {p for code in _CODE.findall(_ascii(texte)) for p in _PARTIE.findall(code)}


class IndexMotsCles:
    """Index BM25 en mémoire : id de morceau → compte des mots."""

    def __init__(self) -> None:
        self._docs: dict[str, Counter] = {}
        self._longueurs: dict[str, int] = {}
        self._df: Counter = Counter()

    def __len__(self) -> int:
        return len(self._docs)

    def _retirer_un(self, id_: str) -> None:
        compte = self._docs.pop(id_)
        self._longueurs.pop(id_)
        for terme in compte:   # seulement les mots du morceau retiré, pas tout le vocabulaire
            reste = self._df[terme] - 1
            if reste > 0:
                self._df[terme] = reste
            else:
                del self._df[terme]

    def connait(self, terme: str) -> bool:
        """Vrai si `terme` (forme normalisée, ex. « hyd112 ») figure dans au moins un morceau."""
        return self._df.get(terme, 0) > 0

    def ajouter(self, ids: list[str], textes: list[str]) -> None:
        for id_, texte in zip(ids, textes):
            if id_ in self._docs:
                self._retirer_un(id_)
            compte = Counter(mots(texte))
            self._docs[id_] = compte
            self._longueurs[id_] = sum(compte.values())
            self._df.update(compte.keys())

    def retirer(self, prefixe: str) -> None:
        for id_ in [i for i in self._docs if i.startswith(prefixe)]:
            self._retirer_un(id_)

    def chercher(self, question: str, n: int) -> list[tuple[str, float]]:
        total = len(self._docs)
        termes = set(mots(question))
        if total == 0 or n <= 0 or not termes:
            return []
        moyenne = sum(self._longueurs.values()) / total or 1.0
        scores = []
        for id_, compte in self._docs.items():
            score = 0.0
            for terme in termes:
                tf = compte.get(terme, 0)
                if not tf:
                    continue
                idf = math.log(1 + (total - self._df[terme] + 0.5) / (self._df[terme] + 0.5))
                score += idf * tf * (K1 + 1) / (tf + K1 * (1 - B + B * self._longueurs[id_] / moyenne))
            if score > 0:
                scores.append((id_, score))
        scores.sort(key=lambda s: (-s[1], s[0]))
        return scores[:n]

    def rares(self, question: str, part_max: float = 0.10) -> set[str]:
        """Mots de la question présents dans peu de morceaux (au moins un) : codes, sigles.

        Les fragments d'un code de la question ne comptent pas (seule sa forme collée compte) :
        « HYD-113 » ne doit pas retrouver « HYD-112 » par « hyd »."""
        limite = max(1, math.floor(part_max * len(self._docs)))
        termes = set(mots(question)) - (_fragments_de_codes(question) - codes(question))
        return {t for t in termes if 0 < self._df.get(t, 0) <= limite}

