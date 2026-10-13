"""Números do corpo só como inteiros int64 (semântica §6): `/fatos` e `/concluir`.

Decodifica o texto bruto com hooks do `json` (o corpo já decodificado perderia `1.0`, `1e2`
e `-0`). Qualquer número fracionário, com expoente, `-0`, NaN/Infinity ou fora de int64, em
qualquer profundidade, é rejeitado com 422 `corpo_invalido`.
"""
from __future__ import annotations

import json
from typing import Any

from fastapi import Request

from motor.api.erros import invalido
from motor.dominio.dinheiro import INT64_MAX, INT64_MIN


class NumeroInvalido(ValueError):
    pass


def _inteiro(texto: str) -> int:
    if texto == "-0":
        raise NumeroInvalido("'-0' não é permitido")
    if len(texto) > 20:  # int64 tem no máximo 19 dígitos + sinal
        raise NumeroInvalido(f"número fora de int64: {texto[:24]}")
    v = int(texto)
    if not INT64_MIN <= v <= INT64_MAX:
        raise NumeroInvalido(f"número fora de int64: {texto}")
    return v


def _fracionario(texto: str) -> float:
    raise NumeroInvalido(f"número não inteiro: {texto[:24]}")


def _constante(texto: str) -> float:
    raise NumeroInvalido(f"constante não permitida: {texto}")


def decodificar_estrito(texto: str | bytes) -> Any:
    """json.loads aceitando só inteiros int64; levanta NumeroInvalido ou JSONDecodeError."""
    return json.loads(
        texto, parse_int=_inteiro, parse_float=_fracionario, parse_constant=_constante
    )


async def exigir_numeros_inteiros(request: Request) -> None:
    """Dependência das rotas com payload: 422 corpo_invalido se houver número não inteiro."""
    corpo = await request.body()
    try:
        decodificar_estrito(corpo)
    except NumeroInvalido as e:
        raise invalido("corpo_invalido", str(e)) from e
    except (ValueError, RecursionError):
        # JSON malformado (ou fundo demais): quem responde é a validação do Pydantic.
        return
