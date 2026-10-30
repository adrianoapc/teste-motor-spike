"""Vetores do hash v2 (semântica v5 §6.3, contrato/vetores-hash-v2.json).

Fixture copiada de contrato/vetores-hash-v2.json: casos com `canonico` e `sha256` exatos e
pares `iguais` que têm de dar o mesmo hash. A etapa canônica continua coberta por
`test_vetores_hash.py` (contrato/vetores-hash.json). Aqui só a projeção, a normalização e a
ordenação (as etapas novas da v5), sob o `contrato` de cada vetor.
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from motor.api.numeros import decodificar_estrito
from motor.dominio.hashing import entrada_hash_v2, hash_fato_v2

_DADOS: dict[str, Any] = json.loads(
    (Path(__file__).parent.parent / "fixtures" / "vetores-hash-v2.json").read_text("utf-8")
)
_CASOS: list[dict[str, Any]] = _DADOS["casos"]
_IGUAIS: list[dict[str, Any]] = _DADOS["iguais"]


def _ids(vs: list[dict[str, Any]]) -> list[str]:
    return [v["nome"] for v in vs]


def _payload(texto: str) -> dict[str, Any]:
    dados = decodificar_estrito(texto)
    assert isinstance(dados, dict)
    return dados


def test_tem_todos_os_vetores() -> None:
    assert len(_CASOS) == 21
    assert len(_IGUAIS) == 4


@pytest.mark.parametrize("v", _CASOS, ids=_ids(_CASOS))
def test_canonico_e_sha256(v: dict[str, Any]) -> None:
    contrato = v["contrato"]
    campos_hash = contrato["campos_hash"]
    ordenar_arrays = contrato.get("ordenar_arrays") or {}
    payload = _payload(v["payload"])
    canonico = entrada_hash_v2(payload, v["valor_centavos"], campos_hash, ordenar_arrays)
    assert canonico == v["canonico"], v["nome"]
    sha = hash_fato_v2(payload, v["valor_centavos"], campos_hash, ordenar_arrays)
    assert sha == v["sha256"], v["nome"]


@pytest.mark.parametrize("v", _IGUAIS, ids=_ids(_IGUAIS))
def test_pares_iguais(v: dict[str, Any]) -> None:
    contrato = v["contrato"]
    campos_hash = contrato["campos_hash"]
    ordenar_arrays = contrato.get("ordenar_arrays") or {}
    a = hash_fato_v2(_payload(v["payload_a"]), v["valor_centavos"], campos_hash, ordenar_arrays)
    b = hash_fato_v2(_payload(v["payload_b"]), v["valor_centavos"], campos_hash, ordenar_arrays)
    assert a == b == v["sha256"], v["nome"]
