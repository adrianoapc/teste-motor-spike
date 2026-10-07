"""Estados do entregável e sua ordem no caminho principal (§4).

`ordem` define "estado ≥ X". Estados fora do caminho (divergente, invalidado)
têm ordem None e nunca são ≥ a nada.
"""
from __future__ import annotations

from enum import StrEnum


class EstadoEntregavel(StrEnum):
    AGUARDANDO_INSUMO = "aguardando_insumo"
    PRONTO = "pronto"
    PROCESSADO = "processado"
    VALIDADO = "validado"
    LIBERADO = "liberado"
    DISPONIBILIZADO = "disponibilizado"
    PAGO = "pago"
    ENCERRADO = "encerrado"
    DIVERGENTE = "divergente"
    INVALIDADO = "invalidado"


_ORDEM: dict[str, int] = {
    EstadoEntregavel.AGUARDANDO_INSUMO: 1,
    EstadoEntregavel.PRONTO: 2,
    EstadoEntregavel.PROCESSADO: 3,
    EstadoEntregavel.VALIDADO: 4,
    EstadoEntregavel.LIBERADO: 5,
    EstadoEntregavel.DISPONIBILIZADO: 6,
    EstadoEntregavel.PAGO: 7,
    EstadoEntregavel.ENCERRADO: 8,
}


def ordem(estado: str) -> int | None:
    """Ordem do estado no caminho principal, ou None se fora dele."""
    return _ORDEM.get(estado)


def pelo_menos(estado: str, minimo: str) -> bool:
    """Verdadeira se `estado` ≥ `minimo` pela ordem. None nunca é ≥ a nada."""
    o_estado = ordem(estado)
    o_minimo = ordem(minimo)
    if o_estado is None or o_minimo is None:
        return False
    return o_estado >= o_minimo
