"""U+0000 em qualquer texto do corpo ou da rota -> 422 corpo_invalido (nunca 500).

O jsonb/PostgreSQL recusa NUL; a validação roda antes de qualquer consulta, então estes testes
não precisam de banco. U+0001..U+001F continuam permitidos.
"""
from __future__ import annotations

import json
from typing import Any

import pytest
from fastapi.testclient import TestClient

from motor.api.app import app
from motor.api.numeros import decodificar_estrito

_H = {"content-type": "application/json"}
_NUL = "a\x00b"
_EMPRESA: dict[str, Any] = {
    "titular_id": "T1", "regime": "SN", "tem_folha": True, "tem_prolabore": False,
    "tem_taxa_municipal": False, "filial": False, "carteira": "C1", "municipio": "SP",
}
_FATO: dict[str, Any] = {
    "titular_id": "T1", "competencia": "202609", "tipo": "guia", "tributo": "DAS",
    "fonte": "api", "origem_ref": "r1", "observado_em": "2026-09-01T10:00:00Z", "payload": {},
}


def _post(rota: str, corpo: dict[str, Any]) -> Any:
    return TestClient(app).post(rota, content=json.dumps(corpo), headers=_H)


def _422(r: Any) -> None:
    assert r.status_code == 422, r.text
    assert r.json()["erro"] == "corpo_invalido"


@pytest.mark.parametrize("campo", ["titular_id", "competencia", "tipo", "tributo", "fonte",
                                   "origem_ref", "observado_em"])
def test_fatos_nul_em_cada_campo_de_texto(campo: str) -> None:
    _422(_post("/fatos", {**_FATO, campo: _NUL}))


def test_fatos_nul_no_payload_valor_e_chave() -> None:
    _422(_post("/fatos", {**_FATO, "payload": {"s": _NUL}}))
    _422(_post("/fatos", {**_FATO, "payload": {_NUL: 1}}))
    _422(_post("/fatos", {**_FATO, "payload": {"a": [{"b": ["x", _NUL]}]}}))


@pytest.mark.parametrize("campo", ["titular_id", "regime", "carteira", "municipio"])
def test_abrir_nul_em_cada_texto_da_empresa(campo: str) -> None:
    corpo = {"competencia": "202609", "ator": "a", "empresas": [{**_EMPRESA, campo: _NUL}]}
    _422(_post("/competencias/abrir", corpo))


def test_abrir_nul_no_ator_e_na_competencia_e_na_segunda_empresa() -> None:
    ok = {"competencia": "202609", "ator": "a", "empresas": [_EMPRESA]}
    _422(_post("/competencias/abrir", {**ok, "ator": _NUL}))
    _422(_post("/competencias/abrir", {**ok, "competencia": "2026\x0009"}))
    segunda = {**_EMPRESA, "municipio": _NUL}
    _422(_post("/competencias/abrir", {**ok, "empresas": [_EMPRESA, segunda]}))


def test_concluir_nul_em_ator_tributo_e_payload_das_saidas() -> None:
    rota = "/casos/T1/202609/entregaveis/E1/concluir"
    _422(_post(rota, {"ator": _NUL, "saidas": [{"payload": {}}]}))
    _422(_post(rota, {"ator": "a", "saidas": [{"tributo": _NUL}]}))
    _422(_post(rota, {"ator": "a", "saidas": [{"payload": {"k": _NUL}}]}))
    _422(_post(rota, {"ator": "a", "saidas": [{"payload": {}}, {"payload": {_NUL: 1}}]}))


def test_override_nul_em_ator_motivo_e_cf() -> None:
    rota = "/casos/T1/202609/entregaveis/E1/override"
    _422(_post(rota, {"ator": _NUL, "cf": "CF-01", "motivo": "motivo ok"}))
    _422(_post(rota, {"ator": "a", "cf": "CF-01", "motivo": "motivo\x00ok"}))
    _422(_post(rota, {"ator": "a", "cf": "CF-0\x001", "motivo": "motivo ok"}))


@pytest.mark.parametrize("rota", [
    "/casos/T%001/202609/entregaveis/E1/concluir",
    "/casos/T1/202609/entregaveis/E%00/concluir",
    "/casos/T1/2026%0009/entregaveis/E1/override",
    "/casos/T%001/202609/entregaveis/E1/override",
])
def test_nul_em_parametro_de_caminho_dos_posts(rota: str) -> None:
    corpo = ({"ator": "a", "saidas": [{"payload": {}}]} if rota.endswith("concluir")
             else {"ator": "a", "cf": "CF-01", "motivo": "motivo ok"})
    _422(_post(rota, corpo))


@pytest.mark.parametrize("rota", ["/casos/T%001/202609", "/casos/T1/2026%0009"])
def test_nul_em_parametro_de_caminho_do_get(rota: str) -> None:
    _422(TestClient(app).get(rota))


def test_controles_de_u0001_a_u001f_continuam_permitidos() -> None:
    dados = decodificar_estrito(json.dumps({"s": "\x01\x08\t\n\x1f", "\x01": 1}))
    assert dados["s"] == "\x01\x08\t\n\x1f"
