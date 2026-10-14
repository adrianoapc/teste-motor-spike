"""Banco real: a conferência grava semantica_calculo=2 e não reaproveita linha legada (§9.2).

Precisa de MOTOR_DB com a migration V007 aplicada; senão pula. Tudo roda numa transação que
termina em rollback (nada fica no banco).
"""
from __future__ import annotations

import os
import uuid
from collections.abc import Iterator
from typing import Any

import pytest
from psycopg import Connection
from psycopg.rows import dict_row
from psycopg.types.json import Jsonb

from motor.aplicacao.conferencia import conferir
from motor.aplicacao.registros import Caso, Entregavel
from motor.dominio.conferencias import SEMANTICA_CALCULO
from motor.dominio.hashing import entrada_hash, hash_fato
from motor.infraestrutura.unidade_de_trabalho import UnidadeDeTrabalhoSQL

pytestmark = pytest.mark.skipif(not os.environ.get("MOTOR_DB"), reason="sem MOTOR_DB")


@pytest.fixture
def uow() -> Iterator[UnidadeDeTrabalhoSQL]:
    conn: Connection[dict[str, Any]] = Connection.connect(
        os.environ["MOTOR_DB"], row_factory=dict_row
    )
    try:
        yield UnidadeDeTrabalhoSQL(conn)
    finally:
        conn.rollback()
        conn.close()


def _cenario(uow: UnidadeDeTrabalhoSQL) -> tuple[Caso, Entregavel, list[dict[str, object]]]:
    titular = f"T-sem-{uuid.uuid4()}"
    caso = Caso(
        id=str(uuid.uuid4()), titular_id=titular, competencia="202609",
        snapshot={"regime": "SN"}, carteira="C1",
        regra_entregaveis_versao_id=str(uuid.uuid4()), estado="aberto", version=1,
    )
    ent = Entregavel(
        id=str(uuid.uuid4()), caso_id=caso.id, tipo="E03", estado="processado",
        executor="sistema", area="fiscal", version=1,
    )
    usados: list[dict[str, object]] = []
    for tipo, valor in (("faturamento_mes", 1000), ("notas_oneflow", 1000)):
        f = uow.inserir_fato(
            titular, "202609", tipo, "", 1, valor, {}, "teste", "2026-09-01T00:00:00+00:00",
            hash_fato({}, valor), None,
        )
        usados.append({"fato_id": f.id, "versao": f.versao})
    return caso, ent, sorted(usados, key=lambda d: str(d["fato_id"]))


def _linhas(uow: UnidadeDeTrabalhoSQL, ent: Entregavel) -> list[dict[str, Any]]:
    return uow._todos(
        "SELECT id, semantica_calculo, entrada_hash FROM conferencias.conferencia "
        "WHERE entregavel_id = %s ORDER BY semantica_calculo",
        (ent.id,),
    )


def test_conferencia_nova_grava_semantica_calculo_atual(uow: UnidadeDeTrabalhoSQL) -> None:
    caso, ent, usados = _cenario(uow)
    resultado, _, conf_id, _, _ = conferir(uow, caso, ent, "CF-01")
    assert resultado == "ok"
    linhas = _linhas(uow, ent)
    assert [(str(r["id"]), r["semantica_calculo"]) for r in linhas] == [
        (conf_id, SEMANTICA_CALCULO)
    ]
    tol = uow.tolerancia_em_uso()
    assert linhas[0]["entrada_hash"] == entrada_hash(
        "CF-01", usados, tol.versao_id, SEMANTICA_CALCULO
    )
    # Reexecutar é idempotente: mesma linha, nada novo.
    _, _, conf_id2, _, _ = conferir(uow, caso, ent, "CF-01")
    assert conf_id2 == conf_id
    assert len(_linhas(uow, ent)) == 1


def test_conferencia_legada_semantica_1_nao_e_reaproveitada(uow: UnidadeDeTrabalhoSQL) -> None:
    caso, ent, usados = _cenario(uow)
    tol = uow.tolerancia_em_uso()
    legado = uow._um(
        "INSERT INTO conferencias.conferencia "
        "(cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash, resultado, "
        " esperado_centavos, obtido_centavos, diferenca, severidade, classe, semantica_calculo) "
        "VALUES ('CF-01', %s, %s, %s, %s, %s, 'ok', 1000, 1000, '{}'::jsonb, 'B', 'O', 1) "
        "RETURNING id",
        (caso.id, ent.id, tol.versao_id, Jsonb(usados),
         entrada_hash("CF-01", usados, tol.versao_id)),  # hash legado: sem a chave
    )
    assert legado is not None
    _, _, conf_id, _, _ = conferir(uow, caso, ent, "CF-01")
    assert conf_id != str(legado["id"])  # não reaproveitou a legada
    linhas = _linhas(uow, ent)
    assert [r["semantica_calculo"] for r in linhas] == [1, SEMANTICA_CALCULO]
    assert str(linhas[0]["id"]) == str(legado["id"])  # a legada segue intacta
    assert linhas[0]["entrada_hash"] != linhas[1]["entrada_hash"]
