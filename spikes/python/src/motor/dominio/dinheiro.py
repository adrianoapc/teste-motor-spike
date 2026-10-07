"""Dinheiro em centavos (inteiro) e arredondamento meio para o par.

Nunca use ponto flutuante. `arred_meio_par` faz divisão inteira com
desempate "meio para o par" (banker's rounding), só com inteiros (§9.1).
"""
from __future__ import annotations

from typing import NewType

Centavos = NewType("Centavos", int)


def arred_meio_par(numerador: int, denominador: int) -> int:
    """numerador / denominador arredondado meio para o par, só com inteiros.

    Vetores (requisito 7.2):
        arred(60000000000, 10000) == 6000000
        arred(5, 2) == 2   arred(7, 2) == 4
        arred(15, 10) == 2 arred(25, 10) == 2 arred(26, 10) == 3
    """
    if denominador <= 0:
        raise ValueError("denominador deve ser positivo")
    quociente, resto = divmod(numerador, denominador)
    dobro = resto * 2
    if dobro < denominador:
        return quociente
    if dobro > denominador:
        return quociente + 1
    # Exatamente no meio: arredonda para o par.
    return quociente if quociente % 2 == 0 else quociente + 1
