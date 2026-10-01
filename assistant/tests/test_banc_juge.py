from banc import juge
from banc.questions import Question
from rag import modeles
from rag.reglages import Reglages

Q = Question("F04", "simple", "Pression de gonflage ?", ["1 450 kPa"], ["x y z w"], ["m"])
QA = Question("F32", "absente", "Couple de serrage ?", [], [], [])


def test_lire_verdict_json_entoure_de_texte():
    v = juge.lire_verdict('Voici : ```json\n{"juste": true, "fidele": false, "justification": "invente 3 h"}\n```')
    assert v == {"juste": True, "fidele": False, "justification": "invente 3 h"}


def test_lire_verdict_illisible():
    assert juge.lire_verdict("je pense que oui") is None
    assert juge.lire_verdict("") is None


def test_juger_question_normale(monkeypatch):
    envoyes = []

    def faux(messages, r, temperature):
        envoyes.append((messages, temperature))
        return '{"juste": true, "fidele": true, "justification": "valeur exacte"}'

    monkeypatch.setattr(modeles, "discuter", faux)
    v = juge.juger(Q, "Elle est de 1 450 kPa.", "extraits…", Reglages())

    assert v == {"juste": True, "fidele": True, "refus_correct": None, "note": "ok", "justification": "valeur exacte"}
    assert envoyes[0][1] == 0
    assert "1 450 kPa" in envoyes[0][0][-1]["content"]


def test_juger_question_absente_sans_appel_si_refus_exact(monkeypatch):
    monkeypatch.setattr(modeles, "discuter", lambda *a: (_ for _ in ()).throw(AssertionError("pas d'appel")))
    v = juge.juger(QA, "Je ne trouve pas cette information dans les documents du secteur BANC.", "", Reglages())
    assert v["refus_correct"] is True and v["note"] == "ok"


def test_juger_question_absente_repondue(monkeypatch):
    monkeypatch.setattr(modeles, "discuter",
                        lambda *a: '{"refus_correct": false, "fidele": false, "justification": "invente 90 Nm"}')
    v = juge.juger(QA, "Le couple est de 90 Nm.", "extraits", Reglages())
    assert v["refus_correct"] is False and v["juste"] is None


def test_juger_illisible_et_erreur(monkeypatch):
    monkeypatch.setattr(modeles, "discuter", lambda *a: "bof")
    assert juge.juger(Q, "r", "e", Reglages())["note"] == "illisible"

    def panne(*a):
        raise modeles.ModeleIndisponible("délai dépassé")

    monkeypatch.setattr(modeles, "discuter", panne)
    v = juge.juger(Q, "r", "e", Reglages())
    assert v["note"] == "erreur" and "délai dépassé" in v["justification"]
