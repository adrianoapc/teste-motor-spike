"""Teste de arquitetura (requisito 11): camadas limpas verificadas por import-linter.

Falha se `dominio` depender de outra camada, ou `aplicacao` depender de `api`
ou de driver de banco. Roda o import-linter com o `.importlinter` da raiz da spike.
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]


def test_contratos_de_camadas_passam() -> None:
    resultado = subprocess.run(
        [sys.executable, "-m", "importlinter.cli", "lint"],
        cwd=RAIZ,
        capture_output=True,
        text=True,
    )
    assert resultado.returncode == 0, (
        f"import-linter falhou:\n{resultado.stdout}\n{resultado.stderr}"
    )
