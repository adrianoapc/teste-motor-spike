"""Forma do corpo das requisições (semântica §6 e §8 passo 0): 422 `corpo_invalido`.

Decodifica o texto bruto com hooks do `json` (o corpo já decodificado perderia `1.0`, `1e2`,
`-0` e chaves duplicadas) e rejeita, em qualquer profundidade:
- número fracionário, com expoente, `-0`, NaN/Infinity, fora de int64 ou com > 4300 dígitos;
- chave duplicada de objeto (depois de decodificar escapes);
- substituto UTF-16 isolado (`\\ud800`, em valor ou chave: não existe em UTF-8) e NUL (U+0000,
  que o jsonb/PostgreSQL recusa);
- `payload` (de `/fatos` e de cada saída de `/concluir`) com mais de 64 níveis, contando o
  próprio objeto `payload` como nível 1.
"""
from __future__ import annotations

import json
from collections.abc import Callable, Coroutine
from typing import Any

from fastapi import Request, Response
from fastapi.routing import APIRoute

from motor.api.erros import invalido
from motor.dominio.dinheiro import INT64_MAX, INT64_MIN

PROFUNDIDADE_MAXIMA_PAYLOAD = 64
# Limite do texto bruto antes de json.loads (evita RecursionError em aninhamento absurdo):
# payload (64) mais o envelope (corpo, saidas[], item) com folga.
_PROFUNDIDADE_BRUTA_MAXIMA = PROFUNDIDADE_MAXIMA_PAYLOAD + 8


class NumeroInvalido(ValueError):
    """Corpo com número, chave, string ou profundidade fora das regras (§6)."""


def _inteiro(texto: str) -> int:
    if texto == "-0":
        raise NumeroInvalido("'-0' não é permitido")
    if len(texto) > 20:  # int64 tem no máximo 19 dígitos + sinal (e evita o limite de 4300)
        raise NumeroInvalido(f"número fora de int64: {texto[:24]}")
    v = int(texto)
    if not INT64_MIN <= v <= INT64_MAX:
        raise NumeroInvalido(f"número fora de int64: {texto}")
    return v


def _fracionario(texto: str) -> float:
    raise NumeroInvalido(f"número não inteiro: {texto[:24]}")


def _constante(texto: str) -> float:
    raise NumeroInvalido(f"constante não permitida: {texto}")


def _objeto(pares: list[tuple[str, Any]]) -> dict[str, Any]:
    saida: dict[str, Any] = {}
    for chave, valor in pares:
        if chave in saida:
            raise NumeroInvalido(f"chave duplicada: {chave[:40]!r}")
        saida[chave] = valor
    return saida


def _profundidade_bruta(texto: str) -> int:
    """Maior aninhamento de {} e [] no texto, ignorando o conteúdo de strings."""
    atual = maximo = 0
    em_string = escapou = False
    for c in texto:
        if em_string:
            if escapou:
                escapou = False
            elif c == "\\":
                escapou = True
            elif c == '"':
                em_string = False
        elif c == '"':
            em_string = True
        elif c in "{[":
            atual += 1
            maximo = max(maximo, atual)
        elif c in "}]":
            atual -= 1
    return maximo


def _texto_invalido(s: str) -> bool:
    return "\x00" in s or any("\ud800" <= c <= "\udfff" for c in s)


def _varrer(no: object) -> None:
    """Strings e chaves sem NUL nem substituto isolado (iterativo: sem recursão)."""
    pilha: list[object] = [no]
    while pilha:
        atual = pilha.pop()
        if isinstance(atual, str):
            if _texto_invalido(atual):
                raise NumeroInvalido("string com NUL ou substituto UTF-16 isolado")
        elif isinstance(atual, dict):
            for k, v in atual.items():
                if _texto_invalido(k):
                    raise NumeroInvalido("chave com NUL ou substituto UTF-16 isolado")
                pilha.append(v)
        elif isinstance(atual, list):
            pilha.extend(atual)


def _profundidade(no: object) -> int:
    """Níveis de objeto/array de `no` (iterativo); o próprio `no` conta como nível 1."""
    maximo = 0
    pilha: list[tuple[object, int]] = [(no, 1)]
    while pilha:
        atual, nivel = pilha.pop()
        if isinstance(atual, dict):
            maximo = max(maximo, nivel)
            pilha.extend((v, nivel + 1) for v in atual.values())
        elif isinstance(atual, list):
            maximo = max(maximo, nivel)
            pilha.extend((v, nivel + 1) for v in atual)
    return maximo


def _checar_payloads(dados: object) -> None:
    if not isinstance(dados, dict):
        return
    candidatos: list[object] = [dados.get("payload")]
    saidas = dados.get("saidas")
    if isinstance(saidas, list):
        candidatos.extend(s.get("payload") for s in saidas if isinstance(s, dict))
    for p in candidatos:
        if p is not None and _profundidade(p) > PROFUNDIDADE_MAXIMA_PAYLOAD:
            raise NumeroInvalido(
                f"payload com mais de {PROFUNDIDADE_MAXIMA_PAYLOAD} níveis de aninhamento"
            )


def decodificar_estrito(texto: str | bytes) -> Any:
    """json.loads com as regras do §6. Levanta NumeroInvalido (ou JSONDecodeError/ValueError
    se o texto nem for JSON). Não valida o envelope: só números, chaves, strings e payloads."""
    if isinstance(texto, bytes):
        texto = texto.decode("utf-8")
    if _profundidade_bruta(texto) > _PROFUNDIDADE_BRUTA_MAXIMA:
        raise NumeroInvalido("aninhamento excessivo")
    dados = json.loads(
        texto,
        parse_int=_inteiro,
        parse_float=_fracionario,
        parse_constant=_constante,
        object_pairs_hook=_objeto,
    )
    _varrer(dados)
    _checar_payloads(dados)
    return dados


async def validar_requisicao(request: Request) -> None:
    """422 corpo_invalido antes de qualquer consulta ou gravação (§8 passo 0)."""
    for valor in request.path_params.values():
        if isinstance(valor, str) and _texto_invalido(valor):
            raise invalido("corpo_invalido", "parâmetro de rota com NUL ou substituto isolado")
    corpo = await request.body()
    if not corpo:
        return  # sem corpo (GET): quem exige o corpo é o modelo do Pydantic
    try:
        decodificar_estrito(corpo)
    except NumeroInvalido as e:
        raise invalido("corpo_invalido", str(e)) from e
    except ValueError as e:  # JSON malformado, não UTF-8 ou inteiro além do limite do Python
        raise invalido("corpo_invalido", f"JSON inválido: {e}") from e


class RotaValidada(APIRoute):
    """Rota que valida a forma do corpo ANTES do FastAPI decodificá-lo (que daria 400)."""

    def get_route_handler(self) -> Callable[[Request], Coroutine[Any, Any, Response]]:
        original = super().get_route_handler()

        async def handler(request: Request) -> Response:
            await validar_requisicao(request)
            return await original(request)

        return handler
