"""Conexão com o banco. String de conexão só de MOTOR_DB (ADR: nada no código)."""
from __future__ import annotations

from collections.abc import Iterator
from contextlib import contextmanager
from os import environ
from typing import Any, cast

from psycopg import Connection
from psycopg.rows import dict_row
from psycopg_pool import ConnectionPool

_Conn = Connection[dict[str, Any]]

# Quanto uma instrução espera por um lock (advisory de titular/chave/exceção, linha de entregável)
# antes de falhar com 55P03 -> 409 `conflito_concorrencia` (o cliente repete). Folga larga sobre o
# p95 do lote do C6 (0,14 s): só dispara com transação travada de verdade, não com fila normal.
LOCK_TIMEOUT_MS = 10_000


def _dsn() -> str:
    dsn = environ.get("MOTOR_DB")
    if not dsn:
        raise RuntimeError("MOTOR_DB não definido: a conexão vem só da variável de ambiente")
    return dsn


_pool: ConnectionPool[Connection[Any]] | None = None


def iniciar_pool() -> None:
    global _pool
    if _pool is None:
        _pool = ConnectionPool(
            _dsn(),
            min_size=1,
            max_size=10,
            open=True,
            # Testa a conexão ao entregá-la: descarta a que morreu (restart do Postgres) em vez
            # de devolver erro até reiniciar o contêiner.
            check=ConnectionPool.check_connection,
            kwargs={"row_factory": dict_row, "options": f"-c lock_timeout={LOCK_TIMEOUT_MS}"},
        )
        _pool.wait()


def fechar_pool() -> None:
    global _pool
    if _pool is not None:
        _pool.close()
        _pool = None


@contextmanager
def conexao() -> Iterator[_Conn]:
    """Uma conexão do pool. O chamador controla commit/rollback (unidade de trabalho).

    O pool entrega linhas em dict (row_factory=dict_row passado via kwargs); o
    cast fixa esse tipo, que o tipo-parâmetro do pool não consegue carregar.
    """
    if _pool is None:
        iniciar_pool()
    assert _pool is not None
    with _pool.connection() as conn:
        yield cast(_Conn, conn)
