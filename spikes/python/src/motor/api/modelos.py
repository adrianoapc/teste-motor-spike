"""Modelos pydantic v2 de entrada e saída (contrato/openapi.yaml)."""
from __future__ import annotations

import re
from typing import Annotated, Any, Literal

from pydantic import AwareDatetime, BaseModel, ConfigDict, Field, StrictBool, field_validator

# int64 com sinal (openapi format: int64); Python não limita inteiros, o bigint do banco sim.
# strict: true/false, 1.0 e "1" não são coagidos para inteiro.
Int64 = Annotated[int, Field(strict=True, ge=-(2**63), le=2**63 - 1)]
_RFC3339 = re.compile(
    r"[0-9]{4}-[0-9]{2}-[0-9]{2}[Tt][0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?([Zz]|[+-][0-9]{2}:[0-9]{2})",
    re.ASCII,
)
COMPETENCIA = r"^[0-9]{4}(0[1-9]|1[0-2])\z"


class Empresa(BaseModel):
    model_config = ConfigDict(extra="forbid")
    titular_id: str = Field(min_length=1, max_length=64)
    regime: Literal["SN", "LP"]
    tem_folha: StrictBool
    tem_prolabore: StrictBool
    tem_taxa_municipal: StrictBool
    filial: StrictBool
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
    valor_centavos: Int64 | None = None
    payload: dict[str, object] = Field(default_factory=dict)
    fonte: str = Field(min_length=1)
    origem_ref: str | None = None
    observado_em: AwareDatetime

    @field_validator("observado_em", mode="before")
    @classmethod
    def _rfc3339(cls, v: Any) -> Any:
        """date-time RFC 3339 com fuso: sem epoch, sem data pura, sem datetime ingênuo."""
        if isinstance(v, str) and _RFC3339.fullmatch(v):
            return v
        raise ValueError("observado_em deve ser date-time RFC 3339 com fuso (Z ou +hh:mm)")


class FatoResponse(BaseModel):
    fato_id: str
    versao: int
    efeito: Literal["novo", "nova_versao", "sem_mudanca"]


class SaidaItem(BaseModel):
    model_config = ConfigDict(extra="forbid")
    tributo: str = ""
    valor_centavos: Int64 | None = None
    payload: dict[str, object] = Field(default_factory=dict)


class ConcluirRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")
    ator: str = Field(min_length=1)
    saidas: list[SaidaItem] = Field(min_length=1)


class OverrideRequest(BaseModel):
    # cf/motivo validados pelo motor (§8.1) para garantir a ordem 422 → 404 → 409.
    model_config = ConfigDict(extra="forbid")
    ator: str = Field(min_length=1)
    cf: str
    motivo: str


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
