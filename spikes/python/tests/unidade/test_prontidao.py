"""Regra de prontidão (§7.1, §7.2) como funções puras: testada sem banco (requisito 11.3)."""
from __future__ import annotations

from collections.abc import Iterator

from motor.dominio.prontidao import (
    ChaveFato,
    dependencias_satisfeitas,
    entradas_resolvidas,
    esta_pronto,
    tributo_da_entrada,
)

_CTX_LP3 = {"regime": "LP", "mes_do_trimestre": 3}
_ENTRADAS_E03 = [
    {"tipo": "apurado", "tributo": "IRPJ", "competencia_relativa": -1,
     "quando": [{"regime": "LP", "mes_do_trimestre": 3}]},
    {"tipo": "apurado", "tributo": "IRPJ", "competencia_relativa": -2,
     "quando": [{"regime": "LP", "mes_do_trimestre": 3}]},
]
_GUIA = [
    {"tipo": "guia", "tributo": "DAS", "quando": [{"regime": "SN"}]},
    {"tipo": "guia", "tributo": "IRPJ", "quando": [{"regime": "LP"}]},
]


def test_entradas_resolvidas_aplica_quando_e_competencia_relativa() -> None:
    assert entradas_resolvidas(_ENTRADAS_E03, _CTX_LP3, "202603") == [
        ChaveFato("202602", "apurado", "IRPJ"),
        ChaveFato("202601", "apurado", "IRPJ"),
    ]


def test_entradas_resolvidas_vira_o_ano() -> None:
    chaves = entradas_resolvidas(_ENTRADAS_E03, _CTX_LP3, "202601")
    assert [c.competencia for c in chaves] == ["202512", "202511"]


def test_entrada_cujo_quando_nao_casa_nao_e_resolvida() -> None:
    assert entradas_resolvidas(
        _ENTRADAS_E03, {"regime": "LP", "mes_do_trimestre": 2}, "202602"
    ) == []
    assert entradas_resolvidas(
        _ENTRADAS_E03, {"regime": "SN", "mes_do_trimestre": 3}, "202603"
    ) == []


def test_tributo_ausente_vira_texto_vazio_e_competencia_relativa_padrao_e_zero() -> None:
    assert entradas_resolvidas([{"tipo": "faturamento_mes"}], {}, "202609") == [
        ChaveFato("202609", "faturamento_mes", "")
    ]


def test_tributo_da_entrada_segue_o_regime_declarado_na_regra() -> None:
    assert tributo_da_entrada(_GUIA, {"regime": "SN"}, "guia") == "DAS"
    assert tributo_da_entrada(_GUIA, {"regime": "LP"}, "guia") == "IRPJ"
    assert tributo_da_entrada(_GUIA, {"regime": "MEI"}, "guia") is None
    assert tributo_da_entrada(_GUIA, {"regime": "SN"}, "recibo_obrigacao") is None


def test_dependencias_so_cumprem_com_estado_pelo_menos_o_minimo() -> None:
    assert dependencias_satisfeitas([])
    assert dependencias_satisfeitas([("validado", "validado"), ("encerrado", "validado")])
    assert not dependencias_satisfeitas([("validado", "validado"), ("processado", "validado")])


def test_estados_fora_do_caminho_principal_nunca_cumprem() -> None:
    assert not dependencias_satisfeitas([("divergente", "validado")])
    assert not dependencias_satisfeitas([("invalidado", "aguardando_insumo")])


def test_dependencia_com_minimo_disponibilizado_exige_a_ordem_do_estado() -> None:
    assert not dependencias_satisfeitas([("liberado", "disponibilizado")])
    assert dependencias_satisfeitas([("disponibilizado", "disponibilizado")])


def test_pronto_exige_dependencias_e_todas_as_entradas() -> None:
    ok = [("validado", "validado")]
    assert esta_pronto(ok, [True, True])
    assert esta_pronto([], [])  # sem dependência e sem entrada: pronto
    assert not esta_pronto(ok, [True, False])
    assert not esta_pronto([("processado", "validado")], [True])


def test_pronto_nao_le_entradas_se_a_dependencia_nao_cumpre() -> None:
    lidas: list[int] = []

    def presencas() -> Iterator[bool]:
        for i in range(3):
            lidas.append(i)
            yield True

    assert not esta_pronto([("pronto", "validado")], presencas())
    assert lidas == []


def test_pronto_para_na_primeira_entrada_ausente() -> None:
    lidas: list[int] = []

    def presencas() -> Iterator[bool]:
        for i, presente in enumerate([True, False, True]):
            lidas.append(i)
            yield presente

    assert not esta_pronto([], presencas())
    assert lidas == [0, 1]
