"""Hash v2 dos fatos (semântica v5, §6.2). Implementação de referência da base comum.

Usada pelo verificador para gerar e checar `contrato/vetores-hash-v2.json`. As spikes
não importam este arquivo: implementam a mesma regra na própria linguagem e provam
pelos vetores.

  hash = sha256( canonico( {"payload": ordenar(normalizar(projetar(payload))), "valor_centavos": v} ) )
"""
from __future__ import annotations

import hashlib
import json
import re
import unicodedata
from typing import Any

_ESPACOS = re.compile(r"[ \t\n\r]+")


def canonico(obj: Any) -> str:
    """JSON canônico da v4 (§6): chaves por code point, sem espaços, UTF-8 cru, escapes mínimos."""
    return json.dumps(obj, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def projetar(payload: dict[str, Any], campos_hash: list[str]) -> dict[str, Any]:
    return {k: payload[k] for k in campos_hash if k in payload}


def normalizar(valor: Any) -> Any:
    """Strings: NFC, sequências de espaço/\\t/\\n/\\r viram um espaço, sem espaço nas pontas.
    Objetos: membro com valor null é removido (ausente = null). Arrays: elementos null ficam."""
    if isinstance(valor, str):
        return _ESPACOS.sub(" ", unicodedata.normalize("NFC", valor)).strip(" ")
    if isinstance(valor, dict):
        return {k: normalizar(v) for k, v in valor.items() if v is not None}
    if isinstance(valor, list):
        return [normalizar(v) for v in valor]
    return valor


def _chave_ordem(item: Any, campos: list[str]) -> tuple[tuple[int, str], ...]:
    # campo ausente vem antes de qualquer valor; valores comparados pelo texto canônico, por code point
    # empate nos campos declarados: desempata pelo texto canônico do item inteiro (ordem total, sem depender da entrada)
    if not isinstance(item, dict):
        return tuple((1, canonico(item)) for _ in campos) + ((1, canonico(item)),)
    return tuple((0, "") if c not in item else (1, canonico(item[c])) for c in campos) + ((1, canonico(item)),)


def ordenar(projecao: dict[str, Any], ordenar_arrays: dict[str, list[str]]) -> dict[str, Any]:
    saida = dict(projecao)
    for campo, por in ordenar_arrays.items():
        if isinstance(saida.get(campo), list):
            saida[campo] = sorted(saida[campo], key=lambda it: _chave_ordem(it, por))
    return saida


def entrada_hash_v2(payload: dict[str, Any] | None, valor_centavos: int | None, contrato: dict[str, Any]) -> str:
    proj = ordenar(normalizar(projetar(payload or {}, contrato["campos_hash"])), contrato.get("ordenar_arrays") or {})
    return canonico({"payload": proj, "valor_centavos": valor_centavos})


def hash_v2(payload: dict[str, Any] | None, valor_centavos: int | None, contrato: dict[str, Any]) -> str:
    return hashlib.sha256(entrada_hash_v2(payload, valor_centavos, contrato).encode("utf-8")).hexdigest()
