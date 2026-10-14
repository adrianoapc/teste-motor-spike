"""Erros da API no formato do contrato: {"erro": "<codigo>", "mensagem": "<texto>"}."""
from __future__ import annotations

from fastapi import Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse


class ErroDominio(Exception):
    """Erro de negócio mapeável a um status HTTP com código curto em snake_case."""

    def __init__(self, status: int, codigo: str, mensagem: str) -> None:
        super().__init__(mensagem)
        self.status = status
        self.codigo = codigo
        self.mensagem = mensagem


def nao_encontrado(mensagem: str) -> ErroDominio:
    return ErroDominio(404, "nao_encontrado", mensagem)


def conflito(codigo: str, mensagem: str) -> ErroDominio:
    return ErroDominio(409, codigo, mensagem)


def invalido(codigo: str, mensagem: str) -> ErroDominio:
    return ErroDominio(422, codigo, mensagem)


async def tratar_erro_dominio(_req: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, ErroDominio)
    return JSONResponse(
        status_code=exc.status,
        content={"erro": exc.codigo, "mensagem": exc.mensagem},
    )


async def tratar_validacao(_req: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, RequestValidationError)
    return JSONResponse(
        status_code=422,
        content={"erro": "corpo_invalido", "mensagem": str(exc.errors())},
    )
