"""Fórmulas e classificação das conferências (§9), como funções puras.

As fórmulas recebem os valores já lidos (centavos inteiros) e devolvem o par
(esperado, obtido). A classificação aplica a tolerância de `fiscal.tolerancia`.
"""
from __future__ import annotations

from motor.dominio.dinheiro import arred_meio_par

# Versão da semântica do cálculo das conferências (semântica §9.2). Entra na chave de
# idempotência (entrada_hash, só se > 1) e é persistida em conferencias.conferencia. Linhas
# legadas valem 1 e nunca colidem com as novas. Mudar a fórmula/classificação => incrementar.
# v4 / Manutenção M1 (§16): CF-07 do LP passa a conferir tributo a tributo e CF-09 ganha o
# terceiro par (IRRF da folha M−1). Como a semântica do cálculo mudou, incrementa de 2 → 3.
SEMANTICA_CALCULO = 3


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


class DadoAusente(Exception):
    """Operando necessário à fórmula ausente/inválido → conferência divergente classe 'S'
    (§9.2). Carrega a chave para a mensagem `fato ausente: <chave>`."""

    def __init__(self, chave: str) -> None:
        super().__init__(chave)
        self.chave = chave


def cf07_lp_par_de_maior_diferenca(
    apurados: dict[str, int], parcelas: list[dict[str, object]]
) -> tuple[str, int, int]:
    """CF-07 do Lucro Presumido, tributo a tributo (§9.1, v4).

    `apurados` = {tributo: valor_centavos} dos fatos vigentes `apurado` do caso.
    `parcelas` = parcelas[] de `divisao_socios`; cada uma com `valor_centavos` inteiro (já
    validado pelo chamador) e `tributo` (texto não vazio, obrigatório no LP).

    Tributos conferidos = tributos apurados ∪ tributos das parcelas. Para cada T:
    esperado = `apurado[T]`; obtido = soma das parcelas com `tributo = T` (nenhuma = 0).
    Devolve `(tributo, esperado, obtido)` do tributo de **maior diferença**; empate = menor
    tributo em ordem de code point.

    Levanta `DadoAusente` quando: alguma parcela não tem `tributo` (texto não vazio); ou um
    tributo que aparece em parcela não tem fato `apurado` no caso.
    """
    obtido_por_tributo: dict[str, int] = {}
    for p in parcelas:
        t = p.get("tributo")
        if not isinstance(t, str) or t == "":
            raise DadoAusente("divisao_socios[].parcela sem tributo")
        valor = p["valor_centavos"]
        assert isinstance(valor, int)  # garantido pelo chamador
        obtido_por_tributo[t] = obtido_por_tributo.get(t, 0) + valor

    for t in obtido_por_tributo:
        if t not in apurados:
            raise DadoAusente(f"apurado[{t}]")

    tributos = set(apurados) | set(obtido_por_tributo)
    if not tributos:
        raise DadoAusente("apurado[] e parcelas vazios")
    # Maior diferença; empate resolvido pelo menor tributo em ordem de code point.
    melhor: tuple[int, str] | None = None  # (-diferenca, tributo) para ordenar
    escolha: tuple[str, int, int] | None = None
    for t in sorted(tributos):
        esperado = apurados[t]
        obtido = obtido_por_tributo.get(t, 0)
        chave = (-abs(esperado - obtido), t)
        if melhor is None or chave < melhor:
            melhor = chave
            escolha = (t, esperado, obtido)
    assert escolha is not None  # tributos não é vazio aqui
    return escolha


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
