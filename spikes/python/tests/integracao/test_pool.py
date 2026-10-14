"""Pool de conexões: descarta conexão morta e limita a espera por lock (precisa de MOTOR_DB)."""
from __future__ import annotations

import asyncio
import json
import os
from collections.abc import Iterator
from typing import Any

import pytest
from psycopg import Connection
from psycopg.errors import LockNotAvailable
from psycopg.rows import dict_row

from motor.api.app import _tratar_concorrencia
from motor.infraestrutura import banco
from motor.infraestrutura.unidade_de_trabalho import UnidadeDeTrabalhoSQL

pytestmark = pytest.mark.skipif(not os.environ.get("MOTOR_DB"), reason="sem MOTOR_DB")


@pytest.fixture
def pool() -> Iterator[None]:
    banco.fechar_pool()
    banco.iniciar_pool()
    try:
        yield
    finally:
        banco.fechar_pool()


def _pid() -> int:
    with banco.conexao() as conn:
        row = conn.execute("SELECT pg_backend_pid() AS pid").fetchone()
        conn.rollback()
    assert row is not None
    return int(row["pid"])


def test_conexao_morta_e_descartada_na_proxima_retirada(pool: None) -> None:
    antes = _pid()
    with Connection.connect(os.environ["MOTOR_DB"], autocommit=True) as admin:
        admin.execute("SELECT pg_terminate_backend(%s)", (antes,))
    assert _pid() != antes  # sem `check`, a primeira consulta falharia com conexão encerrada


def test_lock_timeout_configurado_em_toda_conexao_do_pool(pool: None) -> None:
    with banco.conexao() as conn:
        row = conn.execute("SHOW lock_timeout").fetchone()
        conn.rollback()
    assert row is not None
    assert row["lock_timeout"] == f"{banco.LOCK_TIMEOUT_MS // 1000}s"


def test_espera_por_lock_alem_do_timeout_falha_com_55p03_e_vira_409(pool: None) -> None:
    titular = f"T-pool-{os.getpid()}"
    dono: Connection[dict[str, Any]] = Connection.connect(
        os.environ["MOTOR_DB"], row_factory=dict_row
    )
    try:
        UnidadeDeTrabalhoSQL(dono).travar_titular(titular)  # transação aberta segurando o lock
        with banco.conexao() as conn:
            conn.execute("SET lock_timeout = '200ms'")  # a espera real é a do pool; encurta o teste
            with pytest.raises(LockNotAvailable) as e:
                UnidadeDeTrabalhoSQL(conn).travar_titular(titular)
            conn.rollback()
    finally:
        dono.rollback()
        dono.close()
    resp = asyncio.run(_tratar_concorrencia(None, e.value))  # type: ignore[arg-type]
    assert resp.status_code == 409
    assert json.loads(bytes(resp.body))["erro"] == "conflito_concorrencia"
