"""Motor do fechamento: abertura, fatos, conclusão de tarefa e avaliação (§5–§10)."""
from __future__ import annotations

from collections.abc import Mapping, Sequence

from motor.aplicacao.conferencia import conferir, contexto_do_caso
from motor.aplicacao.portas import UnidadeDeTrabalho
from motor.aplicacao.registros import Caso, Entregavel, ItemEntregavel, RegraEntregaveis
from motor.dominio.competencia import Competencia
from motor.dominio.condicao import casa
from motor.dominio.estados import EstadoEntregavel as E
from motor.dominio.estados import pelo_menos
from motor.dominio.hashing import hash_fato


# -- erros de negócio (traduzidos para HTTP na camada api) --------------------
class ErroNegocio(Exception):
    def __init__(self, status: int, codigo: str, mensagem: str) -> None:
        super().__init__(mensagem)
        self.status = status
        self.codigo = codigo
        self.mensagem = mensagem


def _nao_encontrado(msg: str) -> ErroNegocio:
    return ErroNegocio(404, "nao_encontrado", msg)


def _conflito(codigo: str, msg: str) -> ErroNegocio:
    return ErroNegocio(409, codigo, msg)


def _invalido(codigo: str, msg: str) -> ErroNegocio:
    return ErroNegocio(422, codigo, msg)


# =============================================================================
# AbrirCompetencias (§5) — uma empresa; o chamador abre uma transação por empresa
# =============================================================================
def abrir_caso(
    uow: UnidadeDeTrabalho, competencia: str, ator: str, empresa: Mapping[str, object]
) -> tuple[str, str, bool]:
    """Devolve (titular_id, caso_id, criado). Idempotente por (titular, competência)."""
    titular_id = str(empresa["titular_id"])
    existente = uow.buscar_caso(titular_id, competencia)
    if existente is not None:
        return (titular_id, existente.id, False)

    regra = uow.regra_entregaveis_em_uso()
    snapshot = {k: v for k, v in empresa.items() if k != "titular_id"}
    snapshot["titular_id"] = titular_id
    carteira = str(empresa["carteira"])
    caso = uow.criar_caso(titular_id, competencia, dict(snapshot), carteira, regra.versao_id)
    ctx = contexto_do_caso(caso)

    criados: dict[str, Entregavel] = {}
    for item in regra.itens:
        if casa(item.quando, ctx):
            area = uow.area_do_tipo(item.tipo)
            ent = uow.criar_entregavel(caso.id, item.tipo, item.executor, area)
            uow.transicao_criacao(ent, ator, regra.versao_id)
            criados[item.tipo] = ent

    # Dependências: só para alvos existentes no caso, com quando casando.
    for item in regra.itens:
        if item.tipo not in criados:
            continue
        for dep in item.depende_de:
            alvo_tipo = str(dep["tipo"])
            dep_quando = dep.get("quando")
            dep_quando_lista = dep_quando if isinstance(dep_quando, list) else None
            if not casa(dep_quando_lista, ctx):
                continue
            alvo = criados.get(alvo_tipo)
            if alvo is None:
                continue  # dependência para entregável inexistente → ignorada
            estado_minimo = str(dep.get("estado_minimo") or regra.estado_minimo_padrao)
            uow.gravar_dependencia(criados[item.tipo].id, alvo.id, estado_minimo)

    uow.gravar_evento("competencia.aberta", caso.id, None, {"caso_id": caso.id})
    avaliar_caso(uow, caso, regra, ator)
    return (titular_id, caso.id, True)


# =============================================================================
# PublicarFato (§6)
# =============================================================================
def publicar_fato(
    uow: UnidadeDeTrabalho,
    titular_id: str,
    competencia: str,
    tipo: str,
    tributo: str,
    valor_centavos: int | None,
    payload: Mapping[str, object],
    fonte: str,
    observado_em: str,
    reavaliar: bool = True,
) -> tuple[str, int, str]:
    """Devolve (fato_id, versao, efeito). efeito ∈ {novo, nova_versao, sem_mudanca}."""
    h = hash_fato(payload, valor_centavos)
    vigente = uow.fato_vigente(titular_id, competencia, tipo, tributo)

    if vigente is not None and vigente.hash == h:
        return (vigente.id, vigente.versao, "sem_mudanca")

    if vigente is None:
        versao, substitui_id, efeito = 1, None, "novo"
    else:
        versao, substitui_id, efeito = vigente.versao + 1, vigente.id, "nova_versao"

    fato = uow.inserir_fato(
        titular_id, competencia, tipo, tributo, versao, valor_centavos,
        dict(payload), fonte, observado_em, h, substitui_id,
    )

    caso_do_fato = uow.buscar_caso(titular_id, competencia)
    uow.gravar_evento(
        "fato.publicado",
        caso_do_fato.id if caso_do_fato else None,
        None,
        {"fato_id": fato.id},
    )

    if reavaliar:
        for caso in uow.casos_abertos_do_titular(titular_id):
            regra = _regra_do_caso(uow, caso)
            avaliar_caso(uow, caso, regra, ator="sistema")

    return (fato.id, versao, efeito)


# =============================================================================
# ConcluirTarefa (§8)
# =============================================================================
def concluir_tarefa(
    uow: UnidadeDeTrabalho,
    titular_id: str,
    competencia: str,
    tipo: str,
    ator: str,
    saidas: Sequence[Mapping[str, object]],
) -> Entregavel:
    caso = uow.buscar_caso(titular_id, competencia)
    if caso is None:
        raise _nao_encontrado(f"caso inexistente: {titular_id}/{competencia}")
    ent = uow.buscar_entregavel(caso.id, tipo)
    if ent is None:
        raise _nao_encontrado(f"entregável inexistente: {tipo}")
    if ent.estado != E.PRONTO or ent.executor == "sistema":
        raise _conflito("entregavel_nao_pronto", f"{tipo} em {ent.estado}, executor {ent.executor}")

    regra = _regra_do_caso(uow, caso)
    item = regra.por_tipo.get(tipo)
    if not saidas or item is None or item.saida is None:
        raise _invalido("sem_saida", f"{tipo} sem saída na regra ou saídas vazias")

    saida_tipo = str(item.saida["tipo"])
    agora = uow.hora()
    for s in saidas:
        trib = str(s.get("tributo") or "")
        payload_s = s.get("payload")
        payload_m: Mapping[str, object] = payload_s if isinstance(payload_s, Mapping) else {}
        publicar_fato(
            uow, caso.titular_id, caso.competencia, saida_tipo, trib,
            _valor_opcional(s.get("valor_centavos")), payload_m,
            "tarefa", agora, reavaliar=False,
        )
        fv = uow.fato_vigente(caso.titular_id, caso.competencia, saida_tipo, trib)
        if fv is not None:
            uow.ligar_fato(ent.id, fv.id, "saida")

    tarefa_id = uow.concluir_tarefa_aberta(ent.id)
    uow.transicionar(
        ent, E.PROCESSADO, "tarefa_concluida", "tarefa", tarefa_id, ator, regra.versao_id
    )
    _conferir_entregavel(uow, caso, ent, regra, ator)

    for caso_aberto in uow.casos_abertos_do_titular(caso.titular_id):
        avaliar_caso(uow, caso_aberto, _regra_do_caso(uow, caso_aberto), ator="sistema")

    atual = uow.buscar_entregavel(caso.id, tipo)
    assert atual is not None
    return atual


# =============================================================================
# AvaliarCaso (§7, §10) — repete até não haver mudança
# =============================================================================
def avaliar_caso(
    uow: UnidadeDeTrabalho, caso: Caso, regra: RegraEntregaveis, ator: str
) -> None:
    ctx = contexto_do_caso(caso)
    while True:
        mudou = False
        entregaveis = uow.entregaveis_do_caso_para_atualizar(caso.id)
        for ent in entregaveis:
            item = regra.por_tipo.get(ent.tipo)
            if item is None:
                continue
            if _passo(uow, caso, ent, item, regra, ctx, ator):
                mudou = True
        if not mudou:
            return


def _passo(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    ent: Entregavel,
    item: ItemEntregavel,
    regra: RegraEntregaveis,
    ctx: Mapping[str, object],
    ator: str,
) -> bool:
    estado = ent.estado
    rv = regra.versao_id

    def tr(para: str, motivo: str, por_tipo: str | None, por_id: str | None, a: str) -> None:
        uow.transicionar(ent, para, motivo, por_tipo, por_id, a, rv)

    if estado in (E.AGUARDANDO_INSUMO, E.INVALIDADO):
        if _dependencias_ok(uow, ent) and _entradas_presentes(uow, caso, item, ctx):
            tr(E.PRONTO, "prontidao", "dependencia", None, "sistema")
            _ligar_entradas(uow, caso, ent, item, ctx)
            if item.executor == "humano":
                uow.criar_tarefa(caso.id, ent.id, "executar_entregavel", caso.carteira)
            return True
        if estado == E.INVALIDADO:
            tr(E.AGUARDANDO_INSUMO, "prontidao", "dependencia", None, "sistema")
            return True
        return False

    if estado == E.PRONTO and item.executor == "sistema":
        tr(E.PROCESSADO, "processamento_sistema", "comando", None, "sistema")
        _conferir_entregavel(uow, caso, ent, regra, ator)
        return True

    if estado == E.VALIDADO:
        pos = item.pos_validacao
        if pos is not None and pos.get("liberacao") == "automatica":
            tr(E.LIBERADO, "liberacao_automatica", "comando", None, "sistema")
            return True
        if item.encerra_caso:
            tr(E.ENCERRADO, "encerramento", "comando", None, "sistema")
            uow.encerrar_caso(caso.id)
            uow.gravar_evento("fechamento.concluido", caso.id, None, {"caso_id": caso.id})
            return True
        return False

    if estado == E.LIBERADO:
        pos = item.pos_validacao
        if pos is not None and _fato_pos(uow, caso, pos, "disponibilizado_quando"):
            tr(E.DISPONIBILIZADO, "documento_disponibilizado", "fato", None, "sistema")
            uow.gravar_evento(
                "documento.disponibilizado", caso.id, ent.id, {"entregavel_id": ent.id}
            )
            return True
        return False

    if estado == E.DISPONIBILIZADO:
        pos = item.pos_validacao
        if pos is not None and _fato_pos(uow, caso, pos, "pago_quando"):
            tr(E.PAGO, "guia_paga", "fato", None, "sistema")
            uow.gravar_evento("guia.paga", caso.id, ent.id, {"entregavel_id": ent.id})
            return True
        return False

    return False


# -- auxiliares ---------------------------------------------------------------
def _regra_do_caso(uow: UnidadeDeTrabalho, caso: Caso) -> RegraEntregaveis:
    # Fase 1: só existe uma versão em uso; o caso usa essa versão.
    return uow.regra_entregaveis_em_uso()


def _dependencias_ok(uow: UnidadeDeTrabalho, ent: Entregavel) -> bool:
    for depende_de_id, estado_minimo in uow.dependencias_do_entregavel(ent.id):
        estado_alvo = uow.estado_do_entregavel_por_id(depende_de_id)
        if not pelo_menos(estado_alvo, estado_minimo):
            return False
    return True


def _entradas_resolvidas(
    caso: Caso, item: ItemEntregavel, ctx: Mapping[str, object]
) -> list[tuple[str, str, str]]:
    """[(competencia, tipo, tributo)] das entradas cujo quando casa."""
    resolvidas: list[tuple[str, str, str]] = []
    for entrada in item.entradas:
        quando = entrada.get("quando")
        quando_lista = quando if isinstance(quando, list) else None
        if not casa(quando_lista, ctx):
            continue
        rel_raw = entrada.get("competencia_relativa", 0)
        rel = rel_raw if isinstance(rel_raw, int) else 0
        comp = Competencia(caso.competencia).somar(rel).valor
        tipo = str(entrada["tipo"])
        tributo = str(entrada.get("tributo") or "")
        resolvidas.append((comp, tipo, tributo))
    return resolvidas


def _entradas_presentes(
    uow: UnidadeDeTrabalho, caso: Caso, item: ItemEntregavel, ctx: Mapping[str, object]
) -> bool:
    for comp, tipo, tributo in _entradas_resolvidas(caso, item, ctx):
        if uow.fato_vigente(caso.titular_id, comp, tipo, tributo) is None:
            return False
    return True


def _ligar_entradas(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    ent: Entregavel,
    item: ItemEntregavel,
    ctx: Mapping[str, object],
) -> None:
    for comp, tipo, tributo in _entradas_resolvidas(caso, item, ctx):
        f = uow.fato_vigente(caso.titular_id, comp, tipo, tributo)
        if f is not None:
            uow.ligar_fato(ent.id, f.id, "entrada")


def _fato_pos(
    uow: UnidadeDeTrabalho, caso: Caso, pos: Mapping[str, object], chave: str
) -> bool:
    """Existe fato pos[chave].tipo com o tributo da guia de entrada do E11?"""
    gatilho = pos.get(chave)
    if not isinstance(gatilho, Mapping):
        return False
    tipo = str(gatilho["tipo"])
    tributo = "DAS" if caso.snapshot.get("regime") == "SN" else "IRPJ"
    return uow.fato_vigente(caso.titular_id, caso.competencia, tipo, tributo) is not None


def _conferir_entregavel(
    uow: UnidadeDeTrabalho, caso: Caso, ent: Entregavel, regra: RegraEntregaveis, ator: str
) -> None:
    """Roda as conferências do item (quando casa) e aplica o desfecho (§9.3)."""
    item = regra.por_tipo[ent.tipo]
    ctx = contexto_do_caso(caso)
    rv = regra.versao_id
    alguma_b_divergente = False
    for cfobj in item.conferencias:
        cf_quando = cfobj.get("quando")
        cf_quando_lista = cf_quando if isinstance(cf_quando, list) else None
        if not casa(cf_quando_lista, ctx):
            continue
        cf = str(cfobj["cf"])
        resultado = conferir(uow, caso, ent, cf)
        if resultado == "divergente":
            tipo_cf = uow.tipo_conferencia(cf)
            if tipo_cf.severidade == "B":
                alguma_b_divergente = True
                uow.criar_excecao(caso.id, ent.id, cf, tipo_cf.classe_padrao, caso.carteira)
                pl: dict[str, object] = {"entregavel_id": ent.id}
                uow.gravar_evento("conferencia.divergente", caso.id, ent.id, pl)
                uow.gravar_evento("excecao.aberta", caso.id, ent.id, pl)

    if alguma_b_divergente:
        uow.transicionar(
            ent, E.DIVERGENTE, "conferencia_divergente", "conferencia", None, ator, rv
        )
    else:
        uow.transicionar(ent, E.VALIDADO, "conferencias_ok", "comando", None, "sistema", rv)
        evento = uow.evento_publicado_do_tipo(ent.tipo)
        if evento is not None:
            uow.gravar_evento(evento, caso.id, ent.id, {"entregavel_id": ent.id})


def _valor_opcional(v: object) -> int | None:
    if v is None:
        return None
    if isinstance(v, int):
        return v
    raise _invalido("valor_invalido", f"valor_centavos deve ser inteiro ou nulo: {v!r}")
