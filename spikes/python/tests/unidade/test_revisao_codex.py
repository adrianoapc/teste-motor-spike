"""Regressões dos achados da revisão (PR #5): regra fixada, eventos, tributo, origem_ref."""
from __future__ import annotations

from dataclasses import dataclass, field

import pytest
from pydantic import ValidationError

from motor.api.modelos import FatoRequest
from motor.aplicacao import motor_fechamento as mf
from motor.aplicacao.conferencia import conferir
from motor.aplicacao.registros import (
    Caso,
    Entregavel,
    Fato,
    ItemEntregavel,
    RegraEntregaveis,
    TipoConferencia,
    Tolerancia,
)


def _caso(regime: str = "SN") -> Caso:
    return Caso(
        id="caso-1", titular_id="T1", competencia="202609",
        snapshot={"regime": regime}, carteira="C1",
        regra_entregaveis_versao_id="rv-antiga", estado="aberto", version=1,
    )


def _fato(tipo: str, tributo: str = "", versao: int = 1, h: str = "h1") -> Fato:
    return Fato(
        id=f"f-{tipo}-{tributo}-{versao}", titular_id="T1", competencia="202609",
        tipo=tipo, tributo=tributo, versao=versao, valor_centavos=100, payload={}, hash=h,
    )


@dataclass
class _Fake:
    caso: Caso = field(default_factory=_caso)
    e11: Entregavel | None = None
    regra: RegraEntregaveis | None = None
    vigentes: dict[tuple[str, str], Fato] = field(default_factory=dict)
    eventos: list[tuple[str, dict[str, object]]] = field(default_factory=list)
    excecoes: list[str] = field(default_factory=list)
    classes_excecao: list[str] = field(default_factory=list)
    fatos_caso: list[Fato] = field(default_factory=list)
    chamadas: list[str] = field(default_factory=list)
    marcadas: list[str] = field(default_factory=list)
    existente: tuple[str, str, str, str] | None = None
    inseridos: list[dict[str, object]] = field(default_factory=list)
    versoes_pedidas: list[str] = field(default_factory=list)
    confs: list[dict[str, object]] = field(default_factory=list)

    def regra_entregaveis_da_versao(self, versao_id: str) -> RegraEntregaveis:
        self.versoes_pedidas.append(versao_id)
        assert self.regra is not None
        return self.regra

    def regra_entregaveis_em_uso(self) -> RegraEntregaveis:  # pragma: no cover
        raise AssertionError("deve usar a versão fixada no caso")

    def buscar_caso(self, titular_id: str, competencia: str) -> Caso | None:
        return self.caso

    def travar_titular(self, titular_id: str) -> None:
        self.chamadas.append("lock-titular")

    def marcar_conferencia_corrente(self, conferencia_id: str) -> None:
        self.marcadas.append(conferencia_id)

    def buscar_entregavel(
        self, caso_id: str, tipo: str, para_atualizar: bool = False
    ) -> Entregavel | None:
        return self.e11 if tipo == "E11" else None

    def travar_chave_fato(self, titular_id: str, competencia: str, tipo: str, tributo: str) -> None:
        self.chamadas.append("lock")

    def fato_vigente(
        self, titular_id: str, competencia: str, tipo: str, tributo: str
    ) -> Fato | None:
        self.chamadas.append("vigente")
        return self.vigentes.get((tipo, tributo))

    def inserir_fato(
        self, titular_id: str, competencia: str, tipo: str, tributo: str, versao: int,
        valor_centavos: int | None, payload: dict[str, object], fonte: str,
        observado_em: str, hash_valor: str, substitui_id: str | None,
        origem_ref: str | None = None,
    ) -> Fato:
        self.inseridos.append({"origem_ref": origem_ref, "versao": versao})
        return _fato(tipo, tributo, versao, hash_valor)

    def gravar_evento(
        self, nome: str, caso_id: str | None, entregavel_id: str | None,
        payload: dict[str, object],
    ) -> None:
        self.eventos.append((nome, payload))

    def casos_abertos_do_titular(self, titular_id: str) -> list[Caso]:
        return []

    def abrir_excecao_unica(
        self, caso_id: str, entregavel_id: str | None, tipo: str, classe: str, dono: str
    ) -> str | None:
        self.excecoes.append(tipo)
        self.classes_excecao.append(classe)
        return f"exc-{tipo}"

    def resolver_excecoes(self, *a: object, **k: object) -> None:
        pass

    # conferência
    def tipo_conferencia(self, cf: str) -> TipoConferencia:
        return TipoConferencia(chave=cf, severidade="B", classe_padrao="D")

    def tolerancia_em_uso(self) -> Tolerancia:
        return Tolerancia(versao_id="tol-1", ok_ate_centavos=0, alerta_ate_centavos=10)

    def fatos_vigentes_do_caso(self, titular_id: str, competencia: str) -> list[Fato]:
        return self.fatos_caso

    def conferencia_existente(
        self, cf: str, entregavel_id: str, entrada_hash: str
    ) -> tuple[str, str, str, str] | None:
        return self.existente

    def inserir_conferencia(self, **kw: object) -> str:
        self.confs.append(dict(kw))
        return "conf-1"


def _e11() -> Entregavel:
    return Entregavel(
        id="e11", caso_id="caso-1", tipo="E11", estado="disponibilizado",
        executor="sistema", area="fiscal", version=1,
    )


def _regra_e11() -> RegraEntregaveis:
    item = ItemEntregavel(
        tipo="E11", executor="sistema", quando=[], depende_de=[], entradas=[],
        saida=None, conferencias=[],
        acompanhamento={
            "falha": {"tipo": "entrega_falhou"},
            "confirmacao": {"tipo": "recebimento_confirmado"},
            "pendencia": {"tipo": "recebimento_pendente"},
        },
    )
    return RegraEntregaveis(versao_id="rv-antiga", estado_minimo_padrao="validado", itens=[item])


def test_regra_do_caso_usa_a_versao_fixada_no_caso() -> None:
    uow = _Fake(regra=_regra_e11())
    regra = mf._regra_do_caso(uow, uow.caso)  # type: ignore[arg-type]
    assert uow.versoes_pedidas == ["rv-antiga"]
    assert regra.versao_id == "rv-antiga"


def test_publicar_fato_trava_chave_antes_de_ler_vigente_e_grava_origem_ref() -> None:
    uow = _Fake()
    mf.publicar_fato(
        uow, "T1", "202609", "guia", "DAS", 100, {}, "api", "2026-09-01T00:00:00+00:00",  # type: ignore[arg-type]
        origem_ref="ref-123",
    )
    assert uow.chamadas[:3] == ["lock-titular", "lock", "vigente"]
    assert uow.inseridos == [{"origem_ref": "ref-123", "versao": 1}]


def test_acompanhamento_ignora_tributo_que_nao_e_o_da_guia() -> None:
    uow = _Fake(e11=_e11(), regra=_regra_e11())
    # SN → guia é DAS; falha publicada em IRPJ não abre exceção.
    mf._acompanhamento_pos_protocolo(uow, uow.caso, "entrega_falhou", "IRPJ", "novo", True)  # type: ignore[arg-type]
    assert uow.excecoes == [] and uow.eventos == []


def test_acompanhamento_tributo_da_guia_abre_excecao_com_id() -> None:
    uow = _Fake(e11=_e11(), regra=_regra_e11())
    mf._acompanhamento_pos_protocolo(uow, uow.caso, "entrega_falhou", "DAS", "novo", True)  # type: ignore[arg-type]
    assert uow.excecoes == ["entrega_falhou"]
    assert uow.eventos == [("excecao.aberta", {"excecao_id": "exc-entrega_falhou"})]


def test_acompanhamento_lp_usa_irpj() -> None:
    uow = _Fake(caso=_caso("LP"), e11=_e11(), regra=_regra_e11())
    mf._acompanhamento_pos_protocolo(uow, uow.caso, "entrega_falhou", "DAS", "novo", True)  # type: ignore[arg-type]
    assert uow.excecoes == []
    mf._acompanhamento_pos_protocolo(uow, uow.caso, "entrega_falhou", "IRPJ", "novo", True)  # type: ignore[arg-type]
    assert uow.excecoes == ["entrega_falhou"]


def test_conferir_fato_ausente_mantem_severidade_do_catalogo_e_classe_s() -> None:
    uow = _Fake()
    ent = _e11()
    resultado, severidade, conf_id, classe = conferir(uow, uow.caso, ent, "CF-01")  # type: ignore[arg-type]
    assert (resultado, severidade, conf_id, classe) == ("divergente", "B", "conf-1", "S")
    assert uow.confs[0]["severidade"] == "B"
    assert uow.confs[0]["classe"] == "S"


def test_divergencia_emite_conferencia_id_e_excecao_id() -> None:
    uow = _Fake()
    regra = RegraEntregaveis(
        versao_id="rv-antiga", estado_minimo_padrao="validado",
        itens=[ItemEntregavel(
            tipo="E11", executor="sistema", quando=[], depende_de=[], entradas=[],
            saida=None, conferencias=[{"cf": "CF-01"}],
        )],
    )
    transicoes: list[tuple[str, str | None]] = []

    def transicionar(
        ent: Entregavel, para: str, motivo: str, por_tipo: str | None,
        por_id: str | None, ator: str, rv: str,
    ) -> None:
        transicoes.append((para, por_id))

    uow.transicionar = transicionar  # type: ignore[attr-defined]
    mf._conferir_entregavel(uow, uow.caso, _e11(), regra, "sistema")  # type: ignore[arg-type]
    assert ("conferencia.divergente", {"conferencia_id": "conf-1"}) in uow.eventos
    assert ("excecao.aberta", {"excecao_id": "exc-CF-01"}) in uow.eventos
    assert transicoes == [("divergente", "conf-1")]


def test_observado_em_invalido_gera_422_de_validacao() -> None:
    base = {"titular_id": "T1", "competencia": "202609", "tipo": "guia", "fonte": "api"}
    with pytest.raises(ValidationError):
        FatoRequest(**base, observado_em="ontem")  # type: ignore[arg-type]
    ok = FatoRequest(**base, observado_em="2026-09-01T10:00:00Z")  # type: ignore[arg-type]
    assert ok.observado_em.isoformat().startswith("2026-09-01T10:00:00")


def test_abrir_caso_trava_a_chave_antes_de_ler_o_caso_existente() -> None:
    uow = _Fake()
    titular, caso_id, criado = mf.abrir_caso(
        uow, "202609", "ator", {"titular_id": "T1", "carteira": "C1"}  # type: ignore[arg-type]
    )
    assert (titular, caso_id, criado) == ("T1", "caso-1", False)
    assert uow.chamadas == ["lock-titular"]


def test_excecao_de_fato_ausente_herda_classe_s_da_conferencia() -> None:
    uow = _Fake()
    regra = RegraEntregaveis(
        versao_id="rv-antiga", estado_minimo_padrao="validado",
        itens=[ItemEntregavel(
            tipo="E11", executor="sistema", quando=[], depende_de=[], entradas=[],
            saida=None, conferencias=[{"cf": "CF-01"}],
        )],
    )
    uow.transicionar = lambda *a, **k: None  # type: ignore[attr-defined]
    mf._conferir_entregavel(uow, uow.caso, _e11(), regra, "sistema")  # type: ignore[arg-type]
    assert uow.excecoes == ["CF-01"]
    assert uow.classes_excecao == ["S"]  # catálogo diz "D"; a conferência computou "S"


@pytest.mark.parametrize(
    ("regime", "tributo_certo", "tributo_errado"),
    [("SN", "PGDAS-D", "DCTFWEB"), ("LP", "DCTFWEB", "PGDAS-D")],
)
def test_cf12_seleciona_o_recibo_do_tributo_exigido(
    regime: str, tributo_certo: str, tributo_errado: str
) -> None:
    uow = _Fake(caso=_caso(regime))
    certo = _fato("recibo_obrigacao", tributo_certo)
    errado = _fato("recibo_obrigacao", tributo_errado)
    uow.fatos_caso = [errado, certo]  # o errado vem primeiro: busca só por tipo o pegaria
    resultado, _, _, _ = conferir(uow, uow.caso, _e11(), "CF-12")  # type: ignore[arg-type]
    assert resultado == "ok"
    assert [u["fato_id"] for u in uow.confs[0]["fatos_usados"]] == [certo.id]  # type: ignore[attr-defined,union-attr]


def test_cf12_so_com_recibo_do_tributo_errado_e_fato_ausente() -> None:
    uow = _Fake(caso=_caso("SN"))
    uow.fatos_caso = [_fato("recibo_obrigacao", "DCTFWEB")]
    resultado, _, _, classe = conferir(uow, uow.caso, _e11(), "CF-12")  # type: ignore[arg-type]
    assert (resultado, classe) == ("divergente", "S")


def test_conferencia_reaproveitada_vira_a_corrente() -> None:
    uow = _Fake()
    uow.existente = ("divergente", "B", "conf-antiga", "O")
    res = conferir(uow, uow.caso, _e11(), "CF-01")  # type: ignore[arg-type]
    assert res == ("divergente", "B", "conf-antiga", "O")
    assert uow.marcadas == ["conf-antiga"]
    assert uow.confs == []  # nada novo inserido


def test_comandos_que_escrevem_travam_o_titular_antes_de_qualquer_outra_coisa() -> None:
    uow = _Fake()
    ordem: list[str] = []
    uow.travar_titular = lambda t: ordem.append("titular")  # type: ignore[method-assign]
    uow.buscar_caso = lambda t, c: (ordem.append("caso"), None)[1]  # type: ignore[method-assign]
    with pytest.raises(mf.ErroNegocio):
        mf.concluir_tarefa(uow, "T1", "202609", "E1", "a", [])  # type: ignore[arg-type]
    with pytest.raises(mf.ErroNegocio):
        mf.override_conferencia(uow, "T1", "202609", "E1", "CF-01", "a", "motivo ok")  # type: ignore[arg-type]
    assert ordem == ["titular", "caso", "titular", "caso"]
