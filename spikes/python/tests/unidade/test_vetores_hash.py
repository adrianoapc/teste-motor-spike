"""Vetores compartilhados do hash (PR #7, contrato/vetores-hash.json).

Fixture copiada de origin/docs/payload-numeros-inteiros:contrato/vetores-hash.json (semântica
§6): 49 vetores, 22 aceitos (canônico e sha256 exatos) e 27 rejeitados (422 corpo_invalido).
"""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient

from motor.api.app import app
from motor.api.modelos import FatoRequest
from motor.api.numeros import NumeroInvalido, decodificar_estrito
from motor.dominio.hashing import hash_fato, json_canonico

_VETORES: list[dict[str, Any]] = json.loads(
    (Path(__file__).parent.parent / "fixtures" / "vetores-hash.json").read_text("utf-8")
)["fatos"]
_ACEITOS = [v for v in _VETORES if v["esperado"] == "aceito"]
_REJEITADOS = [v for v in _VETORES if v["esperado"] == "rejeitado"]
_REJ_PAYLOAD = [v for v in _REJEITADOS if "valor_centavos_bruto" not in v]
_REJ_VALOR = [v for v in _REJEITADOS if "valor_centavos_bruto" in v]
_CABECALHO = {"content-type": "application/json"}


def _ids(vs: list[dict[str, Any]]) -> list[str]:
    return [v["nome"] for v in vs]


def _envelope(partes: list[str]) -> str:
    base = [
        '"titular_id":"T1"', '"competencia":"202609"', '"tipo":"guia"', '"fonte":"api"',
        '"observado_em":"2026-09-01T10:00:00Z"',
    ]
    return "{" + ",".join(base + partes) + "}"


def test_vetor_tem_49_vetores_22_aceitos_27_rejeitados() -> None:
    assert (len(_VETORES), len(_ACEITOS), len(_REJEITADOS)) == (49, 22, 27)


@pytest.mark.parametrize("v", _ACEITOS, ids=_ids(_ACEITOS))
def test_aceitos_canonico_e_sha256_exatos(v: dict[str, Any]) -> None:
    omitidos = v.get("campos_omitidos", [])
    payload = decodificar_estrito(v["payload"]) if "payload" not in omitidos else {}
    valor = None if "valor_centavos" in omitidos else v["valor_centavos"]
    canonico = json_canonico({"payload": payload, "valor_centavos": valor})
    assert canonico == v["canonico"]
    assert hashlib.sha256(canonico.encode("utf-8")).hexdigest() == v["sha256"]
    assert hash_fato(payload, valor) == v["sha256"]


@pytest.mark.parametrize("v", _ACEITOS, ids=_ids(_ACEITOS))
def test_aceitos_passam_pela_validacao_do_corpo_de_fatos(v: dict[str, Any]) -> None:
    omitidos = v.get("campos_omitidos", [])
    partes: list[str] = []
    if "payload" not in omitidos:
        partes.append('"payload":' + v["payload"])
    if "valor_centavos" not in omitidos and v["valor_centavos"] is not None:
        partes.append('"valor_centavos":' + str(v["valor_centavos"]))
    corpo = _envelope(partes)
    dados = decodificar_estrito(corpo)
    FatoRequest.model_validate(dados)


@pytest.mark.parametrize("v", _REJ_PAYLOAD, ids=_ids(_REJ_PAYLOAD))
def test_rejeitados_de_payload_nao_decodificam(v: dict[str, Any]) -> None:
    with pytest.raises((NumeroInvalido, ValueError)):
        decodificar_estrito('{"payload":' + v["payload"] + "}")


@pytest.mark.parametrize("v", _REJ_PAYLOAD, ids=_ids(_REJ_PAYLOAD))
def test_rejeitados_de_payload_dao_422_em_fatos(v: dict[str, Any]) -> None:
    r = TestClient(app).post(
        "/fatos", content=_envelope(['"payload":' + v["payload"]]), headers=_CABECALHO
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("v", _REJ_PAYLOAD, ids=_ids(_REJ_PAYLOAD))
def test_rejeitados_de_payload_dao_422_em_concluir(v: dict[str, Any]) -> None:
    corpo = '{"ator":"a","saidas":[{"tributo":"","payload":' + v["payload"] + "}]}"
    r = TestClient(app).post(
        "/casos/T1/202609/entregaveis/E1/concluir", content=corpo, headers=_CABECALHO
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("v", _REJ_VALOR, ids=_ids(_REJ_VALOR))
def test_valor_centavos_bruto_invalido_da_422_em_fatos_e_concluir(v: dict[str, Any]) -> None:
    bruto = v["valor_centavos_bruto"]
    r = TestClient(app).post(
        "/fatos", content=_envelope([f'"valor_centavos":{bruto}']), headers=_CABECALHO
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"
    corpo = '{"ator":"a","saidas":[{"valor_centavos":' + bruto + "}]}"
    r = TestClient(app).post(
        "/casos/T1/202609/entregaveis/E1/concluir", content=corpo, headers=_CABECALHO
    )
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("texto", ["NaN", "Infinity", "-Infinity", "-0.0", "1E+2"])
def test_constantes_e_formas_extras_rejeitadas(texto: str) -> None:
    with pytest.raises(NumeroInvalido):
        decodificar_estrito('{"n":' + texto + "}")


def test_canonico_ordena_por_code_point_e_escapa_o_minimo() -> None:
    assert json_canonico({"～": 1, "\U0001f600": 2}) == '{"～":1,"😀":2}'
    assert json_canonico({"s": "\x7f/ \x1f\x01"}) == '{"s":"\x7f/ \\u001f\\u0001"}'


@pytest.mark.parametrize("corpo", [
    '{"payload":{"s":"\\ud800"}}', '{"payload":{"\\udc00":1}}', '{"payload":{"s":"a\\u0000b"}}',
    '{"tipo":"x\\u0000"}', '{"a":1,"a":2}',
])
def test_substituto_isolado_nul_e_duplicata_viram_erro(corpo: str) -> None:
    with pytest.raises(NumeroInvalido):
        decodificar_estrito(corpo)


def test_aninhamento_absurdo_nao_estoura_recursao() -> None:
    with pytest.raises(NumeroInvalido):
        decodificar_estrito('{"payload":' + "[" * 100000 + "]" * 100000 + "}")


def test_nul_em_campo_de_texto_e_na_rota_da_422_sem_tocar_o_banco() -> None:
    c = TestClient(app)
    r = c.post("/fatos", content=_envelope(['"tributo":"a\\u0000b"']), headers=_CABECALHO)
    assert r.status_code == 422 and r.json()["erro"] == "corpo_invalido"
    r = c.get("/casos/T%001/202609")
    assert r.status_code == 422 and r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("rota", ["/fatos", "/competencias/abrir",
                                  "/casos/T1/202609/entregaveis/E1/concluir",
                                  "/casos/T1/202609/entregaveis/E1/override"])
def test_json_malformado_da_422_corpo_invalido_em_todos_os_posts(rota: str) -> None:
    r = TestClient(app).post(rota, content="{nao e json", headers=_CABECALHO)
    assert r.status_code == 422
    assert r.json()["erro"] == "corpo_invalido"
