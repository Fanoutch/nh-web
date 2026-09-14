"""Tests fumigènes des outils d'autodiagnostic."""
from datetime import date

import pytest

import config
import check_setup
import inspect_csv
import make_fixtures as mf


@pytest.fixture
def sandbox(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "INCOMING_DIR", tmp_path / "incoming")
    monkeypatch.setattr(config, "OUTPUT_DIR", tmp_path / "output")
    monkeypatch.setattr(config, "ARCHIVE_DIR", tmp_path / "archive")
    monkeypatch.setattr(config, "LOG_DIR", tmp_path / "logs")
    monkeypatch.setattr(config, "TEMPLATE_PATH", tmp_path / "template.xlsx")
    mf.make_template(config.TEMPLATE_PATH)
    csv = mf.make_csv(config.INCOMING_DIR, date(2026, 9, 3))
    monkeypatch.setattr(check_setup, "_problems", 0)
    return tmp_path, csv


def test_check_setup_ok_on_fixtures(sandbox, capsys):
    assert check_setup.main([]) == 0
    out = capsys.readouterr().out
    assert "[KO]" not in out
    assert "placeholder" in out


def test_check_setup_detects_formula_target(sandbox, monkeypatch, capsys):
    monkeypatch.setattr(config, "CELL_MAPPING", {"COLONNE_A_DEFINIR_1": "B5"})
    assert check_setup.main([]) == 1
    assert "formule" in capsys.readouterr().out


def test_check_setup_detects_missing_sheet(sandbox, monkeypatch, capsys):
    monkeypatch.setattr(config, "TARGET_SHEET", "Inexistant")
    assert check_setup.main([]) == 1
    assert "absent" in capsys.readouterr().out


def test_check_setup_detects_wrong_separator(sandbox, monkeypatch, capsys):
    monkeypatch.setattr(config, "CSV_READ_KWARGS", {"sep": ",", "encoding": "utf-8-sig"})
    assert check_setup.main([]) == 1
    assert "séparateur" in capsys.readouterr().out


def test_inspect_csv_detects_format_and_dates(sandbox, capsys):
    _, csv = sandbox
    assert inspect_csv.main([str(csv)]) == 0
    out = capsys.readouterr().out
    assert '"sep": \';\'' in out
    assert "utf-8-sig" in out
    assert "parse_dates" in out and "COLONNE_A_DEFINIR_1" in out
    assert "TABLE_MAPPING" in out


def test_inspect_csv_cp1252_comma(tmp_path, capsys):
    p = tmp_path / "x.csv"
    p.write_bytes("Nom,Montant\nCafé,\"12,5\"\nThé,\"3,25\"\n".encode("cp1252"))
    assert inspect_csv.main([str(p)]) == 0
    out = capsys.readouterr().out
    assert "cp1252" in out and '"sep": \',\'' in out and '"decimal": \',\'' in out
