"""Condição `quando` (§3): lista de objetos; OU entre objetos, E entre pares.

Lista vazia = sempre verdadeira. Campo ausente no contexto nunca casa
(mas um objeto vazio {} casa, pois não tem par a satisfazer).
"""
from __future__ import annotations

from collections.abc import Mapping, Sequence

Contexto = Mapping[str, object]


def casa(quando: Sequence[Mapping[str, object]] | None, ctx: Contexto) -> bool:
    """Verdadeira se ALGUM objeto da lista casa (todos os pares iguais no ctx).

    - `quando` vazio/None = sempre verdadeira.
    - Objeto `{}` = sempre verdadeiro (sem par a verificar).
    - Par `campo: valor` casa se `ctx[campo] == valor`; campo ausente não casa.
    """
    if not quando:
        return True
    for objeto in quando:
        if all(campo in ctx and ctx[campo] == valor for campo, valor in objeto.items()):
            return True
    return False
