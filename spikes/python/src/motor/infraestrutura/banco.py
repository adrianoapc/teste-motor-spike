"""Conexão com o banco. String de conexão só de MOTOR_DB (ADR: nada no código)."""
from __future__ import annotations

import os
from collections.abc import Iterator
from contextlib import contextmanager

import psycopg
from psycopg.rows import dict_row
from psycopg_pool import ConnectionPool

_pool: ConnectionPool | None = None


def _dsn() -> str:
    dsn = os.environ.get("MOTOR_DB")
    if not dsn:
        raise RuntimeError("MOTOR_DB não definido: a conexão vem só da variável de ambiente")
    return dsn


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
def conexao() -> Iterator[psycopg.Connection]:
    """Uma conexão do pool. O chamador controla commit/rollback (unidade de trabalho)."""
    if _pool is None:
        iniciar_pool()
    assert _pool is not None
    with _pool.connection() as conn:
        yield conn
