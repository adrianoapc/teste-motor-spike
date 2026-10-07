"""Registros simples que o motor manipula (sem pydantic, sem driver)."""
from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(slots=True)
class Caso:
    id: str
    titular_id: str
    competencia: str
    snapshot: dict[str, object]
    carteira: str
    regra_entregaveis_versao_id: str
    estado: str
    version: int


@dataclass(slots=True)
class Entregavel:
    id: str
    caso_id: str
    tipo: str
    estado: str
    executor: str
    area: str
    version: int


@dataclass(slots=True)
class Fato:
    id: str
    titular_id: str
    competencia: str
    tipo: str
    tributo: str
    versao: int
    valor_centavos: int | None
    payload: dict[str, object]
    hash: str


@dataclass(slots=True)
class ItemEntregavel:
    """Um item da regra `fechamento.entregaveis` (já desserializado)."""

    tipo: str
    executor: str
    quando: list[dict[str, object]]
    depende_de: list[dict[str, object]]
    entradas: list[dict[str, object]]
    saida: dict[str, object] | None
    conferencias: list[dict[str, object]]
    pos_validacao: dict[str, object] | None = None
    encerra_caso: bool = False


@dataclass(slots=True)
class RegraEntregaveis:
    versao_id: str
    estado_minimo_padrao: str
    itens: list[ItemEntregavel]
    por_tipo: dict[str, ItemEntregavel] = field(default_factory=dict)

    def __post_init__(self) -> None:
        if not self.por_tipo:
            self.por_tipo = {it.tipo: it for it in self.itens}


@dataclass(slots=True)
class Tolerancia:
    versao_id: str
    ok_ate_centavos: int
    alerta_ate_centavos: int


@dataclass(slots=True)
class Parametros:
    prolabore_percentual_bp: int


@dataclass(slots=True)
class TipoConferencia:
    chave: str
    severidade: str
    classe_padrao: str
