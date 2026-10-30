"""Erros de negócio: UMA hierarquia, traduzida para HTTP na camada api (um handler só).

O caso de uso levanta `ErroNegocio` com o status que o contrato define (404/409/422) e um
código curto em snake_case. A camada `api` só converte para `{"erro", "mensagem"}`.
"""
from __future__ import annotations


class ErroNegocio(Exception):
    """Erro de negócio mapeável a um status HTTP com código curto em snake_case."""

    def __init__(
        self,
        status: int,
        codigo: str,
        mensagem: str,
        violacoes: list[dict[str, str]] | None = None,
    ) -> None:
        super().__init__(mensagem)
        self.status = status
        self.codigo = codigo
        self.mensagem = mensagem
        self.violacoes = violacoes


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


def tipo_desconhecido(tipo: str) -> ErroNegocio:
    """Tipo de fato sem versão `ativa` no contrato (§6.2 passo 2 → 422)."""
    return ErroNegocio(422, "tipo_desconhecido", f"tipo de fato sem contrato ativo: {tipo}")


def fonte_nao_autorizada(fonte: str, tipo: str) -> ErroNegocio:
    """Fonte fora de `fontes_permitidas` do contrato (§6.2 passo 3 → 403)."""
    return ErroNegocio(
        403, "fonte_nao_autorizada", f"fonte {fonte!r} não publica o tipo {tipo!r}"
    )


def payload_invalido(violacoes: list[dict[str, str]]) -> ErroNegocio:
    """Payload/qualificador/valor fora do contrato (§6.2 passo 4 → 422), com as violações."""
    return ErroNegocio(
        422, "payload_invalido", f"{len(violacoes)} violação(ões) do contrato", violacoes
    )
