"""Regressões do code-review: regex ASCII, competência, modelos estritos, relógio e log JSON."""
from __future__ import annotations

import json
import logging
import re
from pathlib import Path
from typing import Any

import pytest
from fastapi.testclient import TestClient
from pydantic import ValidationError

from motor.api.app import app
from motor.api.modelos import Empresa, FatoRequest
from motor.aplicacao import motor_fechamento as mf
from motor.aplicacao.erros import ErroNegocio
from motor.dominio.competencia import Competencia
from motor.infraestrutura.log import configurar_log

_BASE_FATO: dict[str, Any] = {
    "titular_id": "T1", "competencia": "202609", "tipo": "guia", "fonte": "api",
}
_EMPRESA: dict[str, Any] = {
    "titular_id": "T1", "regime": "SN", "tem_folha": True, "tem_prolabore": False,
    "tem_taxa_municipal": False, "filial": False, "carteira": "C1", "municipio": "SP",
}


@pytest.mark.parametrize("cf", ["CF-١٢", "CF-1", "cf-12", "CF-123", "CF-12\n"])
def test_override_com_cf_fora_de_ascii_da_422_antes_de_consultar(cf: str) -> None:
    with pytest.raises(ErroNegocio) as e:
        mf.override_conferencia(None, "T1", "202609", "E1", cf, "ator", "motivo ok")  # type: ignore[arg-type]
    assert e.value.status == 422


@pytest.mark.parametrize("valor", ["202609\n", "٢٠٢٦٠٩", "20261", "202613"])
def test_competencia_invalida_e_recusada(valor: str) -> None:
    with pytest.raises(ValueError):
        Competencia(valor)


def test_competencia_valida_com_virada_de_ano_continua() -> None:
    assert Competencia("202612").valor == "202612"


@pytest.mark.parametrize("valor", ["202609\n", "٢٠٢٦٠٩"])
def test_modelos_recusam_competencia_com_quebra_de_linha(valor: str) -> None:
    with pytest.raises(ValidationError):
        FatoRequest(**{**_BASE_FATO, "competencia": valor}, observado_em="2026-09-01T10:00:00Z")


@pytest.mark.parametrize("campo", ["tem_folha", "tem_prolabore", "tem_taxa_municipal", "filial"])
@pytest.mark.parametrize("ruim", [1, 0, "yes", "on", "true"])
def test_empresa_booleanos_sao_estritos(campo: str, ruim: object) -> None:
    with pytest.raises(ValidationError):
        Empresa(**{**_EMPRESA, campo: ruim})


def test_empresa_booleano_verdadeiro_passa() -> None:
    assert Empresa(**_EMPRESA).tem_folha is True


@pytest.mark.parametrize("ruim", [
    1788256800, 1788256800.5, "2026-09-01", "2026-09-01T10:00:00", "2026-09-01 10:00:00Z",
    "2026-09-01T10:00:00+0300", None, True,
])
def test_observado_em_exige_rfc3339_com_fuso(ruim: object) -> None:
    with pytest.raises(ValidationError):
        FatoRequest(**_BASE_FATO, observado_em=ruim)  # type: ignore[arg-type]


@pytest.mark.parametrize("ok", [
    "2026-09-01T10:00:00Z", "2026-09-01T10:00:00.123456Z", "2026-09-01T10:00:00-03:00",
    "2026-09-01t10:00:00z",
])
def test_observado_em_rfc3339_valido(ok: str) -> None:
    m = FatoRequest(**_BASE_FATO, observado_em=ok)  # type: ignore[arg-type]
    assert m.observado_em.tzinfo is not None


def test_observado_em_ingenuo_da_422_na_api() -> None:
    corpo = ('{"titular_id":"T1","competencia":"202609","tipo":"guia","fonte":"api",'
             '"observado_em":"2026-09-01T10:00:00"}')
    r = TestClient(app).post("/fatos", content=corpo, headers={"content-type": "application/json"})
    assert r.status_code == 422


@pytest.mark.parametrize("corpo", [
    '{"ator":"a","saidas":[]}', '{"saidas":[{"payload":{}}]}', '{"ator":"a"}',
    '{"ator":"a","saidas":[{"payload":{}},{"valor_centavos":1.5}]}',
])
def test_concluir_valida_a_forma_antes_de_404_e_409(corpo: str) -> None:
    """§8 passo 0: 404 só depois de a forma do corpo passar (sem tocar o banco)."""
    r = TestClient(app).post(
        "/casos/INEXISTENTE/202609/entregaveis/E1/concluir", content=corpo,
        headers={"content-type": "application/json"},
    )
    assert r.status_code == 422


def test_sql_do_banco_so_usa_clock_timestamp_nos_campos_de_data() -> None:
    """now() é o início da transação: empata/regride em relação a clock_timestamp()."""
    fonte = (Path(__file__).parents[2] / "src/motor/infraestrutura/unidade_de_trabalho.py"
             ).read_text("utf-8")
    assert not re.search(r"\bnow\(\)", fonte)
    assert fonte.count("clock_timestamp()") >= 5  # inserir_conferencia, override, tarefa, ...


def test_log_do_uvicorn_sai_em_json_no_stdout(capsys: pytest.CaptureFixture[str]) -> None:
    configurar_log()
    logging.getLogger("uvicorn.access").info('127.0.0.1 - "GET /health" 200')
    logging.getLogger("uvicorn.error").info("Started server process")
    linhas = [ln for ln in capsys.readouterr().out.splitlines() if ln.strip()]
    assert len(linhas) == 2
    objs = [json.loads(ln) for ln in linhas]
    assert {o["logger"] for o in objs} == {"uvicorn.access", "uvicorn.error"}
    assert all({"ts", "nivel", "msg"} <= o.keys() for o in objs)


def test_log_config_do_cmd_aponta_para_o_formatador_json() -> None:
    cfg = json.loads((Path(__file__).parents[2] / "log-config.json").read_text("utf-8"))
    assert cfg["formatters"]["json"]["()"] == "motor.infraestrutura.log.FormatadorJson"
    for nome in ("uvicorn", "uvicorn.error", "uvicorn.access"):
        assert cfg["loggers"][nome]["handlers"] == ["stdout"]
    dockerfile = (Path(__file__).parents[2] / "Dockerfile").read_text("utf-8")
    assert "--log-config" in dockerfile
