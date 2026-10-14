#!/usr/bin/env bash
# Roda a suíte (pytest, mypy, ruff, import-linter) dentro de python:3.13-slim
# com a fonte montada. Uso: scripts/checar.sh  (a partir de spikes/python/)
# ou  bash spikes/python/scripts/checar.sh  (a partir da raiz do worktree).
set -euo pipefail

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

docker run --rm \
  -v "$AQUI":/app \
  -w /app \
  python:3.13-slim \
  bash -lc '
set -e
pip install --quiet --disable-pip-version-check -e ".[dev]" >/dev/null 2>&1
echo "=== pytest ===";       python -m pytest -q
echo "=== mypy ===";         python -m mypy
echo "=== ruff ===";         python -m ruff check
echo "=== import-linter ==="; python -m importlinter.cli lint
'
