"""Hash canônico (§6, §9.2): SHA-256 hex minúsculo de JSON canônico.

JSON canônico: chaves ordenadas em todos os níveis, sem espaços,
UTF-8 sem escapar não-ASCII (ensure_ascii=False). Números do payload são só inteiros int64
(a API rejeita o resto, ver motor.api.numeros), então o texto do número é sempre o decimal
simples, idêntico em qualquer linguagem; não há float/repr no hash.

Hash v2 (v5, §6.3): projetar → normalizar → ordenar → canônico → sha256, sob o contrato ativo.
A etapa canônica é a mesma da v4; só a entrada muda.
"""
from __future__ import annotations

import hashlib
import json
import re
import unicodedata
from collections.abc import Mapping, Sequence

_ESPACOS = re.compile(r"[ \t\n\r]+")


def json_canonico(obj: object) -> str:
    """Serialização canônica usada por todos os hashes do motor."""
    return json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def _sha256_hex(texto: str) -> str:
    return hashlib.sha256(texto.encode("utf-8")).hexdigest()


def hash_canonico(obj: object) -> str:
    """SHA-256 hex minúsculo do JSON canônico de `obj`."""
    return _sha256_hex(json_canonico(obj))


def hash_fato(payload: Mapping[str, object], valor_centavos: int | None) -> str:
    """Hash da versão do fato: sha256 de {"payload": ..., "valor_centavos": ...} (§6)."""
    return hash_canonico({"payload": payload, "valor_centavos": valor_centavos})


def entrada_hash(
    cf: str,
    fatos_usados: Sequence[Mapping[str, object]],
    regra_versao_id: str,
    semantica_calculo: int = 1,
) -> str:
    """Hash de idempotência da conferência (§9.2).

    sha256 de {"cf", "fatos_usados", "regra_versao_id"} canônico. `fatos_usados`
    deve vir ordenado por fato_id pelo chamador. `semantica_calculo` (§9.2) entra como última
    chave canônica ("semantica_calculo":N) SOMENTE se > 1; com 1 o hash é o legado.
    """
    corpo: dict[str, object] = {
        "cf": cf, "fatos_usados": list(fatos_usados), "regra_versao_id": regra_versao_id,
    }
    if semantica_calculo > 1:
        corpo["semantica_calculo"] = semantica_calculo
    return hash_canonico(corpo)


# =============================================================================
# Hash v2 (v5, §6.3): projetar → normalizar → ordenar → canônico → sha256
# =============================================================================
def projetar(payload: Mapping[str, object], campos_hash: Sequence[str]) -> dict[str, object]:
    """Mantém só as chaves de `campos_hash` que existem no payload (§6.3)."""
    return {k: payload[k] for k in campos_hash if k in payload}


def normalizar(valor: object) -> object:
    """Normaliza como a referência (§6.3):

    - string: NFC, toda sequência de espaço/\\t/\\n/\\r vira um espaço, pontas sem espaço;
    - objeto: membro com valor `null` é removido (ausente = null), em qualquer profundidade;
    - array: elementos `null` ficam; só chaves de objeto NÃO são normalizadas.
    """
    if isinstance(valor, str):
        return _ESPACOS.sub(" ", unicodedata.normalize("NFC", valor)).strip(" ")
    if isinstance(valor, Mapping):
        return {k: normalizar(v) for k, v in valor.items() if v is not None}
    if isinstance(valor, list):
        return [normalizar(v) for v in valor]
    return valor


def _normalizar_dict(projecao: Mapping[str, object]) -> dict[str, object]:
    """`normalizar` de um objeto, com o tipo estreitado para dict (a projeção é sempre dict)."""
    return {k: normalizar(v) for k, v in projecao.items() if v is not None}


def _chave_ordem(item: object, campos: Sequence[str]) -> tuple[tuple[int, str], ...]:
    """Chave de ordenação de um item de array (§6.3).

    Campo ausente vem antes de qualquer valor (0, ""); valor presente compara pelo texto
    canônico (1, canonico). Empate nos campos declarados desempata pelo texto canônico do
    item inteiro, para uma ordem total que não depende da ordem de entrada.
    """
    if not isinstance(item, Mapping):
        return tuple((1, json_canonico(item)) for _ in campos) + ((1, json_canonico(item)),)
    return tuple(
        (0, "") if c not in item else (1, json_canonico(item[c])) for c in campos
    ) + ((1, json_canonico(item)),)


def ordenar(
    projecao: Mapping[str, object], ordenar_arrays: Mapping[str, Sequence[str]]
) -> dict[str, object]:
    """Ordena cada array listado em `ordenar_arrays` pelos campos declarados (§6.3)."""
    saida = dict(projecao)
    for campo, por in ordenar_arrays.items():
        valor = saida.get(campo)
        if isinstance(valor, list):
            saida[campo] = sorted(valor, key=lambda it: _chave_ordem(it, por))
    return saida


def entrada_hash_v2(
    payload: Mapping[str, object] | None,
    valor_centavos: int | None,
    campos_hash: Sequence[str],
    ordenar_arrays: Mapping[str, Sequence[str]],
) -> str:
    """Texto canônico que entra no sha256 do hash v2 (§6.3)."""
    proj = ordenar(_normalizar_dict(projetar(payload or {}, campos_hash)), ordenar_arrays)
    return json_canonico({"payload": proj, "valor_centavos": valor_centavos})


def hash_fato_v2(
    payload: Mapping[str, object] | None,
    valor_centavos: int | None,
    campos_hash: Sequence[str],
    ordenar_arrays: Mapping[str, Sequence[str]],
) -> str:
    """sha256 do hash v2, sob o contrato ativo (campos_hash, ordenar_arrays) (§6.3)."""
    return _sha256_hex(entrada_hash_v2(payload, valor_centavos, campos_hash, ordenar_arrays))
