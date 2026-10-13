"""Vetores compartilhados do hash (PR #7, contrato/vetores-hash.json).

Fixture copiada de origin/docs/payload-numeros-inteiros:contrato/vetores-hash.json (semântica
§6): payload só com inteiros int64. Aceitos: canônico e sha256 exatos; rejeitados: 422.
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from motor.api.app import app
from motor.api.numeros import NumeroInvalido, decodificar_estrito
from motor.dominio.hashing import hash_fato, json_canonico

_VETORES: list[dict[str, Any]] = json.loads(
    (Path(__file__).parent.parent / "fixtures" / "vetores-hash.json").read_text("utf-8")
)["fatos"]
_ACEITOS = [v for v in _VETORES if v["esperado"] == "aceito"]
_REJEITADOS = [v for v in _VETORES if v["esperado"] == "rejeitado"]


def test_vetor_tem_12_aceitos_e_12_rejeitados() -> None:
    assert (len(_ACEITOS), len(_REJEITADOS)) == (12, 12)


@pytest.mark.parametrize("v", _ACEITOS, ids=[v["nome"] for v in _ACEITOS])
def test_aceitos_canonico_e_sha256_exatos(v: dict[str, Any]) -> None:
    payload = decodificar_estrito(v["payload"])
    canonico = json_canonico({"payload": payload, "valor_centavos": v["valor_centavos"]})
    assert canonico == v["canonico"]
    assert hashlib.sha256(canonico.encode("utf-8")).hexdigest() == v["sha256"]
    assert hash_fato(payload, v["valor_centavos"]) == v["sha256"]


@pytest.mark.parametrize("v", _REJEITADOS, ids=[v["nome"] for v in _REJEITADOS])
def test_rejeitados_nao_decodificam(v: dict[str, Any]) -> None:
    with pytest.raises(NumeroInvalido):
        decodificar_estrito(v["payload"])


def _corpo_fato(payload: str) -> str:
    return (
        '{"titular_id":"T1","competencia":"202609","tipo":"guia","fonte":"api",'
        '"observado_em":"2026-09-01T10:00:00Z","payload":' + payload + "}"
    )


@pytest.mark.parametrize("v", _REJEITADOS, ids=[v["nome"] for v in _REJEITADOS])
def test_rejeitados_dao_422_corpo_invalido_em_fatos(v: dict[str, Any]) -> None:
    r = TestClient(app).post(
        "/fatos", content=_corpo_fato(v["payload"]),
        headers={"content-type": "application/json"},
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("v", _REJEITADOS, ids=[v["nome"] for v in _REJEITADOS])
def test_rejeitados_dao_422_corpo_invalido_em_concluir(v: dict[str, Any]) -> None:
    corpo = '{"ator":"a","saidas":[{"tributo":"","payload":' + v["payload"] + "}]}"
    r = TestClient(app).post(
        "/casos/T1/202609/entregaveis/E1/concluir", content=corpo,
        headers={"content-type": "application/json"},
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("texto", ["NaN", "Infinity", "-Infinity", "-0.0", "1E+2"])
def test_constantes_e_formas_extras_rejeitadas(texto: str) -> None:
    with pytest.raises(NumeroInvalido):
        decodificar_estrito('{"n":' + texto + "}")
