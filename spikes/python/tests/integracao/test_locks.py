"""Duas conexões reais: o lock de titular serializa comandos (precisa de MOTOR_DB; senão pula)."""
from __future__ import annotations

import os
import threading
import time
from typing import Any

import pytest
from psycopg import Connection
from psycopg.rows import dict_row

from motor.infraestrutura.unidade_de_trabalho import UnidadeDeTrabalhoSQL

pytestmark = pytest.mark.skipif(not os.environ.get("MOTOR_DB"), reason="sem MOTOR_DB")


def _conn() -> Connection[dict[str, Any]]:
    return Connection.connect(os.environ["MOTOR_DB"], row_factory=dict_row)


def test_titular_e_reentrante_e_serializa_a_segunda_conexao() -> None:
    titular = f"T-lock-{os.getpid()}"
    a, b = _conn(), _conn()
    ordem: list[str] = []
    try:
        ua, ub = UnidadeDeTrabalhoSQL(a), UnidadeDeTrabalhoSQL(b)
        ua.travar_titular(titular)
        ua.travar_titular(titular)  # reentrante: /concluir → publicar_fato
        ua.travar_chave_fato(titular, "202609", "guia", "DAS")

        def segunda() -> None:
            ub.travar_titular(titular)  # bloqueia até a primeira commitar
            ordem.append("b")
            b.rollback()

        t = threading.Thread(target=segunda)
        t.start()
        time.sleep(0.5)
        assert ordem == []  # b ainda esperando
        ordem.append("a")
        a.commit()
        t.join(timeout=5)
        assert ordem == ["a", "b"]
    finally:
        a.close()
        b.close()


def test_ordem_titular_fato_nao_gera_deadlock_entre_conexoes() -> None:
    """Comandos concorrentes com a mesma ordem global (titular → fato) terminam sem deadlock."""
    titular = f"T-ordem-{os.getpid()}"
    erros: list[BaseException] = []

    def comando(fato_duas_vezes: bool) -> None:
        c = _conn()
        try:
            u = UnidadeDeTrabalhoSQL(c)
            u.travar_titular(titular)
            u.travar_chave_fato(titular, "202609", "guia", "DAS")
            time.sleep(0.1)
            if fato_duas_vezes:
                u.travar_chave_fato(titular, "202609", "guia", "DAS")
            c.commit()
        except Exception as e:
            erros.append(e)
        finally:
            c.close()

    ts = [threading.Thread(target=comando, args=(i % 2 == 0,)) for i in range(6)]
    for t in ts:
        t.start()
    for t in ts:
        t.join(timeout=20)
    assert erros == []
