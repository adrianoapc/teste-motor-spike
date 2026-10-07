"""Fórmulas e classificação das conferências (§9), como funções puras.

As fórmulas recebem os valores já lidos (centavos inteiros) e devolvem o par
(esperado, obtido). A classificação aplica a tolerância de `fiscal.tolerancia`.
"""
from __future__ import annotations

from motor.dominio.dinheiro import arred_meio_par


def esperado_das_simples(faturamento_centavos: int, aliquota_bp: int) -> int:
    """CF-04: round_half_even(faturamento * aliquota_bp / 10000)."""
    return arred_meio_par(faturamento_centavos * aliquota_bp, 10000)


def esperado_prolabore(faturamento_centavos: int, percentual_bp: int) -> int:
    """CF-08: round_half_even(faturamento * prolabore_percentual_bp / 10000)."""
    return arred_meio_par(faturamento_centavos * percentual_bp, 10000)


def soma_parcelas(parcelas: list[dict[str, object]]) -> int:
    """CF-07: soma de parcelas[].valor_centavos (inteiros)."""
    total = 0
    for p in parcelas:
        v = p.get("valor_centavos")
        if not isinstance(v, int):
            raise ValueError(f"parcela sem valor_centavos inteiro: {p!r}")
        total += v
    return total


def classificar_numerica(
    esperado: int,
    obtido: int,
    ok_ate_centavos: int,
    alerta_ate_centavos: int,
    severidade_catalogo: str,
) -> tuple[str, str, int]:
    """Classifica uma conferência numérica (§9.2).

    Devolve (resultado, severidade, diferenca_centavos).
        diferenca ≤ ok_ate            → ok,          severidade do catálogo
        ok_ate < diferenca ≤ alerta   → divergente,  'A'
        diferenca > alerta            → divergente,  severidade do catálogo
    """
    diferenca = abs(esperado - obtido)
    if diferenca <= ok_ate_centavos:
        return ("ok", severidade_catalogo, diferenca)
    if diferenca <= alerta_ate_centavos:
        return ("divergente", "A", diferenca)
    return ("divergente", severidade_catalogo, diferenca)
