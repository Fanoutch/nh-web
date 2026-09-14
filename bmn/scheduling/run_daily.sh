#!/usr/bin/env bash
# Lancement quotidien (Linux/macOS). Appelé par cron, voir scheduling/README.md.
set -u
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_DIR" || exit 1
PYTHON="$PROJECT_DIR/.venv/bin/python"
[ -x "$PYTHON" ] || PYTHON="python3"
"$PYTHON" daily_report.py "$@"
exit $?
