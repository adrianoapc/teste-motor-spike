"""Vetores compartilhados do entrada_hash da conferência (semântica §9.2).

Fixture copiada de origin/docs/metricas-pos-merge:contrato/vetores-entrada-hash.json
(`git show origin/docs/metricas-pos-merge:contrato/vetores-entrada-hash.json`): 9 vetores com
canônico e sha256 exatos, inclusive ordenação de fatos_usados e omissão da chave
"semantica_calculo" quando N = 1.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest

from motor.dominio.conferencias import SEMANTICA_CALCULO
from motor.dominio.hashing import entrada_hash, json_canonico

_VETORES: list[dict[str, Any]] = json.loads(
    (Path(__file__).parent.parent / "fixtures" / "vetores-entrada-hash.json").read_text("utf-8")
)["vetores"]


def _ordenados(v: dict[str, Any]) -> list[dict[str, Any]]:
    return sorted(v["fatos_usados_entrada"], key=lambda f: str(f["fato_id"]))


def test_ha_9_vetores() -> None:
    assert len(_VETORES) == 9


@pytest.mark.parametrize("v", _VETORES, ids=[v["nome"] for v in _VETORES])
def test_canonico_e_sha256_exatos(v: dict[str, Any]) -> None:
    corpo: dict[str, object] = {
        "cf": v["cf"], "fatos_usados": _ordenados(v), "regra_versao_id": v["regra_versao_id"],
    }
    if v["semantica_calculo"] > 1:
        corpo["semantica_calculo"] = v["semantica_calculo"]
    canonico = json_canonico(corpo)
    assert canonico == v["canonico"]
    assert hashlib.sha256(canonico.encode("utf-8")).hexdigest() == v["sha256"]


@pytest.mark.parametrize("v", _VETORES, ids=[v["nome"] for v in _VETORES])
def test_funcao_entrada_hash_bate_com_o_vetor(v: dict[str, Any]) -> None:
    assert (
        entrada_hash(v["cf"], _ordenados(v), v["regra_versao_id"], v["semantica_calculo"])
        == v["sha256"]
    )


def test_semantica_atual_e_2_e_muda_o_hash_em_relacao_a_legada() -> None:
    assert SEMANTICA_CALCULO == 2
    fatos = [{"fato_id": "a", "versao": 1}]
    assert entrada_hash("CF-01", fatos, "r", 1) != entrada_hash("CF-01", fatos, "r", 2)
    assert entrada_hash("CF-01", fatos, "r") == entrada_hash("CF-01", fatos, "r", 1)
