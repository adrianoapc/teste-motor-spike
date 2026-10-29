"""Contrato de tipo de fato (§6.1) e validação de publicação (§6.2 passo 4).

O contrato é lido do banco a cada publicação (§13: nada em memória entre requisições);
aqui mora só a regra pura, sem SQL. A validação devolve todas as violações como
`[{"campo", "regra"}]`, na ordem: qualificador (tributo) → valor_centavos → payload
(JSON Schema draft 2020-12). Nenhuma exceção é levantada: quem chama decide o 422.
"""
from __future__ import annotations

from collections.abc import Mapping, Sequence
from dataclasses import dataclass

from jsonschema import Draft202012Validator
from jsonschema.exceptions import ValidationError


@dataclass(slots=True, frozen=True)
class Contrato:
    """Versão ativa do contrato de um tipo de fato (§6.1)."""

    tipo: str
    versao: int
    esquema: dict[str, object]
    qualificador_usa: bool
    qualificador_valores: tuple[str, ...]
    valor_centavos: str  # 'obrigatorio' | 'opcional' | 'proibido'
    campos_hash: tuple[str, ...]
    ordenar_arrays: dict[str, list[str]]
    fontes_permitidas: tuple[str, ...]


@dataclass(slots=True)
class Violacao:
    campo: str
    regra: str

    def como_dict(self) -> dict[str, str]:
        return {"campo": self.campo, "regra": self.regra}


def fonte_autorizada(contrato: Contrato, fonte: str) -> bool:
    return fonte in contrato.fontes_permitidas


def _caminho_payload(erro: ValidationError) -> str:
    """Caminho do campo no payload, no formato `payload.a.b[0].c` (§6.2)."""
    partes = ["payload"]
    for p in erro.absolute_path:
        if isinstance(p, int):
            partes[-1] = f"{partes[-1]}[{p}]"
        else:
            partes.append(str(p))
    return ".".join(partes)


def _regra_do_erro(erro: ValidationError) -> tuple[str, str]:
    """(campo, regra) de uma falha do JSON Schema (§6.2 passo 4).

    - `required` → o campo que falta é `obrigatorio`;
    - `type` → `tipo`;
    - qualquer outra (`pattern`, `minimum`, `minLength`, ...) → `esquema`.
    """
    if str(erro.validator) == "required":
        faltando = erro.validator_value
        nome = ""
        if isinstance(faltando, Sequence):
            instancia = erro.instance if isinstance(erro.instance, Mapping) else {}
            for campo in faltando:
                if campo not in instancia:
                    nome = str(campo)
                    break
        base = _caminho_payload(erro)
        campo = f"{base}.{nome}" if nome else base
        return (campo, "obrigatorio")
    if str(erro.validator) == "type":
        return (_caminho_payload(erro), "tipo")
    return (_caminho_payload(erro), "esquema")


def validar_payload_contra_esquema(
    esquema: Mapping[str, object], payload: Mapping[str, object]
) -> list[Violacao]:
    """Valida o payload com Draft202012Validator; devolve todas as violações (§6.2)."""
    validador = Draft202012Validator(dict(esquema))
    violacoes: list[Violacao] = []
    for erro in sorted(validador.iter_errors(dict(payload)), key=str):
        campo, regra = _regra_do_erro(erro)
        violacoes.append(Violacao(campo, regra))
    return violacoes


def validar_publicacao(
    contrato: Contrato,
    tributo: str,
    valor_centavos: int | None,
    payload: Mapping[str, object],
) -> list[Violacao]:
    """§6.2 passo 4: qualificador, valor_centavos e payload contra o contrato ativo.

    Devolve TODAS as violações em ordem (tributo, valor, payload). Lista vazia = ok.
    """
    violacoes: list[Violacao] = []

    # Qualificador (coluna tributo).
    if contrato.qualificador_usa:
        if tributo == "":
            violacoes.append(Violacao("tributo", "obrigatorio"))
        elif contrato.qualificador_valores and tributo not in contrato.qualificador_valores:
            violacoes.append(Violacao("tributo", "fora_da_lista"))
    elif tributo != "":
        violacoes.append(Violacao("tributo", "proibido"))

    # valor_centavos.
    if contrato.valor_centavos == "obrigatorio" and valor_centavos is None:
        violacoes.append(Violacao("valor_centavos", "obrigatorio"))
    elif contrato.valor_centavos == "proibido" and valor_centavos is not None:
        violacoes.append(Violacao("valor_centavos", "proibido"))

    # payload contra o JSON Schema.
    violacoes.extend(validar_payload_contra_esquema(contrato.esquema, payload))
    return violacoes
