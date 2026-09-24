import os
import sys
from pathlib import Path

# Les tests portent sur les valeurs par défaut : un llm.env / reglages.env de
# poste ou des variables d'environnement ne doivent pas les fausser.
os.environ["ASSISTANT_REGLAGES"] = "aucun"
for cle in list(os.environ):
    if cle.startswith(("LLM_", "EMBED_")) or (cle.startswith("ASSISTANT_") and cle != "ASSISTANT_REGLAGES"):
        del os.environ[cle]

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
