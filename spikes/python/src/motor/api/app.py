"""App FastAPI do motor do fechamento."""
from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.exceptions import RequestValidationError

from motor.api.erros import ErroDominio, tratar_erro_dominio, tratar_validacao
from motor.infraestrutura import banco
from motor.infraestrutura.log import configurar_log


@asynccontextmanager
async def ciclo_de_vida(_app: FastAPI) -> AsyncIterator[None]:
    configurar_log()
    banco.iniciar_pool()
    try:
        yield
    finally:
        banco.fechar_pool()


app = FastAPI(title="Motor do fechamento — spike Python", lifespan=ciclo_de_vida)
app.add_exception_handler(ErroDominio, tratar_erro_dominio)
app.add_exception_handler(RequestValidationError, tratar_validacao)


@app.get("/health")
def health() -> dict[str, str]:
    """Saúde do serviço e do banco (consulta SELECT 1)."""
    with banco.conexao() as conn:
        with conn.cursor() as cur:
            cur.execute("SELECT 1")
            cur.fetchone()
        conn.rollback()
    return {"status": "ok"}


@app.get("/admin/fila")
def admin_fila() -> dict[str, int]:
    """Processamento síncrono nesta fase: a fila está sempre vazia (§13)."""
    return {"pendentes": 0, "com_erro": 0}
