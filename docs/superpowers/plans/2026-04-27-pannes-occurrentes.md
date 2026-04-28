# Pannes occurrentes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the inter-flight recurrent failure detection module per `docs/superpowers/specs/2026-04-27-pannes-occurrentes-design.md`. A new Python module per machine maintains a 3-flight sliding window (≥2/3 active, 0/3 archive), persists to JSON, and is surfaced to the Laravel front (machine bubble counter + 2 detail tabs).

**Architecture:** New module `src/reporting/recurrent_failures.py` consumes `pannes_conservees` produced in-memory by `date_filter.filter_by_flight_date`. Hooks into `process_file()` between Phase 1 and Phase 3. Persists `occurrentes.json` (working state) and `archive.json` (archived episodes) per machine under `data/reports/occurrentes/{HcId}/`. 150-day cap on processed XMLs and on `vols_history` retention. `archive.json` never purged. Laravel `MachineController` reads the JSONs and exposes counts to the bubble grid plus two new tabs (`occurrentes`, `occurrentes-archive`).

**Tech Stack:** Python 3.12, lxml, pytest. Laravel 11 (Blade + Eloquent + PHP).

---

## File Structure

**Created:**
- `src/reporting/recurrent_failures.py` — main module
- `tests/conftest.py` — fake XML generator fixture (shared across tests)
- `tests/reporting/__init__.py` — empty package marker
- `tests/reporting/test_recurrent_failures.py` — pytest scenarios (~14 tests)

**Modified:**
- `main.py` — add `update_recurrent_failures()` call between Phase 1 and Phase 3
- `run.py` — add `Occurrentes actives: N (+A/-D)` to per-vol log line
- `web/app/Http/Controllers/MachineController.php` — read JSONs in `index()` and `show()`
- `web/resources/views/machines/index.blade.php` — 4th counter on the bubble
- `web/resources/views/machines/show.blade.php` — 2 new tabs
- `CLAUDE.md` — document the new phase

**Generated at runtime (not committed):**
- `data/reports/occurrentes/{HcId}/occurrentes.json`
- `data/reports/occurrentes/{HcId}/archive.json`

---

## Task 1: Test infrastructure (fake XML generator)

Sets up a shared pytest fixture that generates minimal valid XMLs which pass Phase 1 and Phase 2.

**Files:**
- Create: `tests/conftest.py`
- Create: `tests/reporting/__init__.py`

- [ ] **Step 1: Create empty package marker**

```bash
mkdir -p tests/reporting
touch tests/reporting/__init__.py
```

- [ ] **Step 2: Write `tests/conftest.py`**

```python
"""Shared pytest fixtures for the test suite."""
from datetime import datetime, timedelta
from pathlib import Path
import pytest


def _fmt_xml_dt(dt):
    """XML datetime format used by the pipeline (DD/MM/YYYY HH:MM:SS)."""
    return dt.strftime("%d/%m/%Y %H:%M:%S")


def _make_xml(hc_id, dsn, num, start_dt, end_dt, te_ids, type_vol="FLIGHT", flight_hours=3600.0):
    """Build a minimal HUMS-like XML string accepted by Phase 1 & Phase 2.

    The XML mirrors the structure expected by date_filter and engine_filter:
    - <HC> root
    - <XmlHeader>/<HcIdentification>/<HcId>
    - <Flight-Usage>/<DSN>/<Header>/<DSN>
    - <Flight-Usage>/<DSN>/<FlightData>/<Flight> with <Type>, <StartDateTime>, <EndDateTime>
    - One <FlightUsageMetric> with FLIGHT_HOURS so Phase FH writes data
    - One <TechnicalEvent> per te_id, with RaiseDateTime = end_dt + 5min (within ±48h tolerance)
    """
    raise_dt = end_dt + timedelta(minutes=5)
    te_blocks = "".join(
        f"""<TechnicalEvent>
            <TE-Identification>
                <TechnicalEventId>{te_id}</TechnicalEventId>
                <TechnicalEventDescription>Desc {te_id}</TechnicalEventDescription>
                <RaiseDateTime>{_fmt_xml_dt(raise_dt)}</RaiseDateTime>
                <TechnicalEventTypeId>WARNING</TechnicalEventTypeId>
                <TypeDescription>Warning</TypeDescription>
                <StatusId>OPEN</StatusId>
                <StatusDescription>Open</StatusDescription>
                <SystemType>{te_id[:3]}</SystemType>
                <SystemDescription>System for {te_id}</SystemDescription>
            </TE-Identification>
            <TE-Type>
                <HumsSource>
                    <FailureCode>{te_id}-CODE</FailureCode>
                </HumsSource>
            </TE-Type>
        </TechnicalEvent>"""
        for te_id in te_ids
    )
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<HC>
    <XmlHeader>
        <HcIdentification><HcId>{hc_id}</HcId></HcIdentification>
    </XmlHeader>
    <Flight-Usage>
        <DSN>
            <Header><DSN>{dsn}</DSN></Header>
            <FlightData>
                <Flight>
                    <Type>{type_vol}</Type>
                    <StartDateTime>{_fmt_xml_dt(start_dt)}</StartDateTime>
                    <EndDateTime>{_fmt_xml_dt(end_dt)}</EndDateTime>
                    <FlightHours>1</FlightHours>
                    <ConsumedFuel>10</ConsumedFuel>
                </Flight>
            </FlightData>
            <FlightUsageMetric>
                <MetricCode>FLIGHT_HOURS</MetricCode>
                <CurrentValue>{flight_hours}</CurrentValue>
            </FlightUsageMetric>
            <DsnParameters>
                <DsnParameter>
                    <DsnParameterName>ROTOR STARTS</DsnParameterName>
                    <CurrentValue>1</CurrentValue>
                </DsnParameter>
            </DsnParameters>
            {te_blocks}
        </DSN>
    </Flight-Usage>
</HC>
"""


@pytest.fixture
def make_fake_xml(tmp_path):
    """Factory fixture: returns a callable that writes a fake XML and returns its Path."""
    counter = {"n": 0}

    def _factory(hc_id, end_datetime, te_ids=None, start_datetime=None,
                 type_vol="FLIGHT", flight_hours=3600.0):
        if te_ids is None:
            te_ids = []
        if start_datetime is None:
            start_datetime = end_datetime - timedelta(hours=1)
        counter["n"] += 1
        # num is unique within a test run, used as the trailing _N in the file name
        num = 600 + counter["n"]
        dsn = num  # DSN equals num for simplicity in fake data
        xml_str = _make_xml(hc_id, dsn, num, start_datetime, end_datetime, te_ids,
                            type_vol=type_vol, flight_hours=flight_hours)
        # File name pattern matches the regex in main.py: r'_(\d+)\.xml$'
        file_path = tmp_path / f"{num}_FAKE_{num}.xml"
        file_path.write_text(xml_str)
        return file_path

    return _factory
```

- [ ] **Step 3: Write a smoke test that verifies the generator produces XMLs the pipeline accepts**

Create `tests/reporting/test_recurrent_failures.py`:

```python
"""Tests for the inter-flight recurrent failure detection module."""
import json
import sys
from datetime import datetime, timedelta
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT))


def test_fake_xml_passes_phase_2(make_fake_xml, tmp_path):
    """Generator produces an XML accepted by engine_filter as a real flight."""
    from src.cleaning.engine_filter import is_flight
    xml = make_fake_xml(
        hc_id="NH08",
        end_datetime=datetime(2026, 4, 1, 12, 0, 0),
        te_ids=["TE-1234"],
    )
    is_real, flight_type = is_flight(str(xml))
    assert is_real is True
    assert flight_type == "FLIGHT"


def test_fake_xml_processes_with_main(make_fake_xml, tmp_path):
    """Generator XML runs through process_file end-to-end without error."""
    from main import process_file
    xml = make_fake_xml(
        hc_id="NH08",
        end_datetime=datetime(2026, 4, 1, 12, 0, 0),
        te_ids=["TE-1234"],
    )
    out_dir = tmp_path / "out"
    result = process_file(str(xml), output_base=str(out_dir))
    assert result["status"] == "ok"
    assert result["hc_id"] == "NH08"
```

- [ ] **Step 4: Run the smoke tests, verify PASS**

```bash
cd /root/camille2/nh_project
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: both tests PASS.

- [ ] **Step 5: Commit**

```bash
git add tests/conftest.py tests/reporting/__init__.py tests/reporting/test_recurrent_failures.py
git commit -m "test(occurrentes): add fake XML generator fixture

Provides make_fake_xml factory used by the recurrent_failures test
suite to spawn minimal XMLs accepted by Phase 1 and Phase 2."
```

---

## Task 2: Module skeleton

Stand up the module file with the public function returning the expected shape and constants.

**Files:**
- Create: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py` (add skeleton test)

- [ ] **Step 1: Add the failing test**

Append to `tests/reporting/test_recurrent_failures.py`:

```python
def test_module_returns_expected_shape(make_fake_xml, tmp_path):
    """update_recurrent_failures returns a dict with the documented keys."""
    from src.reporting.recurrent_failures import update_recurrent_failures
    result = update_recurrent_failures(
        hc_id="NH08",
        vol_source="NH08_2026-04-01_601",
        end_datetime=datetime(2026, 4, 1, 12, 0, 0),
        pannes_conservees=[],
        output_base=str(tmp_path),
    )
    for key in ("hc_id", "status", "vols_in_history", "active_count",
                "activations", "deactivations", "occurrentes_path", "archive_path"):
        assert key in result, f"missing key {key}"
    assert result["hc_id"] == "NH08"
    assert result["status"] in ("ok", "skipped")
```

- [ ] **Step 2: Run test, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py::test_module_returns_expected_shape -v
```

Expected: FAIL with `ModuleNotFoundError: No module named 'src.reporting.recurrent_failures'`.

- [ ] **Step 3: Create the module skeleton**

Write `src/reporting/recurrent_failures.py`:

```python
"""Phase Recurrent — Detection des pannes occurrentes inter-vols par machine.

Maintient un etat par machine dans data/reports/occurrentes/{HcId}/ :
- occurrentes.json : working state (vols_history + active)
- archive.json     : historique permanent des episodes desactives

Une panne (TechnicalEventId) est marquee occurrente quand elle apparait dans
au moins 2 des 3 derniers vols chronologiques. Elle est archivee (et retiree
de active) quand elle est absente des 3 derniers vols (score 0/3).

Cap operationnel : 150 jours. XMLs avec end_datetime plus ancien sont ignores.
"""
import os
from datetime import datetime, timedelta

VOLS_HISTORY_RETENTION_DAYS = 150


def _occurrentes_dir(hc_id, output_base):
    return os.path.join(output_base, "reports", "occurrentes", hc_id)


def _occurrentes_path(hc_id, output_base):
    return os.path.join(_occurrentes_dir(hc_id, output_base), "occurrentes.json")


def _archive_path(hc_id, output_base):
    return os.path.join(_occurrentes_dir(hc_id, output_base), "archive.json")


def update_recurrent_failures(hc_id, vol_source, end_datetime, pannes_conservees, output_base):
    """Mettre a jour la detection des pannes occurrentes pour le vol courant.

    Args:
        hc_id: identifiant machine (ex: "NH08").
        vol_source: folder_name unique du vol (ex: "NH08_2026-02-01_612").
        end_datetime: datetime de fin de vol (naive).
        pannes_conservees: liste de dicts panne (sortie de date_filter Phase 1).
        output_base: racine des sorties (ex: "data").

    Returns:
        dict avec status, compteurs, listes d'activations/desactivations, chemins fichiers.
    """
    return {
        "hc_id": hc_id,
        "status": "ok",
        "reason": None,
        "vols_in_history": 0,
        "active_count": 0,
        "activations": [],
        "deactivations": [],
        "occurrentes_path": _occurrentes_path(hc_id, output_base),
        "archive_path": _archive_path(hc_id, output_base),
    }
```

- [ ] **Step 4: Run test, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py::test_module_returns_expected_shape -v
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): add module skeleton

Function signature returns the documented result shape; behavior to be
filled in by subsequent tasks."
```

---

## Task 3: Load/save state with corruption recovery

Add JSON I/O helpers that handle missing files (return defaults), corrupted files (back up + recover), and atomic writes.

**Files:**
- Modify: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing tests**

Append to `tests/reporting/test_recurrent_failures.py`:

```python
def test_load_state_missing_file_returns_default(tmp_path):
    from src.reporting.recurrent_failures import _load_occurrentes
    state = _load_occurrentes(str(tmp_path / "missing.json"), hc_id="NH08")
    assert state == {"hc_id": "NH08", "last_updated": None, "vols_history": [], "active": []}


def test_load_state_existing_valid_file(tmp_path):
    from src.reporting.recurrent_failures import _load_occurrentes
    path = tmp_path / "occurrentes.json"
    payload = {"hc_id": "NH08", "last_updated": "2026-04-01T12:00:00",
               "vols_history": [{"vol": "NH08_2026-04-01_601",
                                  "end_datetime": "2026-04-01T12:00:00",
                                  "te_ids": ["TE-1"]}],
               "active": []}
    path.write_text(json.dumps(payload))
    state = _load_occurrentes(str(path), hc_id="NH08")
    assert state == payload


def test_load_state_corrupted_file_backs_up_and_recovers(tmp_path):
    from src.reporting.recurrent_failures import _load_occurrentes
    path = tmp_path / "occurrentes.json"
    path.write_text("{ not valid json")
    state = _load_occurrentes(str(path), hc_id="NH08")
    assert state["hc_id"] == "NH08"
    assert state["vols_history"] == []
    backups = list(tmp_path.glob("occurrentes.json.corrupted.*"))
    assert len(backups) == 1, f"expected one corrupted backup, found {len(backups)}"


def test_save_state_atomic(tmp_path):
    from src.reporting.recurrent_failures import _save_atomic
    path = tmp_path / "occurrentes.json"
    _save_atomic(str(path), {"hc_id": "NH08", "vols_history": [], "active": []})
    assert path.exists()
    assert json.loads(path.read_text())["hc_id"] == "NH08"
    # No leftover .tmp file
    assert not (tmp_path / "occurrentes.json.tmp").exists()
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "load_state or save_state"
```

Expected: 4 FAIL with `ImportError`.

- [ ] **Step 3: Implement**

Add to `src/reporting/recurrent_failures.py` (after the constants, before `update_recurrent_failures`):

```python
import json
import shutil


def _default_state(hc_id):
    return {"hc_id": hc_id, "last_updated": None, "vols_history": [], "active": []}


def _default_archive(hc_id):
    return {"hc_id": hc_id, "pannes_archivees": []}


def _load_json_or_recover(path, default):
    """Load JSON from path. On corruption, back up to .corrupted.{ts} and return default."""
    if not os.path.exists(path):
        return default
    try:
        with open(path, "r", encoding="utf-8") as f:
            return json.load(f)
    except (json.JSONDecodeError, ValueError):
        ts = datetime.now().strftime("%Y%m%d_%H%M%S")
        shutil.copy2(path, f"{path}.corrupted.{ts}")
        return default


def _load_occurrentes(path, hc_id):
    return _load_json_or_recover(path, _default_state(hc_id))


def _load_archive(path, hc_id):
    return _load_json_or_recover(path, _default_archive(hc_id))


def _save_atomic(path, data):
    """Write JSON atomically: serialize to .tmp, then os.replace()."""
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = f"{path}.tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
    os.replace(tmp, path)
```

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "load_state or save_state"
```

Expected: 4 PASS.

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): add atomic JSON I/O with corruption recovery"
```

---

## Task 4: Chronological insertion in vols_history (with idempotence)

Implement the helper that inserts a new vol into `vols_history`, sorted by `end_datetime` ascending, and replaces an existing entry if `vol` already present.

**Files:**
- Modify: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing tests**

Append:

```python
def test_insert_into_empty_history():
    from src.reporting.recurrent_failures import _insert_vol_chronological
    history = []
    new_history = _insert_vol_chronological(history, {
        "vol": "NH08_2026-04-01_601",
        "end_datetime": "2026-04-01T12:00:00",
        "te_ids": ["TE-1"],
    })
    assert len(new_history) == 1
    assert new_history[0]["vol"] == "NH08_2026-04-01_601"


def test_insert_appends_when_newest():
    from src.reporting.recurrent_failures import _insert_vol_chronological
    history = [{"vol": "v1", "end_datetime": "2026-04-01T00:00:00", "te_ids": []}]
    new_history = _insert_vol_chronological(history, {
        "vol": "v2", "end_datetime": "2026-04-02T00:00:00", "te_ids": [],
    })
    assert [v["vol"] for v in new_history] == ["v1", "v2"]


def test_insert_inserts_in_middle_when_older():
    from src.reporting.recurrent_failures import _insert_vol_chronological
    history = [
        {"vol": "v1", "end_datetime": "2026-04-01T00:00:00", "te_ids": []},
        {"vol": "v3", "end_datetime": "2026-04-03T00:00:00", "te_ids": []},
    ]
    new_history = _insert_vol_chronological(history, {
        "vol": "v2", "end_datetime": "2026-04-02T00:00:00", "te_ids": [],
    })
    assert [v["vol"] for v in new_history] == ["v1", "v2", "v3"]


def test_insert_replaces_existing_vol_idempotent():
    from src.reporting.recurrent_failures import _insert_vol_chronological
    history = [{"vol": "v1", "end_datetime": "2026-04-01T00:00:00", "te_ids": ["TE-OLD"]}]
    new_history = _insert_vol_chronological(history, {
        "vol": "v1", "end_datetime": "2026-04-01T00:00:00", "te_ids": ["TE-NEW"],
    })
    assert len(new_history) == 1
    assert new_history[0]["te_ids"] == ["TE-NEW"]
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "insert_"
```

Expected: 4 FAIL with `ImportError`.

- [ ] **Step 3: Implement**

Add to `src/reporting/recurrent_failures.py`:

```python
def _insert_vol_chronological(vols_history, vol_entry):
    """Insert a vol entry into vols_history sorted by (end_datetime, vol) asc.

    Idempotence: if a vol with the same `vol` key exists, replace it (no duplicate).
    """
    filtered = [v for v in vols_history if v["vol"] != vol_entry["vol"]]
    filtered.append(vol_entry)
    return sorted(filtered, key=lambda v: (v["end_datetime"], v["vol"]))
```

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "insert_"
```

Expected: 4 PASS.

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): add chronological insertion with idempotence"
```

---

## Task 5: 5-month cap (skip XML and purge vols_history)

Apply the 150-day rule: skip processing for old XMLs, prune `vols_history` of stale entries on each update.

**Files:**
- Modify: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing tests**

Append:

```python
def test_xml_older_than_150_days_skipped(tmp_path):
    from src.reporting.recurrent_failures import update_recurrent_failures
    old_dt = datetime.now() - timedelta(days=200)
    result = update_recurrent_failures(
        hc_id="NH08",
        vol_source="NH08_OLD_999",
        end_datetime=old_dt,
        pannes_conservees=[{"TechnicalEventId": "TE-1"}],
        output_base=str(tmp_path),
    )
    assert result["status"] == "skipped"
    assert "5 mois" in (result["reason"] or "") or "150" in (result["reason"] or "")
    # No file should have been created
    occ_dir = tmp_path / "reports" / "occurrentes" / "NH08"
    assert not occ_dir.exists()


def test_vols_history_purged_of_old_entries(tmp_path):
    """Old entries already in vols_history are removed on the next update."""
    from src.reporting.recurrent_failures import update_recurrent_failures, _occurrentes_path
    # Pre-seed an occurrentes.json with one old vol and one fresh vol
    occ_path = _occurrentes_path("NH08", str(tmp_path))
    os.makedirs(os.path.dirname(occ_path), exist_ok=True)
    old_iso = (datetime.now() - timedelta(days=200)).strftime("%Y-%m-%dT%H:%M:%S")
    fresh_iso = (datetime.now() - timedelta(days=10)).strftime("%Y-%m-%dT%H:%M:%S")
    seed = {
        "hc_id": "NH08", "last_updated": None,
        "vols_history": [
            {"vol": "NH08_OLD_500", "end_datetime": old_iso, "te_ids": ["TE-X"]},
            {"vol": "NH08_FRESH_600", "end_datetime": fresh_iso, "te_ids": ["TE-Y"]},
        ],
        "active": [],
    }
    with open(occ_path, "w") as f:
        json.dump(seed, f)
    # Trigger an update with a brand-new vol
    update_recurrent_failures(
        hc_id="NH08",
        vol_source="NH08_NEW_700",
        end_datetime=datetime.now(),
        pannes_conservees=[],
        output_base=str(tmp_path),
    )
    with open(occ_path) as f:
        state = json.load(f)
    vols = [v["vol"] for v in state["vols_history"]]
    assert "NH08_OLD_500" not in vols
    assert "NH08_FRESH_600" in vols
    assert "NH08_NEW_700" in vols
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "older_than_150 or purged"
```

Expected: 2 FAIL.

- [ ] **Step 3: Implement — replace the body of `update_recurrent_failures`**

Replace the function body in `src/reporting/recurrent_failures.py`:

```python
def update_recurrent_failures(hc_id, vol_source, end_datetime, pannes_conservees, output_base):
    pivot = datetime.now() - timedelta(days=VOLS_HISTORY_RETENTION_DAYS)

    # Step 0 : skip si vol > 150 jours
    if end_datetime < pivot:
        return {
            "hc_id": hc_id,
            "status": "skipped",
            "reason": f"vol > 5 mois ({VOLS_HISTORY_RETENTION_DAYS} jours)",
            "vols_in_history": 0,
            "active_count": 0,
            "activations": [],
            "deactivations": [],
            "occurrentes_path": _occurrentes_path(hc_id, output_base),
            "archive_path": _archive_path(hc_id, output_base),
        }

    # Step 1 : load state
    occ_path = _occurrentes_path(hc_id, output_base)
    archive_path = _archive_path(hc_id, output_base)
    state = _load_occurrentes(occ_path, hc_id)

    # Step 2 : purge vols_history > 150 jours
    pivot_iso = pivot.strftime("%Y-%m-%dT%H:%M:%S")
    state["vols_history"] = [v for v in state["vols_history"] if v["end_datetime"] >= pivot_iso]

    # Step 3 : insert vol courant
    te_ids = sorted({p.get("TechnicalEventId") for p in pannes_conservees if p.get("TechnicalEventId")})
    vol_entry = {
        "vol": vol_source,
        "end_datetime": end_datetime.strftime("%Y-%m-%dT%H:%M:%S"),
        "te_ids": te_ids,
    }
    state["vols_history"] = _insert_vol_chronological(state["vols_history"], vol_entry)

    # Step 7 : save (activations/desactivations a venir dans Tasks 6 et 7)
    state["last_updated"] = datetime.now().strftime("%Y-%m-%dT%H:%M:%S")
    _save_atomic(occ_path, state)

    return {
        "hc_id": hc_id,
        "status": "ok",
        "reason": None,
        "vols_in_history": len(state["vols_history"]),
        "active_count": len(state["active"]),
        "activations": [],
        "deactivations": [],
        "occurrentes_path": occ_path,
        "archive_path": archive_path,
    }
```

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: all tests so far PASS (including the new ones).

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): apply 150-day cap (skip + purge)"
```

---

## Task 6: Score calculation + activation

Detect pannes that crossed the activation threshold (≥ 2 in last_3) and add them to `active`.

**Files:**
- Modify: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing tests**

Append:

```python
def _run_chain(make_fake_xml, tmp_path, vols):
    """Helper: process a list of (date, te_ids) tuples for NH08 and return final state."""
    from main import process_file
    for end_dt, te_ids in vols:
        xml = make_fake_xml(hc_id="NH08", end_datetime=end_dt, te_ids=te_ids)
        process_file(str(xml), output_base=str(tmp_path))
    occ_path = tmp_path / "reports" / "occurrentes" / "NH08" / "occurrentes.json"
    return json.loads(occ_path.read_text())


def test_premiere_panne_pas_active(make_fake_xml, tmp_path):
    """1 vol with 1 TE -> active stays empty."""
    state = _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
    ])
    assert state["active"] == []


def test_activation_sur_2_2(make_fake_xml, tmp_path):
    """2 vols same TE -> active with score 2/2."""
    state = _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), ["TE-1"]),
    ])
    assert len(state["active"]) == 1
    assert state["active"][0]["id"] == "TE-1"
    assert state["active"][0]["score"] == "2/2"


def test_activation_sur_2_3(make_fake_xml, tmp_path):
    """3 vols, TE in 2 of them -> active with score 2/3."""
    state = _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), []),
        (datetime(2026, 4, 3, 12, 0, 0), ["TE-1"]),
    ])
    assert len(state["active"]) == 1
    assert state["active"][0]["id"] == "TE-1"
    assert state["active"][0]["score"] == "2/3"


def test_pas_activation_a_1_3(make_fake_xml, tmp_path):
    """3 vols, TE in only 1 -> not active (zone tampon)."""
    state = _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), []),
        (datetime(2026, 4, 2, 12, 0, 0), []),
        (datetime(2026, 4, 3, 12, 0, 0), ["TE-1"]),
    ])
    assert state["active"] == []
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "premiere_panne or activation_sur or pas_activation"
```

Expected: 4 FAIL (active list is empty for activation tests).

- [ ] **Step 3: Implement — score + activation**

Add to `src/reporting/recurrent_failures.py`:

```python
def _compute_scores(last_3):
    """Return {te_id: count} for every TE_id appearing in last_3."""
    scores = {}
    for vol in last_3:
        for te_id in vol["te_ids"]:
            scores[te_id] = scores.get(te_id, 0) + 1
    return scores


def _build_active_entry(te_id, score, denom, vol_source, end_datetime, pannes_conservees, last_3):
    """Build an active[] entry, prefering details from the current vol's pannes."""
    details = None
    for panne in pannes_conservees:
        if panne.get("TechnicalEventId") == te_id:
            details = panne
            break
    return {
        "id": te_id,
        "description": (details or {}).get("TechnicalEventDescription"),
        "system": (details or {}).get("SystemDescription"),
        "failure_code": (details or {}).get("FailureCode"),
        "type": (details or {}).get("TypeDescription"),
        "score": f"{score}/{denom}",
        "active_depuis_vol": vol_source,
        "active_depuis_date": end_datetime.strftime("%Y-%m-%d"),
    }
```

Then **modify the body of `update_recurrent_failures`** — between the `state["vols_history"] = _insert_vol_chronological(...)` line and the `state["last_updated"] = ...` line, insert:

```python
    # Step 4-5 : compute scores on last 3 chronological vols
    last_3 = state["vols_history"][-3:]
    denom = len(last_3)
    scores = _compute_scores(last_3)

    # Step 6 : activations (>= 2 in last_3 and not yet in active)
    active_ids = {a["id"] for a in state["active"]}
    activations = []
    for te_id, n in scores.items():
        if n >= 2 and te_id not in active_ids:
            entry = _build_active_entry(te_id, n, denom, vol_source, end_datetime,
                                         pannes_conservees, last_3)
            state["active"].append(entry)
            activations.append(te_id)

    # Update score on existing active entries (they may have moved from 2/3 to 3/3 etc.)
    for entry in state["active"]:
        if entry["id"] in scores:
            entry["score"] = f"{scores[entry['id']]}/{denom}"
```

And update the return dict:
```python
        "active_count": len(state["active"]),
        "activations": activations,
```

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: all activation-related tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): activation logic on 2/3 threshold"
```

---

## Task 7: Deactivation + archive

Detect pannes that hit 0/3 in `last_3`, archive them with duration metrics, remove from `active`.

**Files:**
- Modify: `src/reporting/recurrent_failures.py`
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing tests**

Append:

```python
def test_zone_tampon_apres_activation(make_fake_xml, tmp_path):
    """Active panne stays active when it disappears for 1 vol (zone tampon, score 2/3)."""
    state = _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), ["TE-1"]),  # activation here (2/2)
        (datetime(2026, 4, 3, 12, 0, 0), []),         # absent, score 2/3
    ])
    assert len(state["active"]) == 1
    assert state["active"][0]["id"] == "TE-1"


def test_desactivation_apres_3_absences(make_fake_xml, tmp_path):
    """Active panne archived after 3 consecutive absences (score 0/3)."""
    _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), ["TE-1"]),  # activation
        (datetime(2026, 4, 3, 12, 0, 0), []),
        (datetime(2026, 4, 4, 12, 0, 0), []),
        (datetime(2026, 4, 5, 12, 0, 0), []),         # 0/3 here -> deactivation
    ])
    occ_path = tmp_path / "reports" / "occurrentes" / "NH08" / "occurrentes.json"
    archive_path = tmp_path / "reports" / "occurrentes" / "NH08" / "archive.json"
    state = json.loads(occ_path.read_text())
    archive = json.loads(archive_path.read_text())
    assert state["active"] == []
    assert len(archive["pannes_archivees"]) == 1
    entry = archive["pannes_archivees"][0]
    assert entry["id"] == "TE-1"
    assert entry["active_depuis_date"] == "2026-04-02"
    assert entry["desactivee_le"] == "2026-04-05"
    assert entry["duree_occurrence_jours"] == 3
    assert entry["nombre_vols_actifs"] == 2  # 2 vols with TE-1 in vols_history


def test_reapparition_cree_nouvel_episode(make_fake_xml, tmp_path):
    """A panne that re-appears after archive creates a separate archive entry."""
    _run_chain(make_fake_xml, tmp_path, [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), ["TE-1"]),  # activate
        (datetime(2026, 4, 3, 12, 0, 0), []),
        (datetime(2026, 4, 4, 12, 0, 0), []),
        (datetime(2026, 4, 5, 12, 0, 0), []),         # archive episode 1
        (datetime(2026, 4, 6, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 7, 12, 0, 0), ["TE-1"]),  # re-activate
        (datetime(2026, 4, 8, 12, 0, 0), []),
        (datetime(2026, 4, 9, 12, 0, 0), []),
        (datetime(2026, 4, 10, 12, 0, 0), []),        # archive episode 2
    ])
    archive_path = tmp_path / "reports" / "occurrentes" / "NH08" / "archive.json"
    archive = json.loads(archive_path.read_text())
    assert len(archive["pannes_archivees"]) == 2
    assert archive["pannes_archivees"][0]["active_depuis_date"] == "2026-04-02"
    assert archive["pannes_archivees"][1]["active_depuis_date"] == "2026-04-07"
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "zone_tampon or desactivation or reapparition"
```

Expected: 3 FAIL.

- [ ] **Step 3: Implement — deactivation + archive**

Add to `src/reporting/recurrent_failures.py`:

```python
def _archive_entry(active_entry, vol_source, end_datetime, vols_history):
    """Build an archive entry from an active entry being archived now."""
    from datetime import datetime as _dt
    active_date = _dt.strptime(active_entry["active_depuis_date"], "%Y-%m-%d").date()
    end_date = end_datetime.date()
    duree = (end_date - active_date).days

    # Count vols between active_depuis_date and end_datetime that contain this TE
    active_dt_iso = active_entry["active_depuis_date"] + "T00:00:00"
    end_iso = end_datetime.strftime("%Y-%m-%dT%H:%M:%S")
    nombre_vols_actifs = sum(
        1 for v in vols_history
        if active_dt_iso <= v["end_datetime"] <= end_iso and active_entry["id"] in v["te_ids"]
    )

    return {
        "id": active_entry["id"],
        "description": active_entry.get("description"),
        "system": active_entry.get("system"),
        "failure_code": active_entry.get("failure_code"),
        "type": active_entry.get("type"),
        "active_depuis_vol": active_entry["active_depuis_vol"],
        "active_depuis_date": active_entry["active_depuis_date"],
        "desactivee_au_vol": vol_source,
        "desactivee_le": end_datetime.strftime("%Y-%m-%d"),
        "duree_occurrence_jours": duree,
        "nombre_vols_actifs": nombre_vols_actifs,
    }
```

Then **modify the body of `update_recurrent_failures`** — between the active-score-update loop and the `state["last_updated"] = ...` line, insert:

```python
    # Step 6b : deactivations (active TE with score 0 in last_3)
    deactivations = []
    surviving_active = []
    archive_entries_to_add = []
    for entry in state["active"]:
        if scores.get(entry["id"], 0) == 0:
            archive_entries_to_add.append(_archive_entry(entry, vol_source, end_datetime, state["vols_history"]))
            deactivations.append(entry["id"])
        else:
            surviving_active.append(entry)
    state["active"] = surviving_active

    # Persist archive if any deactivations
    if archive_entries_to_add:
        archive_data = _load_archive(archive_path, hc_id)
        archive_data["pannes_archivees"].extend(archive_entries_to_add)
        _save_atomic(archive_path, archive_data)
```

And update the return dict:
```python
        "deactivations": deactivations,
```

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: all tests PASS so far.

- [ ] **Step 5: Commit**

```bash
git add src/reporting/recurrent_failures.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): deactivation + archive on 0/3 threshold"
```

---

## Task 8: Hook in main.py + log update in run.py

Wire the module into the pipeline and surface the activation/deactivation delta in the batch log.

**Files:**
- Modify: `main.py:119-121` (after Phase 1, before Phase 3)
- Modify: `run.py` (the per-XML log line)
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Write failing test for the hook**

Append to `tests/reporting/test_recurrent_failures.py`:

```python
def test_phase_recurrent_in_process_file_result(make_fake_xml, tmp_path):
    from main import process_file
    xml = make_fake_xml(
        hc_id="NH08",
        end_datetime=datetime(2026, 4, 1, 12, 0, 0),
        te_ids=["TE-1"],
    )
    result = process_file(str(xml), output_base=str(tmp_path))
    assert "phase_recurrent" in result
    assert result["phase_recurrent"]["hc_id"] == "NH08"
    assert result["phase_recurrent"]["status"] == "ok"


def test_bff_skips_recurrent_module(make_fake_xml, tmp_path):
    from main import process_file
    xml = make_fake_xml(
        hc_id="NH08",
        end_datetime=datetime(2026, 4, 1, 12, 0, 0),
        te_ids=["TE-1"],
        type_vol="GROUND",
    )
    result = process_file(str(xml), output_base=str(tmp_path))
    assert result["status"] == "no_engine"
    # phase_recurrent should be None or absent
    assert not result.get("phase_recurrent")
    # No occurrentes file should exist
    assert not (tmp_path / "reports" / "occurrentes").exists()
```

- [ ] **Step 2: Run tests, verify FAIL**

```bash
pytest tests/reporting/test_recurrent_failures.py -v -k "phase_recurrent_in or bff_skips"
```

Expected: FAIL with `assert "phase_recurrent" in result`.

- [ ] **Step 3: Modify `main.py`**

In `main.py`, find the existing block (around line 119):

```python
        # Phase 1 : filtrage par date (uniquement si vol confirme)
        # En mode report_only : pas d'ecriture disque, pannes recuperees en memoire
        filter_stats = filter_by_flight_date(
            xml_path, clean_xml_dir, write_files=not report_only,
            filtre_strict=filtre_strict, filtre_mode=filtre_mode,
        )
        result["phase1"] = filter_stats

        # Phase 3 : releve hebdomadaire
```

Insert between `result["phase1"] = filter_stats` and `# Phase 3` :

```python
        # Phase Recurrent : detection des pannes occurrentes inter-vols
        from src.reporting.recurrent_failures import update_recurrent_failures
        recurrent_result = update_recurrent_failures(
            hc_id=hc_id,
            vol_source=folder_name,
            end_datetime=end_dt,
            pannes_conservees=filter_stats["pannes_conservees"],
            output_base=output_base,
        )
        result["phase_recurrent"] = recurrent_result

```

Also add to the result dict initialization (around line 47):
```python
        "phase_recurrent": None,
```

(insert it next to the other `"phase*": None` keys.)

- [ ] **Step 4: Run tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: all tests PASS.

- [ ] **Step 5: Modify `run.py` log line**

Find the existing log line for OK vols in `run.py` (search for `TE conservés`). It should look like:
```python
log_msg = f"→ OK | {annee} | {semaine} | TE conservés: {te_kept}/{te_total} | Pannes ajoutées: {pannes_ajoutees} | FH: {fh}h"
```

Replace with:
```python
phase_rec = result.get("phase_recurrent") or {}
active_count = phase_rec.get("active_count", 0)
n_act = len(phase_rec.get("activations", []))
n_deact = len(phase_rec.get("deactivations", []))
log_msg = (
    f"→ OK | {annee} | {semaine} | TE conservés: {te_kept}/{te_total} "
    f"| Pannes ajoutées: {pannes_ajoutees} | FH: {fh}h "
    f"| Occurrentes actives: {active_count} (+{n_act}/-{n_deact})"
)
```

(Adapt variable names to whatever `run.py` already uses — locate the line by searching for `TE conservés`.)

- [ ] **Step 6: Manual verification of run.py log**

```bash
# Set up a fake input dir with one of the existing raw XMLs
mkdir -p /tmp/run_test_input
cp raw/2405_POST_DWNLD.HUMS_EXPORT_2405_623.xml /tmp/run_test_input/

# Create a temp settings.yaml pointing there
cat > /tmp/run_test_settings.yaml <<EOF
input_dir: /tmp/run_test_input
output_dir: /tmp/run_test_output
report_only: false
filtre_strict: false
EOF

python3 run.py --config /tmp/run_test_settings.yaml 2>&1 | grep "Occurrentes actives"
```

Expected: at least one log line containing `Occurrentes actives: ` with a count and `(+N/-N)` delta.

- [ ] **Step 7: Commit**

```bash
git add main.py run.py tests/reporting/test_recurrent_failures.py
git commit -m "feat(occurrentes): hook into pipeline + batch log line"
```

---

## Task 9: Remaining test scenarios from spec

Cover the spec's 14 mandatory scenarios (those not yet written + edge cases).

**Files:**
- Modify: `tests/reporting/test_recurrent_failures.py`

- [ ] **Step 1: Add the remaining tests**

Append:

```python
def test_xml_hors_ordre_silencieux(make_fake_xml, tmp_path):
    """Inserting an old vol after recent ones leaves last_3 unchanged."""
    from main import process_file
    # Process 3 recent vols
    for end_dt, te_ids in [
        (datetime(2026, 4, 1, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 2, 12, 0, 0), ["TE-1"]),
        (datetime(2026, 4, 3, 12, 0, 0), ["TE-1"]),
    ]:
        process_file(str(make_fake_xml(hc_id="NH08", end_datetime=end_dt, te_ids=te_ids)),
                     output_base=str(tmp_path))

    # State after 3 vols : TE-1 active 3/3
    occ_path = tmp_path / "reports" / "occurrentes" / "NH08" / "occurrentes.json"
    state_before = json.loads(occ_path.read_text())
    assert len(state_before["active"]) == 1

    # Inject an old vol (within 5 mois but older than the 3 above)
    old_xml = make_fake_xml(hc_id="NH08",
                              end_datetime=datetime(2026, 3, 25, 12, 0, 0),
                              te_ids=["TE-OTHER"])
    process_file(str(old_xml), output_base=str(tmp_path))

    state_after = json.loads(occ_path.read_text())
    # Active list unchanged (TE-1 still active, TE-OTHER did not activate because last_3 is still [Apr1, Apr2, Apr3])
    assert len(state_after["active"]) == 1
    assert state_after["active"][0]["id"] == "TE-1"
    # vols_history grew by 1
    assert len(state_after["vols_history"]) == len(state_before["vols_history"]) + 1


def test_idempotence_meme_xml(make_fake_xml, tmp_path):
    """Re-processing the same XML doesn't duplicate vols_history nor archive."""
    from main import process_file
    xml = make_fake_xml(hc_id="NH08", end_datetime=datetime(2026, 4, 1, 12, 0, 0), te_ids=["TE-1"])
    process_file(str(xml), output_base=str(tmp_path))
    # Re-run on the SAME xml (need a way to bypass run.py's "already processed" check —
    # process_file directly does not have that check)
    process_file(str(xml), output_base=str(tmp_path))

    occ_path = tmp_path / "reports" / "occurrentes" / "NH08" / "occurrentes.json"
    state = json.loads(occ_path.read_text())
    # Only one entry in vols_history despite double processing
    vols = state["vols_history"]
    assert len(vols) == 1


def test_xml_corrompu_recovery(tmp_path):
    """Corrupted occurrentes.json is backed up and replaced with default."""
    from src.reporting.recurrent_failures import update_recurrent_failures, _occurrentes_path
    occ_path = _occurrentes_path("NH08", str(tmp_path))
    os.makedirs(os.path.dirname(occ_path), exist_ok=True)
    Path(occ_path).write_text("{ corrupted")

    update_recurrent_failures(
        hc_id="NH08",
        vol_source="NH08_NEW_999",
        end_datetime=datetime.now(),
        pannes_conservees=[{"TechnicalEventId": "TE-1"}],
        output_base=str(tmp_path),
    )
    # A backup file should exist
    occ_dir = Path(occ_path).parent
    backups = list(occ_dir.glob("occurrentes.json.corrupted.*"))
    assert len(backups) == 1
    # And the live file is now valid JSON with our new vol
    state = json.loads(Path(occ_path).read_text())
    assert any(v["vol"] == "NH08_NEW_999" for v in state["vols_history"])


def test_batch_400_vols_multi_machines(make_fake_xml, tmp_path):
    """Smoke test on a moderate batch: ~50 vols across 5 machines."""
    from main import process_file
    base_dt = datetime(2026, 4, 1, 12, 0, 0)
    machines = ["NH08", "NH25", "NH42", "NH50", "NH61"]
    # 10 vols per machine over 10 consecutive days, randomly with TE-1
    for day in range(10):
        for i, hc in enumerate(machines):
            te = ["TE-1"] if (day % 2 == i % 2) else []
            xml = make_fake_xml(
                hc_id=hc,
                end_datetime=base_dt + timedelta(days=day, hours=i),
                te_ids=te,
            )
            process_file(str(xml), output_base=str(tmp_path))

    # All 5 machines should have an occurrentes.json
    for hc in machines:
        occ = tmp_path / "reports" / "occurrentes" / hc / "occurrentes.json"
        assert occ.exists(), f"missing occurrentes for {hc}"
        data = json.loads(occ.read_text())
        # Each machine has 10 vols in history (well below the 150-day cap)
        assert len(data["vols_history"]) == 10
```

- [ ] **Step 2: Run all tests, verify PASS**

```bash
pytest tests/reporting/test_recurrent_failures.py -v
```

Expected: all tests PASS. Total ≥ 14.

- [ ] **Step 3: Commit**

```bash
git add tests/reporting/test_recurrent_failures.py
git commit -m "test(occurrentes): cover full scenario list from design spec"
```

---

## Task 10: Laravel — MachineController reads the JSONs

Expose the active list (count + descriptions) for the bubble grid and the two new tabs (`occurrentes`, `occurrentes-archive`) on the detail page.

**Files:**
- Modify: `web/app/Http/Controllers/MachineController.php`

- [ ] **Step 1: Read the current controller**

```bash
cat web/app/Http/Controllers/MachineController.php
```

Note the existing `index()` and `show()` methods — particularly how the data dir is referenced (relative path from Laravel `base_path()`).

- [ ] **Step 2: Modify `index()` to load occurrentes counts**

Inside `index()`, after the existing `$machines = ...` block (which populates the collection), add a loop:

```php
foreach ($machines as $m) {
    $occPath = base_path('../data/reports/occurrentes/' . $m->hc_id . '/occurrentes.json');
    $m->occurrentes_count = 0;
    $m->occurrentes_descriptions = [];
    if (file_exists($occPath)) {
        $data = json_decode(file_get_contents($occPath), true);
        $active = $data['active'] ?? [];
        $m->occurrentes_count = count($active);
        $m->occurrentes_descriptions = array_map(
            fn($a) => $a['description'] ?? $a['id'],
            $active
        );
    }
}
```

Make sure the variable `$machines` is still passed to `view('machines.index', compact('machines'))`.

- [ ] **Step 3: Modify `show()` to handle the two new tabs**

Inside `show()`, after the existing tab-handling block (around line 30-32 of the original), add:

```php
$occurrentes = [];
$occurrentes_archive = [];

if ($tab === 'occurrentes') {
    $path = base_path('../data/reports/occurrentes/' . $hcId . '/occurrentes.json');
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        $occurrentes = $data['active'] ?? [];
    }
}

if ($tab === 'occurrentes-archive') {
    $path = base_path('../data/reports/occurrentes/' . $hcId . '/archive.json');
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        $occurrentes_archive = $data['pannes_archivees'] ?? [];
        // Trier par desactivee_le desc
        usort($occurrentes_archive, fn($a, $b) => strcmp($b['desactivee_le'] ?? '', $a['desactivee_le'] ?? ''));
    }
}
```

Update the final `return view(...)` to include the new variables :

```php
return view('machines.show', compact('machine', 'tab', 'flights', 'occurrentes', 'occurrentes_archive'));
```

- [ ] **Step 4: Manual verification**

Make sure the Laravel app still boots by running:
```bash
cd web && php artisan route:list 2>&1 | grep machines
```

Expected: routes `machines.index` and `machines.show` listed without error.

- [ ] **Step 5: Commit**

```bash
git add web/app/Http/Controllers/MachineController.php
git commit -m "feat(web): controller reads occurrentes JSONs for index + show"
```

---

## Task 11: Laravel — bubble counter on machines/index

Add a 4th counter in the existing 3-column grid of each machine bubble, with a tooltip listing active descriptions.

**Files:**
- Modify: `web/resources/views/machines/index.blade.php`

- [ ] **Step 1: Read the current view**

```bash
cat web/resources/views/machines/index.blade.php
```

Find the `<div class="grid grid-cols-3 gap-2 mb-4">` block (around line 32) — it currently holds Vols / Non-vols / Erreurs.

- [ ] **Step 2: Convert grid to 4 columns and add the new counter**

Replace `grid-cols-3` with `grid-cols-4`. Add a new counter block AFTER the Erreurs block, mirroring the existing pattern :

```blade
<div class="text-center" title="@foreach (array_slice($m->occurrentes_descriptions, 0, 5) as $desc){{ \Illuminate\Support\Str::limit($desc, 60) }}{{ ', ' }}@endforeach@if (count($m->occurrentes_descriptions) > 5)…et {{ count($m->occurrentes_descriptions) - 5 }} autres@endif">
    <p class="text-2xl font-bold {{ $m->occurrentes_count > 0 ? 'text-amber-600' : 'text-slate-900' }}">
        {{ $m->occurrentes_count }}
    </p>
    <p class="text-[10px] uppercase tracking-wide text-slate-500 font-medium">Occurrentes</p>
</div>
```

- [ ] **Step 3: Manual verification**

```bash
cd web && php artisan view:clear
```

Then load `/machines` in the browser. Verify :
- Each bubble shows 4 counters (Vols, Non-vols, Erreurs, Occurrentes)
- Machines with `occurrentes_count > 0` show the number in amber
- Hover tooltip lists the descriptions of the active occurrentes

- [ ] **Step 4: Commit**

```bash
git add web/resources/views/machines/index.blade.php
git commit -m "feat(web): occurrentes counter on machine bubbles"
```

---

## Task 12: Laravel — two new tabs on machines/show

Add tabs "Occurrentes" and "Occurrentes (historique)" with their tables.

**Files:**
- Modify: `web/resources/views/machines/show.blade.php`

- [ ] **Step 1: Read the current view**

```bash
cat web/resources/views/machines/show.blade.php
```

Note where the tabs nav is rendered and where the tab content is conditionally shown.

- [ ] **Step 2: Add the two new tab buttons**

Inside the tabs nav (after the "Erreurs" tab), add :

```blade
<a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => 'occurrentes']) }}"
   class="px-4 py-2 rounded-lg {{ $tab === 'occurrentes' ? 'bg-amber-100 text-amber-800' : 'text-slate-500 hover:bg-slate-100' }}">
    Occurrentes
</a>
<a href="{{ route('machines.show', ['hcId' => $machine->hc_id, 'tab' => 'occurrentes-archive']) }}"
   class="px-4 py-2 rounded-lg {{ $tab === 'occurrentes-archive' ? 'bg-slate-100 text-slate-800' : 'text-slate-500 hover:bg-slate-100' }}">
    Historique d'occurrences
</a>
```

- [ ] **Step 3: Add the active occurrentes table**

After the existing tab-content blocks (vols / non-vols / erreurs), add :

```blade
@if ($tab === 'occurrentes')
    @if (empty($occurrentes))
        <div class="bg-white rounded-xl border border-slate-200 p-12 text-center text-slate-500">
            Aucune panne occurrente actuellement sur cette machine.
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-2 text-left">ID</th>
                        <th class="px-4 py-2 text-left">Description</th>
                        <th class="px-4 py-2 text-left">Système</th>
                        <th class="px-4 py-2 text-left">Code panne</th>
                        <th class="px-4 py-2 text-left">Type</th>
                        <th class="px-4 py-2 text-left">Score</th>
                        <th class="px-4 py-2 text-left">Active depuis</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($occurrentes as $o)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-2 font-mono">{{ $o['id'] }}</td>
                            <td class="px-4 py-2">{{ $o['description'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $o['system'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $o['failure_code'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $o['type'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-bold text-amber-700">{{ $o['score'] }}</td>
                            <td class="px-4 py-2">{{ $o['active_depuis_date'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
```

- [ ] **Step 4: Add the archive table**

```blade
@if ($tab === 'occurrentes-archive')
    @if (empty($occurrentes_archive))
        <div class="bg-white rounded-xl border border-slate-200 p-12 text-center text-slate-500">
            Aucun épisode d'occurrence archivé pour cette machine.
        </div>
    @else
        <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th class="px-4 py-2 text-left">ID</th>
                        <th class="px-4 py-2 text-left">Description</th>
                        <th class="px-4 py-2 text-left">Système</th>
                        <th class="px-4 py-2 text-left">Active depuis</th>
                        <th class="px-4 py-2 text-left">Désactivée le</th>
                        <th class="px-4 py-2 text-left">Durée (jours)</th>
                        <th class="px-4 py-2 text-left">Vols actifs</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($occurrentes_archive as $a)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-2 font-mono">{{ $a['id'] }}</td>
                            <td class="px-4 py-2">{{ $a['description'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $a['system'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $a['active_depuis_date'] }}</td>
                            <td class="px-4 py-2">{{ $a['desactivee_le'] }}</td>
                            <td class="px-4 py-2">{{ $a['duree_occurrence_jours'] }}</td>
                            <td class="px-4 py-2">{{ $a['nombre_vols_actifs'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif
```

- [ ] **Step 5: Manual verification**

```bash
cd web && php artisan view:clear
```

In the browser :
1. Open `/machines/NH08` (or any machine with `occurrentes_count > 0`)
2. Click the "Occurrentes" tab — table renders with the active list
3. Click the "Historique d'occurrences" tab — table renders the archive
4. Empty-state messages display when no data

- [ ] **Step 6: Commit**

```bash
git add web/resources/views/machines/show.blade.php
git commit -m "feat(web): occurrentes + archive tabs on machine detail"
```

---

## Task 13: Document the new phase in CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the new architecture entry**

In `CLAUDE.md`, in the architecture tree, add `recurrent_failures.py` to `src/reporting/` and add `data/reports/occurrentes/` to the data tree :

Find the existing data tree section that lists `data/reports/weekly/` and `data/FHreport/`, add :

```
│   ├── reports/                     # Phase 3 : releve hebdomadaire des pannes
│   │   ├── weekly/
│   │   │   └── ... (existant)
│   │   └── occurrentes/             # Phase Recurrent : pannes occurrentes inter-vols
│   │       └── NH08/
│   │           ├── occurrentes.json # working state (vols_history + active)
│   │           └── archive.json     # historique des episodes desactives
```

And in `src/reporting/`, add :
```
        └── recurrent_failures.py   # Phase Recurrent : pannes occurrentes inter-vols
```

- [ ] **Step 2: Add a "Phase Recurrent" subsection**

After the existing "Phase FH - Rapport flight hours (FAIT)" section, add :

```markdown
### Phase Recurrent - Detection des pannes occurrentes inter-vols (FAIT)

**Module** : `src/reporting/recurrent_failures.py`

**Declenchement** : Automatique apres Phase 1, avant Phase 3 (uniquement si vol confirme).

**Logique** :
- Fenetre glissante des 3 derniers vols chronologiques de la machine
- Activation : panne (TechnicalEventId) presente dans >= 2 vols sur les 3 derniers
- Desactivation : panne presente dans 0 vol sur les 3 derniers -> archivage
- Cap operationnel : 150 jours (XMLs anciens skip, vols_history purge, archive non purge)

**Sorties** : `data/reports/occurrentes/{HcId}/occurrentes.json` (active list pour le front)
            + `archive.json` (historique permanent des episodes desactives)
```

- [ ] **Step 3: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document Phase Recurrent in CLAUDE.md"
```

---

## Final verification

- [ ] **Run the full test suite**

```bash
cd /root/camille2/nh_project
pytest tests/ -v
```

Expected : all tests PASS, including the original `test_dsn_extraction.py` and the new ~14 tests in `test_recurrent_failures.py`.

- [ ] **Run a real-data smoke check (optional)**

```bash
mkdir -p /tmp/recur_smoke
cp raw/2405_POST_DWNLD.HUMS_EXPORT_2405_623.xml /tmp/recur_smoke/
cat > /tmp/recur_smoke_settings.yaml <<EOF
input_dir: /tmp/recur_smoke
output_dir: /tmp/recur_smoke_out
report_only: false
filtre_strict: false
EOF
python3 run.py --config /tmp/recur_smoke_settings.yaml 2>&1 | tail -20
ls /tmp/recur_smoke_out/reports/occurrentes/
```

Expected : `Occurrentes actives:` line in the log, and an `occurrentes.json` file under the machine's HcId.

- [ ] **Verify Laravel renders the new UI**

Open `/machines` and `/machines/{hcId}?tab=occurrentes` in the browser, confirm visually that the counters and tables render correctly with empty states when applicable.
