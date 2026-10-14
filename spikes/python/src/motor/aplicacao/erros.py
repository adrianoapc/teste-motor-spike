"""Erros de negócio: UMA hierarquia, traduzida para HTTP na camada api (um handler só).

O caso de uso levanta `ErroNegocio` com o status que o contrato define (404/409/422) e um
código curto em snake_case. A camada `api` só converte para `{"erro", "mensagem"}`.
"""
from __future__ import annotations


class ErroNegocio(Exception):
    """Erro de negócio mapeável a um status HTTP com código curto em snake_case."""

    def __init__(self, status: int, codigo: str, mensagem: str) -> None:
        super().__init__(mensagem)
        self.status = status
        self.codigo = codigo
        self.mensagem = mensagem


class ConflitoConcorrencia(ErroNegocio):
    """Outra transação alterou o entregável entre a leitura e a escrita (→ 409)."""

    def __init__(self, mensagem: str) -> None:
        super().__init__(409, "conflito_concorrencia", mensagem)


def nao_encontrado(mensagem: str) -> ErroNegocio:
    return ErroNegocio(404, "nao_encontrado", mensagem)


def conflito(codigo: str, mensagem: str) -> ErroNegocio:
    return ErroNegocio(409, codigo, mensagem)


def invalido(codigo: str, mensagem: str) -> ErroNegocio:
    return ErroNegocio(422, codigo, mensagem)
