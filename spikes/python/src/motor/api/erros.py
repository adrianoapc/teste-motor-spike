"""Tradução de erros para o formato do contrato: {"erro": "<codigo>", "mensagem": "<texto>"}."""
from __future__ import annotations

from fastapi import Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from motor.aplicacao.erros import ErroNegocio
from motor.dominio.dinheiro import ValorForaDoIntervalo


def _resposta(status: int, codigo: str, mensagem: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"erro": codigo, "mensagem": mensagem})


async def tratar_erro_negocio(_req: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, ErroNegocio)
    return _resposta(exc.status, exc.codigo, exc.mensagem)


async def tratar_valor_fora_do_intervalo(_req: Request, exc: Exception) -> JSONResponse:
    """Único ponto que traduz int64 estourado (valor calculado que não cabe no bigint) em 422."""
    assert isinstance(exc, ValorForaDoIntervalo)
    return _resposta(422, "valor_fora_do_intervalo", str(exc))


async def tratar_validacao(_req: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, RequestValidationError)
    return _resposta(422, "corpo_invalido", str(exc.errors()))
