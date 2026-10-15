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


def test_recorte_registrado_na_transicao_unica() -> None:
    """§6 passo 2 (v4): toda mudança de `fechamento.entregavel.estado` passa por uma função
    única (`_transicionar`), que no mesmo lugar registra o entregável para reavaliação. Assim
    nenhum caminho novo pode mudar estado sem pedir a reavaliação dos dependentes.

    Garantia estática: no motor, `.transicionar(...)` (o método do repositório que grava o
    estado) só é chamado de dentro de `_transicionar`. A transição de CRIAÇÃO
    (`transicao_criacao`, estado inicial na abertura) não conta: não é mudança de estado de um
    entregável existente e não tem dependentes a reavaliar.
    """
    import ast

    fonte = (RAIZ / "src" / "motor" / "aplicacao" / "motor_fechamento.py").read_text("utf-8")
    arvore = ast.parse(fonte)

    def _chama_transicionar(no: ast.AST) -> list[int]:
        linhas: list[int] = []
        for filho in ast.walk(no):
            if (
                isinstance(filho, ast.Call)
                and isinstance(filho.func, ast.Attribute)
                and filho.func.attr == "transicionar"
            ):
                linhas.append(filho.lineno)
        return linhas

    # Linhas de chamada permitidas: as que estão dentro da def _transicionar.
    permitidas: set[int] = set()
    for no in ast.walk(arvore):
        if isinstance(no, ast.FunctionDef) and no.name == "_transicionar":
            permitidas.update(_chama_transicionar(no))

    todas = set(_chama_transicionar(arvore))
    fora = sorted(todas - permitidas)
    assert not fora, (
        "chamada a .transicionar fora da função única _transicionar nas linhas "
        f"{fora} de motor_fechamento.py"
    )
    assert permitidas, "esperava ao menos uma chamada a .transicionar dentro de _transicionar"
