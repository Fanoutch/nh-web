# Rapports mensuels et annuels (pannes + FH) - Plan d'implementation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter des CSV mensuels et annuels qui agregent les rapports hebdomadaires (pannes + flight hours) pour alimenter les dashboards.

**Architecture:** Un nouveau module `src/reporting/aggregate_report.py` lit les JSON hebdomadaires existants (pannes et FH) et regenere les CSV mensuels/annuels a chaque passage du pipeline. Appele depuis `main.py` apres les phases 3 et FH.

**Tech Stack:** Python 3.12+, csv, json, os (pas de nouvelle dependance)

---

### File Map

- **Create:** `src/reporting/aggregate_report.py` — fonctions d'agregation mensuelle/annuelle pour pannes et FH
- **Modify:** `main.py:72-83` — appeler les fonctions d'agregation apres phases 3 et FH

### Sortie attendue

```
data/reports/monthly/{HcId}/
  {HcId}_{YYYY-MM}.csv       # pannes agregees par mois (toutes semaines du mois)
data/reports/yearly/{HcId}/
  {HcId}_{YYYY}.csv           # pannes agregees par annee (toutes semaines)

data/FHreport/monthly/{HcId}/
  {HcId}_{YYYY-MM}.csv       # FH agregees par mois
data/FHreport/yearly/{HcId}/
  {HcId}_{YYYY}.csv           # FH agregees par annee
```

---

### Task 1: Creer aggregate_report.py — agregation pannes mensuelle/annuelle

**Files:**
- Create: `src/reporting/aggregate_report.py`

- [ ] **Step 1: Creer le module avec la fonction d'agregation pannes**

```python
"""Agregation mensuelle et annuelle des rapports hebdomadaires (pannes + FH)."""

import csv
import json
import os


def _find_weekly_jsons(weekly_dir, hc_id):
    """Trouve tous les JSON hebdomadaires pour un HcId donne."""
    hc_dir = os.path.join(weekly_dir, hc_id)
    if not os.path.isdir(hc_dir):
        return []
    jsons = []
    for entry in os.listdir(hc_dir):
        subdir = os.path.join(hc_dir, entry)
        if not os.path.isdir(subdir):
            continue
        for f in os.listdir(subdir):
            if f.endswith(".json"):
                jsons.append(os.path.join(subdir, f))
    return jsons


def _week_to_month(semaine):
    """Convertit une semaine ISO (ex: 2026-W05) en YYYY-MM approximatif.

    Utilise le jeudi de la semaine ISO (qui determine le mois de rattachement
    selon la norme ISO 8601).
    """
    from datetime import datetime, timedelta
    # Lundi de la semaine ISO
    monday = datetime.strptime(semaine + "-1", "%G-W%V-%u")
    # Jeudi = jour de reference ISO pour le mois
    thursday = monday + timedelta(days=3)
    return thursday.strftime("%Y-%m")


def _week_to_year(semaine):
    """Extrait l'annee ISO d'une semaine (ex: 2026-W05 -> 2026)."""
    return semaine.split("-")[0]


def update_pannes_aggregates(hc_id, semaine, reports_base="data"):
    """Regenere les CSV mensuel et annuel des pannes pour le HcId/semaine donnes.

    Lit tous les JSON hebdomadaires du meme mois (et de la meme annee) puis
    ecrit les CSV agreges.

    Args:
        hc_id: identifiant machine (ex: "NH08")
        semaine: semaine ISO du vol traite (ex: "2026-W05")
        reports_base: repertoire racine des sorties

    Returns:
        dict avec les chemins des fichiers generes
    """
    weekly_dir = os.path.join(reports_base, "reports", "weekly")
    monthly_dir = os.path.join(reports_base, "reports", "monthly")
    yearly_dir = os.path.join(reports_base, "reports", "yearly")

    mois = _week_to_month(semaine)
    annee = _week_to_year(semaine)

    # Charger tous les JSON hebdomadaires de ce HcId
    all_jsons = _find_weekly_jsons(weekly_dir, hc_id)
    all_weeks = {}
    for path in all_jsons:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        all_weeks[data["semaine"]] = data

    fichiers = []

    # --- CSV mensuel ---
    mois_semaines = {s: d for s, d in all_weeks.items() if _week_to_month(s) == mois}
    if mois_semaines:
        mois_dir = os.path.join(monthly_dir, hc_id)
        os.makedirs(mois_dir, exist_ok=True)
        mois_path = os.path.join(mois_dir, f"{hc_id}_{mois}.csv")
        fieldnames = ["semaine", "id", "description", "type", "statut", "systeme", "code_panne", "date", "vol_source"]
        with open(mois_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            for sem in sorted(mois_semaines.keys()):
                for panne in mois_semaines[sem]["pannes"]:
                    row = {"semaine": sem}
                    row.update(panne)
                    writer.writerow(row)
        fichiers.append(mois_path)

    # --- CSV annuel ---
    annee_semaines = {s: d for s, d in all_weeks.items() if _week_to_year(s) == annee}
    if annee_semaines:
        annee_dir_path = os.path.join(yearly_dir, hc_id)
        os.makedirs(annee_dir_path, exist_ok=True)
        annee_path = os.path.join(annee_dir_path, f"{hc_id}_{annee}.csv")
        fieldnames = ["semaine", "id", "description", "type", "statut", "systeme", "code_panne", "date", "vol_source"]
        with open(annee_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            for sem in sorted(annee_semaines.keys()):
                for panne in annee_semaines[sem]["pannes"]:
                    row = {"semaine": sem}
                    row.update(panne)
                    writer.writerow(row)
        fichiers.append(annee_path)

    return {"hc_id": hc_id, "mois": mois, "annee": annee, "fichiers": fichiers}
```

- [ ] **Step 2: Ajouter la fonction d'agregation FH**

Ajouter dans le meme fichier :

```python
def _find_fh_jsons(fh_dir, hc_id):
    """Trouve tous les JSON FH hebdomadaires pour un HcId donne."""
    hc_dir = os.path.join(fh_dir, hc_id)
    if not os.path.isdir(hc_dir):
        return []
    jsons = []
    for entry in os.listdir(hc_dir):
        subdir = os.path.join(hc_dir, entry)
        if not os.path.isdir(subdir):
            continue
        for f in os.listdir(subdir):
            if f.endswith(".json"):
                jsons.append(os.path.join(subdir, f))
    return jsons


def update_fh_aggregates(hc_id, semaine, reports_base="data"):
    """Regenere les CSV mensuel et annuel FH pour le HcId/semaine donnes.

    Args:
        hc_id: identifiant machine (ex: "NH08")
        semaine: semaine ISO du vol traite (ex: "2026-W05")
        reports_base: repertoire racine des sorties

    Returns:
        dict avec les chemins des fichiers generes
    """
    fh_dir = os.path.join(reports_base, "FHreport")
    monthly_dir = os.path.join(fh_dir, "monthly")
    yearly_dir = os.path.join(fh_dir, "yearly")

    mois = _week_to_month(semaine)
    annee = _week_to_year(semaine)

    # Charger tous les JSON FH hebdomadaires de ce HcId
    all_jsons = _find_fh_jsons(fh_dir, hc_id)
    all_weeks = {}
    for path in all_jsons:
        with open(path, "r", encoding="utf-8") as f:
            data = json.load(f)
        all_weeks[data["semaine"]] = data

    fichiers = []
    fieldnames = ["semaine", "vol_source", "date_vol", "flight_hours"]

    # --- CSV mensuel ---
    mois_semaines = {s: d for s, d in all_weeks.items() if _week_to_month(s) == mois}
    if mois_semaines:
        mois_dir_path = os.path.join(monthly_dir, hc_id)
        os.makedirs(mois_dir_path, exist_ok=True)
        mois_path = os.path.join(mois_dir_path, f"{hc_id}_{mois}.csv")
        total_mois = 0.0
        with open(mois_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            for sem in sorted(mois_semaines.keys()):
                for vol in mois_semaines[sem]["vols"]:
                    writer.writerow({"semaine": sem, **vol})
                    total_mois += vol["flight_hours"]
            writer.writerow({"semaine": "TOTAL", "vol_source": "TOTAL", "date_vol": mois, "flight_hours": round(total_mois, 2)})
        fichiers.append(mois_path)

    # --- CSV annuel ---
    annee_semaines = {s: d for s, d in all_weeks.items() if _week_to_year(s) == annee}
    if annee_semaines:
        annee_dir_path = os.path.join(yearly_dir, hc_id)
        os.makedirs(annee_dir_path, exist_ok=True)
        annee_path = os.path.join(annee_dir_path, f"{hc_id}_{annee}.csv")
        total_annee = 0.0
        with open(annee_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=fieldnames)
            writer.writeheader()
            for sem in sorted(annee_semaines.keys()):
                for vol in annee_semaines[sem]["vols"]:
                    writer.writerow({"semaine": sem, **vol})
                    total_annee += vol["flight_hours"]
            writer.writerow({"semaine": "TOTAL", "vol_source": "TOTAL", "date_vol": annee, "flight_hours": round(total_annee, 2)})
        fichiers.append(annee_path)

    return {"hc_id": hc_id, "mois": mois, "annee": annee, "fichiers": fichiers}
```

- [ ] **Step 3: Commit**

```bash
git add src/reporting/aggregate_report.py
git commit -m "feat: ajout module aggregate_report pour CSV mensuels/annuels (pannes + FH)"
```

---

### Task 2: Integrer dans main.py

**Files:**
- Modify: `main.py:8-9` (import) et `main.py:72-83` (appels apres phase 3 et FH)

- [ ] **Step 1: Ajouter l'import dans main.py**

Ajouter apres la ligne `from src.reporting.fh_report import update_fh_report` :

```python
from src.reporting.aggregate_report import update_pannes_aggregates, update_fh_aggregates
```

- [ ] **Step 2: Appeler update_pannes_aggregates apres la phase 3**

Apres le bloc phase 3 (ligne 74-75), ajouter :

```python
        # Agregation mensuelle/annuelle des pannes
        if report["semaines"]:
            for sem in report["semaines"]:
                agg_pannes = update_pannes_aggregates(hc_id, sem, output_base)
                result["phase3"]["aggregation"] = agg_pannes
```

- [ ] **Step 3: Appeler update_fh_aggregates apres la phase FH**

Apres le bloc phase FH (ligne 81-82), ajouter :

```python
            # Agregation mensuelle/annuelle FH
            if fh_result.get("ajoute"):
                agg_fh = update_fh_aggregates(hc_id, fh_result["semaine"], output_base)
                result["phase_fh"]["aggregation"] = agg_fh
```

- [ ] **Step 4: Ajouter l'affichage dans la section CLI**

Apres l'affichage Phase FH (vers ligne 157), ajouter :

```python
    # Affichage agregation
    agg_p = result.get("phase3", {})
    if isinstance(agg_p, dict) and agg_p.get("aggregation"):
        for f in agg_p["aggregation"].get("fichiers", []):
            print(f"  Agregation → {f}")

    agg_fh = result.get("phase_fh", {})
    if isinstance(agg_fh, dict) and agg_fh.get("aggregation"):
        for f in agg_fh["aggregation"].get("fichiers", []):
            print(f"  Agregation → {f}")
```

- [ ] **Step 5: Commit**

```bash
git add main.py
git commit -m "feat: integration agregation mensuelle/annuelle dans le pipeline"
```

---

### Task 3: Test manuel

- [ ] **Step 1: Lancer le pipeline sur un fichier existant**

```bash
cd /root/camille2/nh_project
python3 main.py data/raw/exemple.xml
```

Expected: les CSV mensuels et annuels sont generes dans `data/reports/monthly/`, `data/reports/yearly/`, `data/FHreport/monthly/`, `data/FHreport/yearly/`.

- [ ] **Step 2: Verifier le contenu des CSV generes**

```bash
cat data/reports/monthly/NH08/NH08_2026-02.csv
cat data/reports/yearly/NH08/NH08_2026.csv
cat data/FHreport/monthly/NH08/NH08_2026-02.csv
cat data/FHreport/yearly/NH08/NH08_2026.csv
```

Expected: chaque CSV contient une colonne `semaine` et les donnees de toutes les semaines du mois/annee.
