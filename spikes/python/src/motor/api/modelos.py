"""Modelos pydantic v2 de entrada e saída (contrato/openapi.yaml)."""
from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field

COMPETENCIA = r"^[0-9]{4}(0[1-9]|1[0-2])$"


class Empresa(BaseModel):
    model_config = ConfigDict(extra="forbid")
    titular_id: str = Field(min_length=1, max_length=64)
    regime: Literal["SN", "LP"]
    tem_folha: bool
    tem_prolabore: bool
    tem_taxa_municipal: bool
    filial: bool
    carteira: str = Field(min_length=1)
    municipio: str = Field(min_length=1)


class AbrirCompetenciasRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    competencia: str = Field(pattern=COMPETENCIA)
    ator: str = Field(min_length=1)
    empresas: list[Empresa] = Field(min_length=1, max_length=10000)


class CasoRef(BaseModel):
    titular_id: str
    caso_id: str


class AbrirCompetenciasResponse(BaseModel):
    criados: list[CasoRef]
    existentes: list[CasoRef]


class FatoRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    titular_id: str = Field(min_length=1, max_length=64)
    competencia: str = Field(pattern=COMPETENCIA)
    tipo: str = Field(min_length=1)
    tributo: str = ""
    valor_centavos: int | None = None
    payload: dict[str, object] = Field(default_factory=dict)
    fonte: str = Field(min_length=1)
    origem_ref: str | None = None
    observado_em: str


class FatoResponse(BaseModel):
    fato_id: str
    versao: int
    efeito: Literal["novo", "nova_versao", "sem_mudanca"]


class SaidaItem(BaseModel):
    model_config = ConfigDict(extra="forbid")
    tributo: str = ""
    valor_centavos: int | None = None
    payload: dict[str, object] = Field(default_factory=dict)


class ConcluirRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    ator: str = Field(min_length=1)
    saidas: list[SaidaItem] = Field(min_length=1)


class EntregavelEstado(BaseModel):
    entregavel_id: str
    tipo: str
    estado: str


class EntregavelResumo(BaseModel):
    entregavel_id: str
    tipo: str
    estado: str
    executor: str


class TarefaResumo(BaseModel):
    tarefa_id: str
    entregavel_tipo: str


class ExcecaoResumo(BaseModel):
    excecao_id: str
    tipo: str
    classe: str


class CasoResponse(BaseModel):
    caso_id: str
    titular_id: str
    competencia: str
    estado: str
    entregaveis: list[EntregavelResumo]
    tarefas_abertas: list[TarefaResumo]
    excecoes_abertas: list[ExcecaoResumo]
