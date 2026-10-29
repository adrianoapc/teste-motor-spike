"""Cascata de invalidação (§11) sobre um grafo em memória, sem banco.

Testa a tabela por estado (§11.2), a cascata que só continua a partir de quem
foi para `invalidado`, e a garantia de "cada entregável no máximo uma vez".
"""
from __future__ import annotations

from dataclasses import dataclass, field

from motor.aplicacao.motor_fechamento import _invalidar, _Pendencias
from motor.aplicacao.registros import Caso, Entregavel


@dataclass
class _FakeUoW:
    """Fake mínimo do protocolo, só com o que a invalidação toca."""

    caso: Caso
    entregaveis: dict[str, Entregavel]
    deps: dict[str, list[str]]  # entregavel_id -> [depende_de_id]
    fato_para_entregaveis: dict[str, list[str]]  # fato_id -> [entregavel_id]
    transicoes: list[tuple[str, str]] = field(default_factory=list)
    eventos: list[tuple[str, str | None]] = field(default_factory=list)
    excecoes: list[tuple[str | None, str]] = field(default_factory=list)
    tarefas_canceladas: list[str] = field(default_factory=list)
    excecoes_cf_resolvidas: list[str] = field(default_factory=list)

    # -- leitura usada pela invalidação --------------------------------------
    def entregaveis_com_fato(self, fato_id: str) -> list[Entregavel]:
        ids = self.fato_para_entregaveis.get(fato_id, [])
        return [self.entregaveis[i] for i in ids]

    def caso_por_id(self, caso_id: str) -> Caso | None:
        return self.caso if caso_id == self.caso.id else None

    def estado_do_entregavel_por_id(self, entregavel_id: str) -> str:
        return self.entregaveis[entregavel_id].estado

    def dependentes_de(self, caso_id: str, depende_de_id: str) -> list[Entregavel]:
        return [
            self.entregaveis[eid]
            for eid, alvos in self.deps.items()
            if depende_de_id in alvos
        ]

    # -- escrita --------------------------------------------------------------
    def transicionar(
        self, entregavel: Entregavel, para: str, motivo: str,
        causado_por_tipo: str | None, causado_por_id: str | None,
        ator: str, regra_versao_id: str,
    ) -> None:
        entregavel.estado = para
        self.transicoes.append((entregavel.id, para))

    def gravar_evento(
        self, nome: str, caso_id: str | None, entregavel_id: str | None,
        payload: dict[str, object],
    ) -> None:
        self.eventos.append((nome, entregavel_id))

    def cancelar_tarefa_aberta(self, entregavel_id: str) -> None:
        self.tarefas_canceladas.append(entregavel_id)

    def resolver_excecoes(
        self, entregavel_id: str, tipos_prefixo: str | None,
        tipos_exatos: object, resolucao: str,
    ) -> None:
        if tipos_prefixo == "CF-":
            self.excecoes_cf_resolvidas.append(entregavel_id)

    def abrir_excecao_unica(
        self, caso_id: str, entregavel_id: str | None, tipo: str,
        classe: str, dono: str,
    ) -> str | None:
        self.excecoes.append((entregavel_id, tipo))
        return f"x-{tipo}-{entregavel_id}"


def _ent(eid: str, estado: str) -> Entregavel:
    return Entregavel(
        id=eid, caso_id="caso-1", tipo=eid, estado=estado,
        executor="sistema", area="fiscal", version=1,
    )


def _caso(estado: str = "aberto") -> Caso:
    return Caso(
        id="caso-1", titular_id="T001", competencia="202609",
        snapshot={"regime": "SN"}, carteira="C1",
        regra_entregaveis_versao_id="rv-1", estado=estado, version=1,
    )


def test_invalidacao_cascata_para_estados_invalidaveis() -> None:
    a, b, c = _ent("A", "processado"), _ent("B", "validado"), _ent("C", "liberado")
    uow = _FakeUoW(
        caso=_caso(),
        entregaveis={"A": a, "B": b, "C": c},
        deps={"B": ["A"], "C": ["B"]},
        fato_para_entregaveis={"f-old": ["A"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id=None)  # type: ignore[arg-type]
    assert a.estado == "invalidado"
    assert b.estado == "invalidado"
    assert c.estado == "invalidado"
    assert uow.transicoes == [
        ("A", "invalidado"), ("B", "invalidado"), ("C", "invalidado"),
    ]
    # A função única de transição registrou os invalidados para reavaliação (§6 passo 2 v4).
    assert pend.entregaveis == {("caso-1", "A"), ("caso-1", "B"), ("caso-1", "C")}


def test_invalidacao_disponibilizado_vira_substituicao() -> None:
    e11 = _ent("E11", "disponibilizado")
    uow = _FakeUoW(
        caso=_caso(),
        entregaveis={"E11": e11},
        deps={},
        fato_para_entregaveis={"f-old": ["E11"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id=None)  # type: ignore[arg-type]
    assert e11.estado == "disponibilizado"
    assert ("E11", "substituicao") in uow.excecoes


def test_invalidacao_caso_encerrado_abre_competencia_encerrada() -> None:
    e = _ent("E01", "validado")
    uow = _FakeUoW(
        caso=_caso("encerrado"),
        entregaveis={"E01": e},
        deps={},
        fato_para_entregaveis={"f-old": ["E01"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id=None)  # type: ignore[arg-type]
    assert e.estado == "validado"
    assert (None, "competencia_encerrada") in uow.excecoes


def test_invalidacao_exclui_entregavel_sendo_concluido() -> None:
    a = _ent("A", "processado")
    uow = _FakeUoW(
        caso=_caso(),
        entregaveis={"A": a},
        deps={},
        fato_para_entregaveis={"f-old": ["A"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id="A")  # type: ignore[arg-type]
    assert a.estado == "processado"
    assert uow.transicoes == []


def test_invalidacao_de_divergente_resolve_excecoes_cf() -> None:
    a = _ent("A", "divergente")
    uow = _FakeUoW(
        caso=_caso(),
        entregaveis={"A": a},
        deps={},
        fato_para_entregaveis={"f-old": ["A"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id=None)  # type: ignore[arg-type]
    assert a.estado == "invalidado"
    assert "A" in uow.excecoes_cf_resolvidas


def test_invalidacao_cada_entregavel_no_maximo_uma_vez() -> None:
    a = _ent("A", "processado")
    b, c, d = _ent("B", "validado"), _ent("C", "validado"), _ent("D", "validado")
    uow = _FakeUoW(
        caso=_caso(),
        entregaveis={"A": a, "B": b, "C": c, "D": d},
        deps={"B": ["A"], "C": ["A"], "D": ["B", "C"]},
        fato_para_entregaveis={"f-old": ["A"]},
    )
    pend = _Pendencias()
    _invalidar(uow, pend, "f-old", "f-new", excluir_entregavel_id=None)  # type: ignore[arg-type]
    transicoes_d = [t for t in uow.transicoes if t[0] == "D"]
    assert transicoes_d == [("D", "invalidado")]
