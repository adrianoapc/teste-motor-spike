"""Regra de prontidão (§7.1, §7.2) e leitura das entradas da regra, como funções puras.

A aplicação só carrega os dados (estado das dependências, presença dos fatos); quem decide
"está pronto?" é este módulo, testável sem banco (requisito 11.3).
"""
from __future__ import annotations

from collections.abc import Iterable, Mapping, Sequence
from dataclasses import dataclass

from motor.dominio.competencia import Competencia
from motor.dominio.condicao import casa
from motor.dominio.estados import pelo_menos


@dataclass(frozen=True, slots=True)
class ChaveFato:
    """Chave de um fato de entrada, já com a competência deslocada (§7.1)."""

    competencia: str
    tipo: str
    tributo: str


def _quando(obj: Mapping[str, object]) -> list[Mapping[str, object]] | None:
    quando = obj.get("quando")
    return quando if isinstance(quando, list) else None


def entradas_resolvidas(
    entradas: Sequence[Mapping[str, object]], ctx: Mapping[str, object], competencia: str
) -> list[ChaveFato]:
    """Entradas do item cujo `quando` casa no contexto, como chaves de fato (§7.1).

    `competencia_relativa` (padrão 0) desloca a competência do caso: -1 sobre 202601 dá 202512.
    """
    chaves: list[ChaveFato] = []
    for entrada in entradas:
        if not casa(_quando(entrada), ctx):
            continue
        rel_raw = entrada.get("competencia_relativa", 0)
        rel = rel_raw if isinstance(rel_raw, int) else 0
        chaves.append(
            ChaveFato(
                competencia=Competencia(competencia).somar(rel).valor,
                tipo=str(entrada["tipo"]),
                tributo=str(entrada.get("tributo") or ""),
            )
        )
    return chaves


def tributo_da_entrada(
    entradas: Sequence[Mapping[str, object]], ctx: Mapping[str, object], tipo: str
) -> str | None:
    """Tributo da primeira entrada resolvida de `tipo` (ex.: a `guia` do E11: DAS no SN, IRPJ
    no LP), lido da regra em vez de repetido no código. None se a regra não declara."""
    for entrada in entradas:
        if entrada.get("tipo") == tipo and casa(_quando(entrada), ctx):
            return str(entrada.get("tributo") or "")
    return None


def dependencias_satisfeitas(dependencias: Iterable[tuple[str, str]]) -> bool:
    """Cada par é (estado atual da dependência, estado mínimo exigido); todos devem cumprir."""
    return all(pelo_menos(atual, minimo) for atual, minimo in dependencias)


def esta_pronto(
    dependencias: Iterable[tuple[str, str]], entradas_presentes: Iterable[bool]
) -> bool:
    """§7.2: pronto se e somente se toda dependência está no mínimo E toda entrada resolvida
    está presente. `entradas_presentes` pode ser um gerador: só é consumido se as dependências
    cumprem, e para na primeira entrada ausente (a aplicação lê o banco sob demanda)."""
    return dependencias_satisfeitas(dependencias) and all(entradas_presentes)
