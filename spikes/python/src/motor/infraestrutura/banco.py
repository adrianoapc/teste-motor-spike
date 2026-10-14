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


def _dsn() -> str:
    dsn = environ.get("MOTOR_DB")
    if not dsn:
        raise RuntimeError("MOTOR_DB não definido: a conexão vem só da variável de ambiente")
    return dsn


_pool: ConnectionPool[Connection[Any]] | None = None


def iniciar_pool() -> None:
    global _pool
    if _pool is None:
        _pool = ConnectionPool(_dsn(), min_size=1, max_size=10, kwargs={"row_factory": dict_row})
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
