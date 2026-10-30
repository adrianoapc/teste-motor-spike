"""Duas conexões reais: o lock de titular serializa comandos (precisa de MOTOR_DB; senão pula).

O teste de deadlock é de mordida: com `travar_titular` real não há deadlock; com ele trocado por
no-op, as mesmas duas ordens (fato→linha e linha→fato, as de /fatos e /concluir antes da ordem
global) provocam um deadlock de verdade, detectado pelo PostgreSQL.
"""
from __future__ import annotations

import os
import threading
import time
from typing import Any

import pytest
from psycopg import Connection
from psycopg.errors import DeadlockDetected
from psycopg.rows import dict_row

from motor.infraestrutura.unidade_de_trabalho import UnidadeDeTrabalhoSQL

pytestmark = pytest.mark.skipif(not os.environ.get("MOTOR_DB"), reason="sem MOTOR_DB")


def _conn() -> Connection[dict[str, Any]]:
    return Connection.connect(os.environ["MOTOR_DB"], row_factory=dict_row)


def _travar_linha(u: UnidadeDeTrabalhoSQL, chave: str) -> None:
    """Lock de 'linha de entregável' simulado por advisory lock de transação."""
    u._exec("SELECT pg_advisory_xact_lock(hashtextextended(%s, 0))", (f"linha|{chave}",))


def test_titulares_diferentes_nao_se_bloqueiam() -> None:
    """§13: a trava é por titular; titulares distintos rodam em paralelo (nunca espera)."""
    a, b = _conn(), _conn()
    try:
        ua, ub = UnidadeDeTrabalhoSQL(a), UnidadeDeTrabalhoSQL(b)
        ua.travar_titular(f"T-x-{os.getpid()}")
        pronto = threading.Event()

        def outro() -> None:
            ub.travar_titular(f"T-y-{os.getpid()}")  # titular diferente: não espera a
            pronto.set()
            b.rollback()

        t = threading.Thread(target=outro)
        t.start()
        # Com a primeira transação ainda ABERTA, a segunda (outro titular) toma a trava já.
        assert pronto.wait(timeout=5), "titular diferente não deveria bloquear"
        t.join(timeout=5)
        assert not t.is_alive()
        a.commit()
    finally:
        a.close()
        b.close()


def test_idempotencia_serializa_entre_titulares_diferentes() -> None:
    """§6.5: a chave (fonte, chave_idempotencia) é global; `travar_idempotencia` serializa
    duas publicações de titulares DIFERENTES que usam a mesma chave, fechando a janela em que
    ambas leem 'sem recebimento' e a segunda colide no índice único."""
    fonte, chave = "api", f"idemp-{os.getpid()}"
    a, b = _conn(), _conn()
    ordem: list[str] = []
    try:
        ua, ub = UnidadeDeTrabalhoSQL(a), UnidadeDeTrabalhoSQL(b)
        ua.travar_titular(f"T-i-a-{os.getpid()}")
        ua.travar_idempotencia(fonte, chave)  # primeira segura a chave

        def segunda() -> None:
            ub.travar_titular(f"T-i-b-{os.getpid()}")  # titular diferente: não serializa aqui
            ub.travar_idempotencia(fonte, chave)  # MESMA chave: espera a primeira commitar
            ordem.append("b")
            b.rollback()

        t = threading.Thread(target=segunda)
        t.start()
        time.sleep(0.5)
        assert t.is_alive() and ordem == [], "a mesma chave deveria serializar entre titulares"
        ordem.append("a")
        a.commit()
        t.join(timeout=5)
        assert not t.is_alive()
        assert ordem == ["a", "b"]
    finally:
        a.close()
        b.close()


def test_idempotencia_chaves_diferentes_nao_se_bloqueiam() -> None:
    """Chaves distintas rodam em paralelo (a trava é por (fonte, chave), não global)."""
    a, b = _conn(), _conn()
    try:
        ua, ub = UnidadeDeTrabalhoSQL(a), UnidadeDeTrabalhoSQL(b)
        ua.travar_idempotencia("api", f"k1-{os.getpid()}")
        pronto = threading.Event()

        def outro() -> None:
            ub.travar_idempotencia("api", f"k2-{os.getpid()}")  # chave diferente: não espera
            pronto.set()
            b.rollback()

        t = threading.Thread(target=outro)
        t.start()
        assert pronto.wait(timeout=5), "chave diferente não deveria bloquear"
        t.join(timeout=5)
        assert not t.is_alive()
        a.commit()
    finally:
        a.close()
        b.close()


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
        assert t.is_alive() and ordem == []  # b ainda esperando
        ordem.append("a")
        a.commit()
        t.join(timeout=5)
        assert not t.is_alive()
        assert ordem == ["a", "b"]
    finally:
        a.close()
        b.close()


def _rodar_dois_comandos(titular: str) -> list[BaseException]:
    """/fatos (titular→fato→linha) contra /concluir (titular→linha→fato), entrelaçados."""
    erros: list[BaseException] = []
    vivos: list[threading.Thread] = []

    def fatos() -> None:
        c = _conn()
        try:
            u = UnidadeDeTrabalhoSQL(c)
            u.travar_titular(titular)
            u.travar_chave_fato(titular, "202609", "guia", "DAS")
            time.sleep(0.4)
            _travar_linha(u, titular)
            c.commit()
        except BaseException as e:
            erros.append(e)
        finally:
            c.close()

    def concluir() -> None:
        time.sleep(0.1)
        c = _conn()
        try:
            u = UnidadeDeTrabalhoSQL(c)
            u.travar_titular(titular)
            _travar_linha(u, titular)
            time.sleep(0.4)
            u.travar_chave_fato(titular, "202609", "guia", "DAS")
            c.commit()
        except BaseException as e:
            erros.append(e)
        finally:
            c.close()

    for alvo in (fatos, concluir):
        t = threading.Thread(target=alvo)
        t.start()
        vivos.append(t)
    for t in vivos:
        t.join(timeout=30)
    assert not any(t.is_alive() for t in vivos), "comando travado"
    return erros


def test_ordem_global_com_lock_de_titular_nao_gera_deadlock() -> None:
    assert _rodar_dois_comandos(f"T-ordem-{os.getpid()}") == []


def test_sem_lock_de_titular_as_mesmas_ordens_dao_deadlock(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Prova que o teste acima morde: sem travar_titular o PostgreSQL detecta deadlock."""
    monkeypatch.setattr(UnidadeDeTrabalhoSQL, "travar_titular", lambda self, titular_id: None)
    erros = _rodar_dois_comandos(f"T-sem-{os.getpid()}")
    assert any(isinstance(e, DeadlockDetected) for e in erros), erros
