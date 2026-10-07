"""Testes de domínio puro com os vetores obrigatórios dos requisitos."""
from __future__ import annotations

import pytest

from motor.dominio.competencia import Competencia
from motor.dominio.condicao import casa
from motor.dominio.conferencias import (
    classificar_numerica,
    esperado_das_simples,
    esperado_prolabore,
    soma_parcelas,
)
from motor.dominio.dinheiro import arred_meio_par
from motor.dominio.estados import EstadoEntregavel, ordem, pelo_menos
from motor.dominio.hashing import entrada_hash, hash_fato, json_canonico


# -- Competência (req 5.1, 5.2) ------------------------------------------------
def test_competencia_mes_do_trimestre() -> None:
    assert Competencia("202601").mes_do_trimestre == 1
    assert Competencia("202602").mes_do_trimestre == 2
    assert Competencia("202603").mes_do_trimestre == 3
    assert Competencia("202609").mes_do_trimestre == 3
    assert Competencia("202607").mes_do_trimestre == 1
    assert Competencia("202612").mes_do_trimestre == 3


def test_competencia_somar_virada_de_ano() -> None:
    assert Competencia("202601").somar(-1) == Competencia("202512")
    assert Competencia("202609").somar(-1) == Competencia("202608")
    assert Competencia("202609").somar(-2) == Competencia("202607")
    assert Competencia("202612").somar(1) == Competencia("202701")
    assert Competencia("202609").somar(0) == Competencia("202609")
    assert Competencia("202601").somar(-13) == Competencia("202412")


def test_competencia_invalida() -> None:
    for ruim in ("", "20260", "2026", "202613", "202600", "abcdef", "2026-9"):
        with pytest.raises(ValueError):
            Competencia(ruim)


# -- Arredondamento meio para o par (req 7.2) ---------------------------------
def test_arred_vetores_obrigatorios() -> None:
    assert arred_meio_par(60000000000, 10000) == 6000000
    assert arred_meio_par(5, 2) == 2
    assert arred_meio_par(7, 2) == 4
    assert arred_meio_par(15, 10) == 2
    assert arred_meio_par(25, 10) == 2
    assert arred_meio_par(26, 10) == 3


def test_arred_sem_resto_e_denominador_invalido() -> None:
    assert arred_meio_par(6000000, 1) == 6000000
    assert arred_meio_par(0, 10) == 0
    with pytest.raises(ValueError):
        arred_meio_par(10, 0)


# -- Condição quando (§3) -----------------------------------------------------
def test_quando_lista_vazia_sempre_verdadeira() -> None:
    assert casa([], {"regime": "SN"}) is True
    assert casa(None, {}) is True


def test_quando_ou_entre_objetos_e_entre_pares() -> None:
    ctx = {"tem_folha": True, "tem_prolabore": False, "regime": "SN"}
    assert casa([{"tem_folha": True}, {"tem_prolabore": True}], ctx) is True
    assert casa([{"tem_prolabore": True}], ctx) is False
    assert casa([{"regime": "LP", "mes_do_trimestre": 3}], ctx) is False
    assert casa([{"regime": "SN", "tem_folha": True}], ctx) is True
    assert casa([{"regime": "SN", "tem_folha": False}], ctx) is False


def test_quando_campo_ausente_nao_casa_mas_objeto_vazio_casa() -> None:
    assert casa([{"filial": True}], {"regime": "SN"}) is False
    assert casa([{}], {"regime": "SN"}) is True


# -- Estados e ordem (§4) -----------------------------------------------------
def test_ordem_do_caminho_principal() -> None:
    assert ordem(EstadoEntregavel.AGUARDANDO_INSUMO) == 1
    assert ordem(EstadoEntregavel.PRONTO) == 2
    assert ordem(EstadoEntregavel.ENCERRADO) == 8
    assert ordem(EstadoEntregavel.DIVERGENTE) is None
    assert ordem(EstadoEntregavel.INVALIDADO) is None


def test_pelo_menos() -> None:
    assert pelo_menos(EstadoEntregavel.VALIDADO, EstadoEntregavel.PRONTO) is True
    assert pelo_menos(EstadoEntregavel.PRONTO, EstadoEntregavel.VALIDADO) is False
    assert pelo_menos(EstadoEntregavel.PAGO, EstadoEntregavel.PAGO) is True
    assert pelo_menos(EstadoEntregavel.DIVERGENTE, EstadoEntregavel.AGUARDANDO_INSUMO) is False
    assert pelo_menos(EstadoEntregavel.PAGO, EstadoEntregavel.INVALIDADO) is False


# -- Hash canônico de fato (req 4.2) ------------------------------------------
def test_hash_fato_vetores_obrigatorios() -> None:
    assert (
        hash_fato({}, 10000000)
        == "1e949949f87138fe966f349922ebff0167b25b1ad5fdc5f7432f7252355faa3d"
    )
    assert (
        hash_fato({"aliquota_bp": 600}, None)
        == "5bafcc08855a4f461c99d302521e44641944b690fc4ab93f56375599289c1449"
    )
    assert (
        hash_fato(
            {
                "parcelas": [
                    {"socio": "S1", "valor_centavos": 360000},
                    {"socio": "S2", "valor_centavos": 240000},
                ]
            },
            None,
        )
        == "b405b94732752a986eb16f76b252ffb975b339e8fbeb56b6012cfac2d9f192ad"
    )


def test_hash_fato_ordena_chaves_e_nao_escapa_unicode() -> None:
    # Chaves fora de ordem na entrada; o sistema deve ordenar em todos os níveis.
    payload = {"descrição": "ção", "b": 1, "a": {"z": 1, "y": 2}}
    assert (
        hash_fato(payload, 5)
        == "a8b6cf1558cc659b04237ac8295a0f3556b60efb6b06a9b7be922431c745b0b0"
    )
    # UTF-8 sem escapar não-ASCII.
    assert "ção" in json_canonico(payload)
    assert "\\u" not in json_canonico(payload)


# -- entrada_hash da conferência (req 7.4) ------------------------------------
def test_entrada_hash_vetor_obrigatorio() -> None:
    assert (
        entrada_hash(
            "CF-01",
            [
                {"fato_id": "00000000-0000-0000-0000-000000000001", "versao": 1},
                {"fato_id": "00000000-0000-0000-0000-000000000002", "versao": 1},
            ],
            "00000000-0000-0000-0000-0000000000aa",
        )
        == "4e0ad4ed509cc1fa2e908b6ef9253d5927588b01a9001b8195c7185e75c878ca"
    )


# -- Fórmulas das conferências (req 7.1, C1) ----------------------------------
def test_formulas_do_c1() -> None:
    # CF-04: faturamento 10000000, alíquota 600 bp → 600000.
    assert esperado_das_simples(10000000, 600) == 600000
    # CF-08: 28% (2800 bp) → 2800000.
    assert esperado_prolabore(10000000, 2800) == 2800000
    # CF-07: 360000 + 240000 = 600000.
    assert (
        soma_parcelas(
            [
                {"socio": "S1", "valor_centavos": 360000},
                {"socio": "S2", "valor_centavos": 240000},
            ]
        )
        == 600000
    )


def test_classificar_numerica_tolerancia_padrao() -> None:
    # fiscal.tolerancia.padrao: ok_ate=0, alerta_ate=100.
    assert classificar_numerica(600000, 600000, 0, 100, "B") == ("ok", "B", 0)
    assert classificar_numerica(600000, 600050, 0, 100, "B") == ("divergente", "A", 50)
    assert classificar_numerica(600000, 600200, 0, 100, "B") == ("divergente", "B", 200)
