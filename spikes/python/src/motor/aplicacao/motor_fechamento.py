"""Motor do fechamento: abertura, fatos, conclusão de tarefa e avaliação (§5–§10)."""
from __future__ import annotations

import re
from collections.abc import Collection, Mapping, Sequence
from dataclasses import dataclass, field

from motor.aplicacao.conferencia import conferir, contexto_do_caso
from motor.aplicacao.portas import UnidadeDeTrabalho
from motor.aplicacao.registros import Caso, Entregavel, ItemEntregavel, RegraEntregaveis
from motor.dominio.competencia import Competencia
from motor.dominio.condicao import casa
from motor.dominio.dinheiro import ValorForaDoIntervalo, garantir_int64
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


# Ator das transições automáticas (prontidão, processamento, conferências, liberação,
# encerramento, invalidação). Só ações de pessoa gravam o ator informado (abertura, conclusão de
# tarefa, override): ver DUVIDAS D11.
ATOR_SISTEMA = "sistema"


@dataclass
class _Pendencias:
    """O que mudou nesta transação e pede reavaliação (§6 passo 2, §7.4).

    Substitui "reavaliar tudo de todos os casos abertos do titular": só os entregáveis que têm
    uma chave publicada como entrada resolvida ou gatilho de pós-validação, ou que mudaram de
    estado fora da avaliação (invalidação, conclusão de tarefa, override), e o que deles depende.
    """

    fatos: list[tuple[str, str, str]] = field(default_factory=list)  # (competência, tipo, tributo)
    entregaveis: set[tuple[str, str]] = field(default_factory=set)  # (caso_id, tipo) que mudaram


# =============================================================================
# AbrirCompetencias (§5) — uma empresa; o chamador abre uma transação por empresa
# =============================================================================
def abrir_caso(
    uow: UnidadeDeTrabalho, competencia: str, ator: str, empresa: Mapping[str, object]
) -> tuple[str, str, bool]:
    """Devolve (titular_id, caso_id, criado). Idempotente por (titular, competência)."""
    titular_id = str(empresa["titular_id"])
    # Ordem global de locks (nível 1): titular. O concorrente espera o commit e cai em "existente".
    uow.travar_titular(titular_id)
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
    avaliar_caso(uow, caso, regra)
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
    pendencias: _Pendencias | None = None,
    excluir_entregavel_id: str | None = None,
    origem_ref: str | None = None,
) -> tuple[str, int, str]:
    """Devolve (fato_id, versao, efeito). efeito ∈ {novo, nova_versao, sem_mudanca}.

    Sem `pendencias`, reavalia o que o fato afeta ao final (§6 passo 2). Com `pendencias`
    (ConcluirTarefa), só acumula: quem chamou reavalia depois, uma vez.
    """
    try:
        garantir_int64(valor_centavos, "valor_centavos")
    except ValorForaDoIntervalo as e:
        raise _invalido("valor_fora_do_intervalo", str(e)) from e
    h = hash_fato(payload, valor_centavos)
    # Ordem global: 1) titular, 2) chave do fato, 3) linhas de entregável (por tipo).
    # Reentrante: /concluir já travou o titular antes de chamar esta função.
    uow.travar_titular(titular_id)
    uow.travar_chave_fato(titular_id, competencia, tipo, tributo)
    vigente = uow.fato_vigente(titular_id, competencia, tipo, tributo)

    if vigente is not None and vigente.hash == h:
        return (vigente.id, vigente.versao, "sem_mudanca")

    if vigente is None:
        versao, substitui_id, efeito = 1, None, "novo"
    else:
        versao, substitui_id, efeito = vigente.versao + 1, vigente.id, "nova_versao"

    fato = uow.inserir_fato(
        titular_id, competencia, tipo, tributo, versao, valor_centavos,
        dict(payload), fonte, observado_em, h, substitui_id, origem_ref,
    )

    caso_do_fato = uow.buscar_caso(titular_id, competencia)
    uow.gravar_evento(
        "fato.publicado",
        caso_do_fato.id if caso_do_fato else None,
        None,
        {"fato_id": fato.id},
    )

    # §10.3: o estado do E11 é lido ANTES da reavaliação desta publicação.
    e11_disponibilizado_antes = _e11_em_disponibilizado(uow, caso_do_fato)

    pend = _Pendencias() if pendencias is None else pendencias
    pend.fatos.append((competencia, tipo, tributo))
    # §11.4 passo 2: invalidação (sementes = entregáveis com a versão ANTERIOR).
    if efeito == "nova_versao" and vigente is not None:
        pend.entregaveis.update(
            _invalidar(uow, vigente.id, fato.id, excluir_entregavel_id=excluir_entregavel_id)
        )

    if pendencias is None:
        # §11.4 passo 3: reavaliação do que este fato afeta nos casos abertos do titular.
        _reavaliar_titular(uow, titular_id, pend)

    # §10.3: acompanhamento depois do protocolo (gravado, não sem_mudanca).
    _acompanhamento_pos_protocolo(
        uow, caso_do_fato, tipo, tributo, efeito, e11_disponibilizado_antes
    )

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
    uow.travar_titular(titular_id)  # nível 1 da ordem global; antes de qualquer linha
    caso = uow.buscar_caso(titular_id, competencia)
    if caso is None:
        raise _nao_encontrado(f"caso inexistente: {titular_id}/{competencia}")
    ent = uow.buscar_entregavel(caso.id, tipo, para_atualizar=True)
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
    pend = _Pendencias()
    for s in saidas:
        trib = str(s.get("tributo") or "")
        payload_s = s.get("payload")
        payload_m: Mapping[str, object] = payload_s if isinstance(payload_s, Mapping) else {}
        publicar_fato(
            uow, caso.titular_id, caso.competencia, saida_tipo, trib,
            _valor_opcional(s.get("valor_centavos")), payload_m,
            "tarefa", agora, pendencias=pend, excluir_entregavel_id=ent.id,
        )
        fv = uow.fato_vigente(caso.titular_id, caso.competencia, saida_tipo, trib)
        if fv is not None:
            uow.ligar_fato(ent.id, fv.id, "saida")

    tarefa_id = uow.concluir_tarefa_aberta(ent.id)
    uow.transicionar(
        ent, E.PROCESSADO, "tarefa_concluida", "tarefa", tarefa_id, ator, regra.versao_id
    )
    _conferir_entregavel(uow, caso, ent, regra)
    pend.entregaveis.add((caso.id, ent.tipo))  # mudou de estado fora da avaliação

    _reavaliar_titular(uow, caso.titular_id, pend)

    atual = uow.buscar_entregavel(caso.id, tipo)
    assert atual is not None
    return atual


# =============================================================================
# Override de conferência (§8.1)
# =============================================================================
def override_conferencia(
    uow: UnidadeDeTrabalho,
    titular_id: str,
    competencia: str,
    tipo: str,
    cf: str,
    ator: str,
    motivo: str,
) -> Entregavel:
    """Libera um `divergente` B via override (§8.1). Validação 422 → 404 → 409."""
    # 1) corpo inválido → 422 (cf fora de CF-nn ou motivo curto).
    if not re.fullmatch(r"CF-[0-9]{2}", cf, re.ASCII) or len(motivo) < 5:
        raise _invalido("corpo_invalido", f"cf/motivo inválidos: {cf!r}, motivo<5")

    # 2) caso/entregável inexistente → 404.
    uow.travar_titular(titular_id)  # nível 1 da ordem global; antes de qualquer linha
    caso = uow.buscar_caso(titular_id, competencia)
    if caso is None:
        raise _nao_encontrado(f"caso inexistente: {titular_id}/{competencia}")
    ent = uow.buscar_entregavel(caso.id, tipo, para_atualizar=True)
    if ent is None:
        raise _nao_encontrado(f"entregável inexistente: {tipo}")

    # 3) estado/conferência inválidos para override → 409.
    conf = uow.conferencia_vigente(cf, ent.id)
    if (
        ent.estado != E.DIVERGENTE
        or conf is None
        or conf.get("resultado") != "divergente"
        or conf.get("severidade") != "B"
        or conf.get("override_autor") is not None
    ):
        raise _conflito("override_invalido", f"{tipo}/{cf} não permite override")

    conf_id = str(conf["id"])
    regra = _regra_do_caso(uow, caso)

    # Efeito (§8.1).
    uow.aplicar_override(conf_id, ator, motivo)
    uow.resolver_excecoes(ent.id, None, [cf], "override")
    uow.gravar_evento("conferencia.override", caso.id, ent.id, {"conferencia_id": conf_id})

    pend = _Pendencias()
    if not uow.ha_divergente_b_sem_override(ent.id, exceto_cf=cf):
        uow.transicionar(
            ent, E.VALIDADO, "override", "conferencia", conf_id, ator, regra.versao_id
        )
        pend.entregaveis.add((caso.id, ent.tipo))  # mudou de estado fora da avaliação
        evento = uow.evento_publicado_do_tipo(ent.tipo)
        if evento is not None:
            uow.gravar_evento(evento, caso.id, ent.id, {"entregavel_id": ent.id})

    _reavaliar_titular(uow, caso.titular_id, pend)

    atual = uow.buscar_entregavel(caso.id, tipo)
    assert atual is not None
    return atual


# =============================================================================
# AvaliarCaso (§7, §10) — repete até não haver mudança
# =============================================================================
def avaliar_caso(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    regra: RegraEntregaveis,
    sementes: Collection[str] | None = None,
) -> None:
    """Percorre o caso em voltas, em ordem de tipo, um passo por entregável (§7.4).

    `sementes=None` avalia todos os entregáveis (abertura do caso). Com `sementes` (tipos),
    só esses são avaliados; quando um entregável muda, ele próprio e os que dependem dele
    entram na fila (na mesma volta, se vierem depois na ordem de tipo, como no percurso
    completo). Termina quando a fila esvazia, o mesmo ponto fixo do percurso completo.
    """
    ctx = contexto_do_caso(caso)
    fila = None if sementes is None else set(sementes)
    while True:
        mudou = False
        entregaveis = uow.entregaveis_do_caso_para_atualizar(caso.id)
        if fila is not None:
            fila &= {e.tipo for e in entregaveis}  # tipos que o caso não tem não esperam volta
        for ent in entregaveis:
            item = regra.por_tipo.get(ent.tipo)
            if item is None:
                continue
            if fila is not None:
                if ent.tipo not in fila:
                    continue
                fila.discard(ent.tipo)
            if _passo(uow, caso, ent, item, regra, ctx):
                mudou = True
                if fila is not None:
                    fila.add(ent.tipo)
                    fila |= regra.dependentes.get(ent.tipo, frozenset())
        if not (mudou if fila is None else fila):
            return


def _reavaliar_titular(uow: UnidadeDeTrabalho, titular_id: str, pend: _Pendencias) -> None:
    """Reavalia, em cada caso aberto do titular, só o que as pendências afetam (§6 passo 2)."""
    for caso in uow.casos_abertos_do_titular(titular_id):
        regra = _regra_do_caso(uow, caso)
        sementes = _sementes_do_caso(caso, regra, pend)
        if sementes:
            avaliar_caso(uow, caso, regra, sementes=sementes)


def _sementes_do_caso(caso: Caso, regra: RegraEntregaveis, pend: _Pendencias) -> set[str]:
    """Tipos do caso a reavaliar: entrada resolvida (§7.1, com competência relativa) ou gatilho
    de pós-validação (§10) casando com um fato publicado, mais os que mudaram de estado e quem
    depende deles. O resto está no ponto fixo da avaliação anterior e nada o altera."""
    ctx = contexto_do_caso(caso)
    publicadas = set(pend.fatos)
    sementes: set[str] = {tipo for caso_id, tipo in pend.entregaveis if caso_id == caso.id}
    for tipo in list(sementes):
        sementes |= regra.dependentes.get(tipo, frozenset())
    for item in regra.itens:
        if publicadas.intersection(_entradas_resolvidas(caso, item, ctx)):
            sementes.add(item.tipo)
            continue
        gatilho = (item.pos_validacao or {}).get("disponibilizado_quando")
        if isinstance(gatilho, Mapping) and (
            caso.competencia, str(gatilho["tipo"]), _tributo_guia_e11(caso)
        ) in publicadas:
            sementes.add(item.tipo)
    return sementes


def _passo(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    ent: Entregavel,
    item: ItemEntregavel,
    regra: RegraEntregaveis,
    ctx: Mapping[str, object],
) -> bool:
    estado = ent.estado
    rv = regra.versao_id

    def tr(para: str, motivo: str, por_tipo: str | None, por_id: str | None) -> None:
        uow.transicionar(ent, para, motivo, por_tipo, por_id, ATOR_SISTEMA, rv)

    if estado in (E.AGUARDANDO_INSUMO, E.INVALIDADO):
        if _dependencias_ok(uow, ent) and _entradas_presentes(uow, caso, item, ctx):
            tr(E.PRONTO, "prontidao", "dependencia", None)
            _ligar_entradas(uow, caso, ent, item, ctx)
            if item.executor == "humano":
                uow.criar_tarefa(caso.id, ent.id, "executar_entregavel", caso.carteira)
            return True
        if estado == E.INVALIDADO:
            tr(E.AGUARDANDO_INSUMO, "prontidao", "dependencia", None)
            return True
        return False

    if estado == E.PRONTO and item.executor == "sistema":
        tr(E.PROCESSADO, "processamento_sistema", "comando", None)
        _conferir_entregavel(uow, caso, ent, regra)
        return True

    if estado == E.VALIDADO:
        pos = item.pos_validacao
        if pos is not None and pos.get("liberacao") == "automatica":
            tr(E.LIBERADO, "liberacao_automatica", "comando", None)
            return True
        if item.encerra_caso:
            tr(E.ENCERRADO, "encerramento", "comando", None)
            uow.encerrar_caso(caso.id)
            uow.gravar_evento("fechamento.concluido", caso.id, None, {"caso_id": caso.id})
            return True
        return False

    if estado == E.LIBERADO:
        pos = item.pos_validacao
        if pos is not None and _fato_pos(uow, caso, pos, "disponibilizado_quando"):
            tr(E.DISPONIBILIZADO, "documento_disponibilizado", "fato", None)
            fato_id = _guia_vigente_e11(uow, caso)
            uow.gravar_evento(
                "documento.disponibilizado",
                caso.id,
                ent.id,
                {"entregavel_id": ent.id, "fato_id": fato_id},
            )
            return True
        return False

    return False


# -- auxiliares ---------------------------------------------------------------
def _regra_do_caso(uow: UnidadeDeTrabalho, caso: Caso) -> RegraEntregaveis:
    # O caso usa a versão da regra fixada na abertura.
    return uow.regra_entregaveis_da_versao(caso.regra_entregaveis_versao_id)


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


def _tributo_guia_e11(caso: Caso) -> str:
    """Tributo da guia de entrada do E11: DAS no SN, IRPJ no LP (§10.1)."""
    return "DAS" if caso.snapshot.get("regime") == "SN" else "IRPJ"


def _guia_vigente_e11(uow: UnidadeDeTrabalho, caso: Caso) -> str | None:
    """fato_id da versão vigente da guia de entrada do E11, se existir (§10.1)."""
    tributo = _tributo_guia_e11(caso)
    f = uow.fato_vigente(caso.titular_id, caso.competencia, "guia", tributo)
    return f.id if f is not None else None


# -- Invalidação (§11) --------------------------------------------------------
_INVALIDAVEIS = frozenset(
    {E.PRONTO, E.PROCESSADO, E.DIVERGENTE, E.VALIDADO, E.LIBERADO}
)


def _invalidar(
    uow: UnidadeDeTrabalho,
    fato_anterior_id: str,
    fato_novo_id: str,
    excluir_entregavel_id: str | None,
) -> list[tuple[str, str]]:
    """Invalidação por nova versão de fato já usado (§11).

    Devolve (caso_id, tipo) dos entregáveis que foram a `invalidado`, para a reavaliação.


    Sementes = entregáveis ligados à versão ANTERIOR do fato, menos o
    entregável sendo concluído (§8 item 4). Aplica a tabela por estado (§11.2),
    casos encerrados por §11.3, e cascata pelos dependentes — cada entregável
    tratado no máximo uma vez (§11.1).
    """
    sementes = uow.entregaveis_com_fato(fato_anterior_id)
    invalidados: list[tuple[str, str]] = []
    visitados: set[str] = set()
    if excluir_entregavel_id is not None:
        visitados.add(excluir_entregavel_id)
    # Agrupa por caso, em ordem de tipo do entregável (§11.4).
    por_caso: dict[str, list[Entregavel]] = {}
    for s in sementes:
        if s.id in visitados:
            continue
        por_caso.setdefault(s.caso_id, []).append(s)
    for caso_id, ents in por_caso.items():
        caso = uow.caso_por_id(caso_id)
        if caso is None:
            continue
        if caso.estado == "encerrado":
            # §11.3: nada transiciona; uma exceção competencia_encerrada por caso.
            nova = uow.abrir_excecao_unica(
                caso.id, None, "competencia_encerrada", "O", caso.carteira
            )
            if nova is not None:
                uow.gravar_evento("excecao.aberta", caso.id, None, {"excecao_id": nova})
            continue
        for ent in sorted(ents, key=lambda e: e.tipo):
            _invalidar_entregavel(uow, caso, ent, fato_novo_id, visitados, invalidados)
    return invalidados


def _invalidar_entregavel(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    ent: Entregavel,
    fato_novo_id: str,
    visitados: set[str],
    invalidados: list[tuple[str, str]],
) -> None:
    """Aplica a tabela §11.2 a um entregável e cascateia pelos dependentes."""
    if ent.id in visitados:
        return
    visitados.add(ent.id)
    estado = uow.estado_do_entregavel_por_id(ent.id)
    ent.estado = estado

    if estado in _INVALIDAVEIS:
        era_divergente = estado == E.DIVERGENTE
        uow.transicionar(
            ent, E.INVALIDADO, "fato_alterado", "fato", fato_novo_id, ATOR_SISTEMA,
            caso.regra_entregaveis_versao_id,
        )
        invalidados.append((caso.id, ent.tipo))
        uow.gravar_evento("entregavel.invalidado", caso.id, ent.id, {"entregavel_id": ent.id})
        uow.cancelar_tarefa_aberta(ent.id)
        if era_divergente:
            uow.resolver_excecoes(ent.id, "CF-", None, "fato_alterado")
        # Cascata: só a partir de quem foi para invalidado.
        for dep in uow.dependentes_de(caso.id, ent.id):
            _invalidar_entregavel(uow, caso, dep, fato_novo_id, visitados, invalidados)
    elif estado == E.DISPONIBILIZADO:
        # Documento já protocolado precisa ser substituído (§11.2).
        nova = uow.abrir_excecao_unica(caso.id, ent.id, "substituicao", "O", caso.carteira)
        if nova is not None:
            uow.gravar_evento("excecao.aberta", caso.id, ent.id, {"excecao_id": nova})
    # aguardando_insumo, invalidado, encerrado: nada.


# -- Acompanhamento depois do protocolo (§10.3) -------------------------------
def _e11_em_disponibilizado(uow: UnidadeDeTrabalho, caso: Caso | None) -> bool:
    if caso is None:
        return False
    e11 = uow.buscar_entregavel(caso.id, "E11")
    return e11 is not None and e11.estado == E.DISPONIBILIZADO


def _acompanhamento_pos_protocolo(
    uow: UnidadeDeTrabalho,
    caso: Caso | None,
    tipo: str,
    tributo: str,
    efeito: str,
    e11_disponibilizado_antes: bool,
) -> None:
    """Tabela §10.3: só com fato gravado e E11 já em disponibilizado antes."""
    if caso is None or efeito == "sem_mudanca" or not e11_disponibilizado_antes:
        return
    e11 = uow.buscar_entregavel(caso.id, "E11")
    if e11 is None:
        return
    regra = _regra_do_caso(uow, caso)
    item = regra.por_tipo.get("E11")
    acomp = _acompanhamento_do_item(item)
    pl: dict[str, object] = {"entregavel_id": e11.id}
    if tributo != _tributo_guia_e11(caso):
        return  # acompanhamento só vale para o tributo da guia do E11

    if tipo == "documento_disponibilizado" and efeito == "nova_versao":
        # Reenvio: novo protocolo + resolve entrega_falhou.
        fato_id = _guia_vigente_e11(uow, caso)
        uow.gravar_evento(
            "documento.disponibilizado", caso.id, e11.id,
            {"entregavel_id": e11.id, "fato_id": fato_id},
        )
        uow.resolver_excecoes(e11.id, None, ["entrega_falhou"], "reenviado")
    elif tipo == acomp.get("falha"):
        nova = uow.abrir_excecao_unica(caso.id, e11.id, "entrega_falhou", "O", caso.carteira)
        if nova is not None:
            uow.gravar_evento("excecao.aberta", caso.id, e11.id, {"excecao_id": nova})
    elif tipo == acomp.get("confirmacao"):
        uow.gravar_evento("recebimento.confirmado", caso.id, e11.id, pl)
        uow.resolver_excecoes(
            e11.id, None, ["entrega_falhou", "recebimento_pendente"], "recebido"
        )
    elif tipo == acomp.get("pendencia"):
        confirmada = uow.fato_vigente(
            caso.titular_id, caso.competencia, str(acomp.get("confirmacao")), tributo
        )
        if confirmada is None:
            nova = uow.abrir_excecao_unica(
                caso.id, e11.id, "recebimento_pendente", "O", caso.carteira
            )
            if nova is not None:
                uow.gravar_evento("excecao.aberta", caso.id, e11.id, {"excecao_id": nova})


def _acompanhamento_do_item(item: ItemEntregavel | None) -> dict[str, str]:
    """Extrai os tipos de acompanhamento do bloco `acompanhamento` do E11 (§10.3)."""
    if item is None or item.acompanhamento is None:
        return {}
    bloco = item.acompanhamento
    saida: dict[str, str] = {}
    for chave in ("falha", "confirmacao", "pendencia"):
        sub = bloco.get(chave)
        if isinstance(sub, Mapping) and "tipo" in sub:
            saida[chave] = str(sub["tipo"])
    return saida


def _fato_pos(
    uow: UnidadeDeTrabalho, caso: Caso, pos: Mapping[str, object], chave: str
) -> bool:
    """Existe fato pos[chave].tipo com o tributo da guia de entrada do E11?"""
    gatilho = pos.get(chave)
    if not isinstance(gatilho, Mapping):
        return False
    tipo = str(gatilho["tipo"])
    tributo = _tributo_guia_e11(caso)
    return uow.fato_vigente(caso.titular_id, caso.competencia, tipo, tributo) is not None


def _conferir_entregavel(
    uow: UnidadeDeTrabalho, caso: Caso, ent: Entregavel, regra: RegraEntregaveis
) -> None:
    """Roda as conferências do item (quando casa) e aplica o desfecho (§9.3)."""
    item = regra.por_tipo[ent.tipo]
    ctx = contexto_do_caso(caso)
    rv = regra.versao_id
    alguma_b_divergente = False
    ultima_conferencia_id: str | None = None
    for cfobj in item.conferencias:
        cf_quando = cfobj.get("quando")
        cf_quando_lista = cf_quando if isinstance(cf_quando, list) else None
        if not casa(cf_quando_lista, ctx):
            continue
        cf = str(cfobj["cf"])
        try:
            resultado, severidade, conferencia_id, classe_cf, sobrescrita = conferir(
                uow, caso, ent, cf
            )
        except ValorForaDoIntervalo as e:
            raise _invalido("valor_fora_do_intervalo", str(e)) from e
        # §9.2: só divergência B bloqueia e abre exceção; A é registrada e segue.
        # Conferência reaproveitada que já tem override (§8.1) continua sobrescrita.
        if resultado == "divergente" and severidade == "B" and not sobrescrita:
            alguma_b_divergente = True
            ultima_conferencia_id = conferencia_id
            uow.gravar_evento(
                "conferencia.divergente", caso.id, ent.id, {"conferencia_id": conferencia_id}
            )
            nova = uow.abrir_excecao_unica(
                caso.id, ent.id, cf, classe_cf, caso.carteira
            )
            if nova is not None:
                uow.gravar_evento("excecao.aberta", caso.id, ent.id, {"excecao_id": nova})

    if alguma_b_divergente:
        uow.transicionar(
            ent, E.DIVERGENTE, "conferencia_divergente", "conferencia",
            ultima_conferencia_id, ATOR_SISTEMA, rv
        )
    else:
        uow.transicionar(ent, E.VALIDADO, "conferencias_ok", "comando", None, ATOR_SISTEMA, rv)
        evento = uow.evento_publicado_do_tipo(ent.tipo)
        if evento is not None:
            uow.gravar_evento(evento, caso.id, ent.id, {"entregavel_id": ent.id})


def _valor_opcional(v: object) -> int | None:
    if v is None:
        return None
    if isinstance(v, int) and not isinstance(v, bool):
        try:
            return garantir_int64(v, "valor_centavos")
        except ValorForaDoIntervalo as e:
            raise _invalido("valor_fora_do_intervalo", str(e)) from e
    raise _invalido("valor_invalido", f"valor_centavos deve ser inteiro ou nulo: {v!r}")
