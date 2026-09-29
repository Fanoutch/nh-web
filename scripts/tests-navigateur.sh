#!/usr/bin/env bash
# Lance le banc de tests navigateur (voir docs/tests-navigateur.md).
# Crée l'environnement Python dédié .venv-tests s'il manque (Playwright, sans télécharger de navigateur :
# le Chrome du système est utilisé).
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -x .venv-tests/bin/python ]; then
    echo "Création de l'environnement Python .venv-tests…"
    python3 -m venv .venv-tests
    .venv-tests/bin/pip install --quiet playwright
fi

exec .venv-tests/bin/python tests/navigateur/parcours_assistant.py "$@"
