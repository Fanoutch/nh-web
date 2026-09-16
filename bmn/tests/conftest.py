import os
import sys
from pathlib import Path

# Les tests portent sur les valeurs par défaut de config.py (template factice,
# tableau plat, LLM coupé). Un bmn/reglages.env de poste ou des variables
# d'environnement ne doivent pas les fausser : on les neutralise AVANT que
# config soit importé par les modules de test.
os.environ["BMN_REGLAGES"] = "aucun"
for cle in list(os.environ):
    if cle.startswith(("LLM_", "DISPO_")):
        del os.environ[cle]

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
