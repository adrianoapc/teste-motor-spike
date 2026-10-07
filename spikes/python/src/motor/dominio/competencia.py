"""Competência AAAAMM: aritmética de meses com virada de ano e mês do trimestre.

Domínio puro: sem banco, HTTP, framework ou pydantic (ver .importlinter).
"""
from __future__ import annotations

import re
from dataclasses import dataclass

_PADRAO = re.compile(r"^[0-9]{4}(0[1-9]|1[0-2])$")


@dataclass(frozen=True, slots=True)
class Competencia:
    """Uma competência mensal no formato AAAAMM (ex.: 202609)."""

    valor: str

    def __post_init__(self) -> None:
        if not _PADRAO.match(self.valor):
            raise ValueError(f"competência inválida: {self.valor!r}")

    @property
    def ano(self) -> int:
        return int(self.valor[:4])

    @property
    def mes(self) -> int:
        return int(self.valor[4:])

    @property
    def mes_do_trimestre(self) -> int:
        """1, 2 ou 3 — derivado por ((mes - 1) % 3) + 1 (§3)."""
        return ((self.mes - 1) % 3) + 1

    def somar(self, meses: int) -> Competencia:
        """Desloca por `meses` (negativo = passado), virando o ano (§7.1).

        Ex.: Competencia('202601').somar(-1) == Competencia('202512').
        """
        total = (self.ano * 12 + (self.mes - 1)) + meses
        ano, mes0 = divmod(total, 12)
        return Competencia(f"{ano:04d}{mes0 + 1:02d}")

    def __str__(self) -> str:
        return self.valor
