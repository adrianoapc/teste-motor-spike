"""Testes da validação do contrato de tipo de fato (§6.2 passo 4, v5)."""
from __future__ import annotations

from motor.dominio.contrato import Contrato, fonte_autorizada, validar_publicacao


def _contrato(
    *,
    esquema: dict[str, object] | None = None,
    usa: bool = False,
    valores: tuple[str, ...] = (),
    valor_centavos: str = "proibido",
    fontes: tuple[str, ...] = ("intranet",),
) -> Contrato:
    return Contrato(
        tipo="x",
        versao=1,
        esquema=esquema
        or {"$schema": "https://json-schema.org/draft/2020-12/schema", "type": "object"},
        qualificador_usa=usa,
        qualificador_valores=valores,
        valor_centavos=valor_centavos,
        campos_hash=(),
        ordenar_arrays={},
        fontes_permitidas=fontes,
    )


def test_fonte_autorizada() -> None:
    c = _contrato(fontes=("intranet", "tarefa"))
    assert fonte_autorizada(c, "tarefa")
    assert not fonte_autorizada(c, "oneflow")


def test_qualificador_proibido_com_tributo() -> None:
    c = _contrato(usa=False)
    v = validar_publicacao(c, "DAS", None, {})
    assert [(x.campo, x.regra) for x in v] == [("tributo", "proibido")]


def test_qualificador_obrigatorio_sem_tributo() -> None:
    c = _contrato(usa=True, valores=("DAS", "INSS"))
    v = validar_publicacao(c, "", None, {})
    assert [(x.campo, x.regra) for x in v] == [("tributo", "obrigatorio")]


def test_qualificador_fora_da_lista() -> None:
    c = _contrato(usa=True, valores=("DAS", "INSS"))
    v = validar_publicacao(c, "ISS", None, {})
    assert [(x.campo, x.regra) for x in v] == [("tributo", "fora_da_lista")]


def test_qualificador_usa_sem_lista_aceita_qualquer() -> None:
    c = _contrato(usa=True, valores=())
    assert validar_publicacao(c, "QUALQUER", None, {}) == []


def test_valor_obrigatorio_ausente() -> None:
    c = _contrato(valor_centavos="obrigatorio")
    v = validar_publicacao(c, "", None, {})
    assert [(x.campo, x.regra) for x in v] == [("valor_centavos", "obrigatorio")]


def test_valor_proibido_presente() -> None:
    c = _contrato(valor_centavos="proibido")
    v = validar_publicacao(c, "", 100, {})
    assert [(x.campo, x.regra) for x in v] == [("valor_centavos", "proibido")]


def test_valor_opcional_aceita_ambos() -> None:
    c = _contrato(valor_centavos="opcional")
    assert validar_publicacao(c, "", None, {}) == []
    assert validar_publicacao(c, "", 100, {}) == []


def test_payload_required_ausente() -> None:
    esquema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {"competencia_impressa": {"type": "string"}},
        "required": ["competencia_impressa"],
    }
    c = _contrato(esquema=esquema)
    v = validar_publicacao(c, "", None, {})
    assert [(x.campo, x.regra) for x in v] == [
        ("payload.competencia_impressa", "obrigatorio")
    ]


def test_payload_tipo_errado() -> None:
    esquema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {"inss_centavos": {"type": "integer"}},
    }
    c = _contrato(esquema=esquema)
    v = validar_publicacao(c, "", None, {"inss_centavos": "88000"})
    assert [(x.campo, x.regra) for x in v] == [("payload.inss_centavos", "tipo")]


def test_payload_pattern_falha_vira_esquema() -> None:
    esquema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {"competencia_impressa": {"type": "string", "pattern": "^[0-9]{6}$"}},
    }
    c = _contrato(esquema=esquema)
    v = validar_publicacao(c, "", None, {"competencia_impressa": "09/2026"})
    assert [(x.campo, x.regra) for x in v] == [("payload.competencia_impressa", "esquema")]


def test_payload_caminho_aninhado() -> None:
    esquema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {
            "parcelas": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {"valor_centavos": {"type": "integer"}},
                    "required": ["valor_centavos"],
                },
            }
        },
    }
    c = _contrato(esquema=esquema)
    v = validar_publicacao(c, "", None, {"parcelas": [{"socio": "a"}]})
    assert [(x.campo, x.regra) for x in v] == [
        ("payload.parcelas[0].valor_centavos", "obrigatorio")
    ]


def test_ok_sem_violacoes() -> None:
    esquema = {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "type": "object",
        "properties": {"competencia_impressa": {"type": "string", "pattern": "^[0-9]{6}$"}},
        "required": ["competencia_impressa"],
    }
    c = _contrato(esquema=esquema, usa=True, valores=("INSS",), valor_centavos="obrigatorio")
    assert validar_publicacao(c, "INSS", 88000, {"competencia_impressa": "202609"}) == []
