"""App FastAPI do motor do fechamento: rotas do contrato (§5–§10)."""
from __future__ import annotations

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse

from motor.api.erros import ErroDominio, tratar_erro_dominio, tratar_validacao
from motor.api.modelos import (
    AbrirCompetenciasRequest,
    AbrirCompetenciasResponse,
    CasoRef,
    CasoResponse,
    ConcluirRequest,
    EntregavelEstado,
    EntregavelResumo,
    ExcecaoResumo,
    FatoRequest,
    FatoResponse,
    TarefaResumo,
)
from motor.aplicacao import motor_fechamento as mf
from motor.aplicacao.motor_fechamento import ErroNegocio
from motor.infraestrutura import banco
from motor.infraestrutura.log import configurar_log
from motor.infraestrutura.unidade_de_trabalho import unidade_de_trabalho


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


async def _tratar_negocio(_req: Request, exc: Exception) -> JSONResponse:
    assert isinstance(exc, ErroNegocio)
    return JSONResponse(
        status_code=exc.status,
        content={"erro": exc.codigo, "mensagem": exc.mensagem},
    )


app.add_exception_handler(ErroNegocio, _tratar_negocio)


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


@app.post("/competencias/abrir")
def abrir_competencias(req: AbrirCompetenciasRequest) -> AbrirCompetenciasResponse:
    """§5: uma transação por empresa. Idempotente por (titular, competência)."""
    criados: list[CasoRef] = []
    existentes: list[CasoRef] = []
    for empresa in req.empresas:
        corpo = empresa.model_dump()
        with unidade_de_trabalho() as uow:
            titular_id, caso_id, foi_criado = mf.abrir_caso(
                uow, req.competencia, req.ator, corpo
            )
        ref = CasoRef(titular_id=titular_id, caso_id=caso_id)
        (criados if foi_criado else existentes).append(ref)
    return AbrirCompetenciasResponse(criados=criados, existentes=existentes)


@app.post("/fatos")
def publicar_fato(req: FatoRequest) -> FatoResponse:
    """§6: hash, versionamento, evento e reavaliação dos casos abertos."""
    with unidade_de_trabalho() as uow:
        fato_id, versao, efeito = mf.publicar_fato(
            uow,
            req.titular_id,
            req.competencia,
            req.tipo,
            req.tributo,
            req.valor_centavos,
            req.payload,
            req.fonte,
            req.observado_em,
        )
    return FatoResponse(fato_id=fato_id, versao=versao, efeito=efeito)


@app.post("/casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir")
def concluir(
    titular_id: str, competencia: str, tipo: str, req: ConcluirRequest
) -> EntregavelEstado:
    """§8: conclui a tarefa humana de um entregável."""
    saidas = [s.model_dump() for s in req.saidas]
    with unidade_de_trabalho() as uow:
        ent = mf.concluir_tarefa(uow, titular_id, competencia, tipo, req.ator, saidas)
    return EntregavelEstado(entregavel_id=ent.id, tipo=ent.tipo, estado=ent.estado)


@app.get("/casos/{titular_id}/{competencia}")
def consultar_caso(titular_id: str, competencia: str) -> CasoResponse:
    """Consulta do caso: estado, entregáveis, tarefas e exceções abertas."""
    with unidade_de_trabalho() as uow:
        caso = uow.buscar_caso(titular_id, competencia)
        if caso is None:
            from motor.api.erros import nao_encontrado

            raise nao_encontrado(f"caso inexistente: {titular_id}/{competencia}")
        entregaveis = [
            EntregavelResumo(
                entregavel_id=e.id, tipo=e.tipo, estado=e.estado, executor=e.executor
            )
            for e in uow.entregaveis_do_caso(caso.id)
        ]
        tarefas = [
            TarefaResumo(tarefa_id=tid, entregavel_tipo=tp)
            for tid, tp in uow.tarefas_abertas_do_caso(caso.id)
        ]
        excecoes = [
            ExcecaoResumo(excecao_id=xid, tipo=tp, classe=cl)
            for xid, tp, cl in uow.excecoes_abertas_do_caso(caso.id)
        ]
    return CasoResponse(
        caso_id=caso.id,
        titular_id=caso.titular_id,
        competencia=caso.competencia,
        estado=caso.estado,
        entregaveis=entregaveis,
        tarefas_abertas=tarefas,
        excecoes_abertas=excecoes,
    )
