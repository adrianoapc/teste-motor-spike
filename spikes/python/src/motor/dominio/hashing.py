"""Hash canônico (§6, §9.2): SHA-256 hex minúsculo de JSON canônico.

JSON canônico: chaves ordenadas em todos os níveis, sem espaços,
UTF-8 sem escapar não-ASCII (ensure_ascii=False). Números do payload são só inteiros int64
(a API rejeita o resto, ver motor.api.numeros), então o texto do número é sempre o decimal
simples, idêntico em qualquer linguagem; não há float/repr no hash.
"""
from __future__ import annotations

import hashlib
import json
from collections.abc import Mapping, Sequence


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
