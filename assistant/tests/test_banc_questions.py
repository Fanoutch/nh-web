import pytest

from banc.questions import charger_questions

ENTETE = "id;type;question;reponse_attendue;passage;documents\n"


def test_charge_le_format_excel_francais(tmp_path):
    chemin = tmp_path / "q.csv"
    chemin.write_bytes(("﻿" + ENTETE +
        "Q1;coupee;Périodicité et report de la V2 ?;50 heures | 5 heures;"
        "La visite V2 est réalisée toutes les 50 heures de vol. | Ce report ne peut excéder 5 heures;manuel-entretien-fictif\n"
        "\n").encode("utf-8"))

    q = charger_questions(chemin)

    assert len(q) == 1
    assert q[0].attendus == ["50 heures", "5 heures"]
    assert len(q[0].passages) == 2
    assert q[0].documents == ["manuel-entretien-fictif"]


def test_charge_aussi_la_virgule(tmp_path):
    chemin = tmp_path / "q.csv"
    chemin.write_text(ENTETE.replace(";", ",") + 'Q2,absente,"Couple de serrage, roues ?",,,\n', encoding="utf-8")
    q = charger_questions(chemin)
    assert q[0].type == "absente" and q[0].passages == [] and q[0].documents == []


def test_type_inconnu_message_clair(tmp_path):
    chemin = tmp_path / "q.csv"
    chemin.write_text(ENTETE + "Q3;facile;x;y;z;d\n", encoding="utf-8")
    with pytest.raises(ValueError, match="Q3.*type « facile »"):
        charger_questions(chemin)


def test_question_non_absente_sans_passage_refusee(tmp_path):
    chemin = tmp_path / "q.csv"
    chemin.write_text(ENTETE + "Q4;simple;x;y;;d\n", encoding="utf-8")
    with pytest.raises(ValueError, match="Q4.*passage"):
        charger_questions(chemin)
