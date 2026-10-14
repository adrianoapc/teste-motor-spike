"""Teste de arquitetura (requisito 11): camadas limpas verificadas por import-linter.

Falha se `dominio` depender de outra camada ou de framework/banco, ou se `aplicacao` depender de
`api` ou de driver de banco. Roda o comando REAL (`lint-imports`, do mesmo ambiente) com o
`.importlinter` da raiz da spike e exige que a saída liste cada contrato como KEPT: assim o teste
não volta a passar sem executar nada (antes ele chamava `python -m importlinter.cli lint`, que
sai com 0 sem analisar coisa alguma).
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parents[2]
CONTRATOS = (
    "Camadas limpas (dominio < aplicacao < infraestrutura < api)",
    "Dominio nao importa framework nem banco",
    "Aplicacao nao importa api nem driver de banco",
)


def _lint_imports() -> subprocess.CompletedProcess[str]:
    executavel = Path(sys.executable).with_name("lint-imports")
    return subprocess.run(
        [str(executavel)], cwd=RAIZ, capture_output=True, text=True, check=False
    )


def test_contratos_de_camadas_passam_e_sao_executados() -> None:
    resultado = _lint_imports()
    saida = resultado.stdout + resultado.stderr
    assert resultado.returncode == 0, f"import-linter falhou:\n{saida}"
    for contrato in CONTRATOS:
        assert f"{contrato} KEPT" in saida, f"contrato não executado/mantido: {contrato}\n{saida}"
    assert "Contracts: 3 kept, 0 broken." in saida, saida
