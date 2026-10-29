"""Testes da tabela de efeitos §6.4 (v5): obsoleto, conflito_fonte, instante."""
from __future__ import annotations

from motor.aplicacao.motor_fechamento import _comparar_instantes, _decidir_efeito
from motor.aplicacao.registros import Fato


def _vigente(
    *, versao: int = 1, observado_em: str, fonte: str, valor: int | None = 100
) -> Fato:
    return Fato(
        id="f1",
        titular_id="T1",
        competencia="202609",
        tipo="guia",
        tributo="INSS",
        versao=versao,
        valor_centavos=valor,
        payload={},
        hash="hgravado",
        fonte=fonte,
        observado_em=observado_em,
    )


def test_novo_quando_nao_ha_vigente() -> None:
    assert _decidir_efeito(None, "h", None, "2026-09-01T10:00:00Z", "porta") == ("novo", 1, None)


def test_sem_mudanca_quando_hash_igual() -> None:
    v = _vigente(observado_em="2026-09-01T10:00:00+00:00", fonte="porta")
    assert _decidir_efeito(v, "h", "h", "2026-09-01T11:00:00Z", "porta") == (
        "sem_mudanca", 0, None
    )


def test_nova_versao_quando_mais_recente() -> None:
    v = _vigente(versao=2, observado_em="2026-09-01T10:00:00+00:00", fonte="porta")
    assert _decidir_efeito(v, "h2", "h1", "2026-09-01T11:00:00Z", "porta") == (
        "nova_versao", 3, "f1"
    )


def test_obsoleto_quando_mais_antigo() -> None:
    v = _vigente(observado_em="2026-09-01T12:00:00+00:00", fonte="porta")
    assert _decidir_efeito(v, "h2", "h1", "2026-09-01T10:00:00Z", "porta") == (
        "obsoleto", 0, None
    )


def test_conflito_fonte_mesmo_instante_mesma_fonte() -> None:
    v = _vigente(observado_em="2026-09-01T10:00:00+00:00", fonte="porta")
    assert _decidir_efeito(v, "h2", "h1", "2026-09-01T10:00:00Z", "porta") == (
        "conflito_fonte", 0, None
    )


def test_nova_versao_mesmo_instante_fonte_diferente() -> None:
    v = _vigente(versao=1, observado_em="2026-09-01T10:00:00+00:00", fonte="porta")
    assert _decidir_efeito(v, "h2", "h1", "2026-09-01T10:00:00Z", "outra") == (
        "nova_versao", 2, "f1"
    )


def test_comparar_instantes_fuso_e_z() -> None:
    assert _comparar_instantes("2026-09-01T10:00:00Z", "2026-09-01T07:00:00-03:00") == 0
    assert _comparar_instantes("2026-09-01T10:00:01Z", "2026-09-01T10:00:00Z") == 1
    assert _comparar_instantes("2026-09-01T09:59:59Z", "2026-09-01T10:00:00Z") == -1
