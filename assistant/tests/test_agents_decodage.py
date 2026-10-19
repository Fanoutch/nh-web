import json

from agents.decodage import decoder


def test_reponse_sans_outil():
    d = decoder({"content": "Voilà le résultat."})
    assert d.appels == [] and d.texte == "Voilà le résultat." and not d.illisible


def test_format_standard_arguments_texte():
    d = decoder({"content": None, "tool_calls": [
        {"id": "c1", "type": "function", "function": {"name": "excel_decrire", "arguments": '{"fichier": "a.xlsx"}'}}]})
    assert d.format == "standard"
    assert d.appels[0].nom == "excel_decrire" and d.appels[0].arguments == {"fichier": "a.xlsx"}
    assert d.appels[0].identifiant == "c1"


def test_format_standard_arguments_deja_dict_ou_vides():
    d = decoder({"tool_calls": [{"function": {"name": "a", "arguments": {"x": 1}}},
                                {"function": {"name": "b", "arguments": ""}}]})
    assert [a.arguments for a in d.appels] == [{"x": 1}, {}]
    assert [a.identifiant for a in d.appels] == ["appel_0", "appel_1"]


def test_format_standard_arguments_casses():
    d = decoder({"tool_calls": [{"function": {"name": "a", "arguments": "{pas du json"}}]})
    assert d.illisible and "arguments" in d.raison


def test_format_standard_sans_nom():
    assert decoder({"tool_calls": [{"function": {"arguments": "{}"}}]}).illisible


def test_format_texte_liste():
    contenu = '[TOOL_CALLS][{"name": "excel_lire", "arguments": {"fichier": "a.xlsx", "limite": 5}}]'
    d = decoder({"content": contenu})
    assert d.format == "texte"
    assert d.appels[0].nom == "excel_lire" and d.appels[0].arguments == {"fichier": "a.xlsx", "limite": 5}


def test_format_texte_avec_phrase_avant_et_deux_appels():
    contenu = ('Je regarde.\n[TOOL_CALLS] [{"name": "a", "arguments": {}}, {"name": "b", "arguments": {"x": "]"}}]')
    d = decoder({"content": contenu})
    assert d.texte == "Je regarde."
    assert [a.nom for a in d.appels] == ["a", "b"] and d.appels[1].arguments == {"x": "]"}


def test_format_texte_objet_seul_et_parametres():
    d = decoder({"content": '[TOOL_CALLS]{"name": "a", "parameters": {"k": 1}}'})
    assert d.appels[0].arguments == {"k": 1}


def test_format_texte_json_casse():
    d = decoder({"content": '[TOOL_CALLS][{"name": "a", "arguments": {'})
    assert d.illisible and d.appels == []


def test_format_texte_liste_vide_ou_sans_nom():
    assert decoder({"content": "[TOOL_CALLS][]"}).illisible
    assert decoder({"content": '[TOOL_CALLS][{"arguments": {}}]'}).illisible


def test_le_standard_prime_sur_le_texte():
    d = decoder({"content": '[TOOL_CALLS][{"name": "b", "arguments": {}}]',
                 "tool_calls": [{"function": {"name": "a", "arguments": "{}"}}]})
    assert d.format == "standard" and d.appels[0].nom == "a"
    json.dumps(d.texte)
