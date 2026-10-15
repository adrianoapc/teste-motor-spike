"""Contexto do caso (§3) e cálculo das conferências (§9), sobre a unidade de trabalho."""
from __future__ import annotations

from collections.abc import Mapping, Sequence

from motor.aplicacao.portas import UnidadeDeTrabalho
from motor.aplicacao.registros import (
    Caso,
    Entregavel,
    Fato,
    RegraEntregaveis,
    TipoConferencia,
)
from motor.dominio.competencia import Competencia
from motor.dominio.conferencias import (
    SEMANTICA_CALCULO,
    DadoAusente,
    cf07_lp_par_de_maior_diferenca,
    classificar_numerica,
    esperado_das_simples,
    esperado_prolabore,
    par_de_maior_diferenca,
    soma_parcelas,
)
from motor.dominio.dinheiro import garantir_int64
from motor.dominio.hashing import entrada_hash
from motor.dominio.prontidao import tributo_da_entrada


def contexto_do_caso(caso: Caso) -> dict[str, object]:
    """Snapshot da empresa mais `mes_do_trimestre` derivado da competência (§3)."""
    ctx: dict[str, object] = dict(caso.snapshot)
    ctx["mes_do_trimestre"] = Competencia(caso.competencia).mes_do_trimestre
    return ctx


def _fato(fatos: list[Fato], tipo: str, tributo: str = "") -> Fato | None:
    for f in fatos:
        if f.tipo == tipo and f.tributo == tributo:
            return f
    return None


class _FatoAusente(Exception):
    def __init__(self, chave: str) -> None:
        super().__init__(chave)
        self.chave = chave


def _campo_int(f: Fato, campo: str) -> int:
    """Operando inteiro do payload; ausente ou de tipo errado (float, string, bool) é insumo
    ausente (conferência divergente classe S), nunca exceção."""
    v = f.payload.get(campo)
    if isinstance(v, int) and not isinstance(v, bool):
        return v
    raise _FatoAusente(f"{f.tipo}[{f.tributo}].payload.{campo} ausente ou inválido")


def _parcelas(f: Fato) -> list[dict[str, object]]:
    """parcelas[] do payload, cada uma com valor_centavos inteiro; senão, insumo inválido."""
    v = f.payload.get("parcelas")
    if not isinstance(v, list):
        raise _FatoAusente(f"{f.tipo}[{f.tributo}].payload.parcelas ausente ou inválido")
    saida: list[dict[str, object]] = []
    for p in v:
        if not isinstance(p, dict):
            raise _FatoAusente(f"{f.tipo}[{f.tributo}].payload.parcelas[] inválido")
        saida.append(_parcela_valida(f, p))
    return saida


def _parcela_valida(f: Fato, p: dict[str, object]) -> dict[str, object]:
    val = p.get("valor_centavos")
    if not isinstance(val, int) or isinstance(val, bool):
        raise _FatoAusente(f"{f.tipo}[{f.tributo}].payload.parcelas[].valor_centavos inválido")
    return p


def _usar(fatos_usados: list[dict[str, object]], f: Fato | None, chave: str) -> Fato:
    if f is None:
        raise _FatoAusente(chave)
    fatos_usados.append({"fato_id": f.id, "versao": f.versao})
    return f


def _tributo_exigido(
    entradas: Sequence[Mapping[str, object]], ctx: Mapping[str, object], fato_tipo: str
) -> str:
    """Tributo que a regra pinada exige para o fato `fato_tipo` (ex.: guia DAS no SN, IRPJ no LP).

    A regra declara o tributo nas `entradas` do item; o código não repete o conhecimento.
    Regra sem a entrada = insumo ausente (conferência divergente classe S)."""
    tributo = tributo_da_entrada(entradas, ctx, fato_tipo)
    if tributo is None:
        raise _FatoAusente(f"{fato_tipo}[]: tributo não declarado nas entradas da regra")
    return tributo


def conferir(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    entregavel: Entregavel,
    cf: str,
    regra: RegraEntregaveis,
) -> tuple[str, str, str, str, bool]:
    """Calcula, classifica e grava uma conferência (idempotente).

    `regra` é a regra pinada do caso: dela vêm os tributos exigidos (§9.1), lidos das `entradas`
    do item conferido (guia no E11, recibo no E10) e do item de entrega (apurado do CF-07).

    Devolve (resultado, severidade, conferencia_id, classe, sobrescrita) com a severidade
    COMPUTADA (A/B/I/S), não a do catálogo: divergência A não bloqueia nem abre exceção (§9.2).
    Resultado é 'ok' ou 'divergente'. Fato ausente → divergente classe 'S'.
    """
    tipo = uow.tipo_conferencia(cf)
    tol = uow.tolerancia_em_uso()
    fatos = uow.fatos_vigentes_do_caso(caso.titular_id, caso.competencia)
    fatos_usados: list[dict[str, object]] = []
    esperado: int | None = None
    obtido: int | None = None
    diferenca: dict[str, object] = {}
    resultado = "ok"
    severidade = tipo.severidade
    ctx = contexto_do_caso(caso)
    item = regra.por_tipo.get(entregavel.tipo)
    entradas = item.entradas if item is not None else []

    try:
        if cf == "CF-01":
            fat = _usar(fatos_usados, _fato(fatos, "faturamento_mes"), "faturamento_mes[]")
            notas = _usar(fatos_usados, _fato(fatos, "notas_oneflow"), "notas_oneflow[]")
            esperado = _valor(fat)
            obtido = _valor(notas)
        elif cf == "CF-04":
            fat = _usar(fatos_usados, _fato(fatos, "faturamento_mes"), "faturamento_mes[]")
            aliq = _usar(fatos_usados, _fato(fatos, "aliquota_mes"), "aliquota_mes[]")
            apur = _usar(fatos_usados, _fato(fatos, "apurado", "DAS"), "apurado[DAS]")
            aliquota_bp = _campo_int(aliq, "aliquota_bp")
            esperado = esperado_das_simples(_valor(fat), aliquota_bp)
            obtido = _valor(apur)
        elif cf == "CF-05":
            t = _tributo_exigido(entradas, ctx, "guia")
            apur = _usar(fatos_usados, _fato(fatos, "apurado", t), f"apurado[{t}]")
            guia = _usar(fatos_usados, _fato(fatos, "guia", t), f"guia[{t}]")
            esperado = _valor(apur)
            obtido = _valor(guia)
        elif cf == "CF-06":
            t = _tributo_exigido(entradas, ctx, "guia")
            guia = _usar(fatos_usados, _fato(fatos, "guia", t), f"guia[{t}]")
            impressa = guia.payload.get("competencia_impressa")
            if not isinstance(impressa, str):
                raise _FatoAusente(f"guia[{t}].payload.competencia_impressa ausente ou inválido")
            esperada = caso.competencia
            resultado = "ok" if impressa == esperada else "divergente"
            diferenca = {"esperada": esperada, "impressa": impressa}
            return _gravar(
                uow, caso, entregavel, cf, tipo, tol.versao_id, fatos_usados,
                resultado, None, None, diferenca, severidade,
            )
        elif cf == "CF-07":
            if ctx.get("regime") == "LP":
                # LP (v4, §9.1): tributo a tributo. Tributos = apurados do caso ∪ tributos das
                # parcelas; par do tributo de maior diferença. apurado[T] ausente ou parcela
                # sem tributo = dado ausente (classe S).
                divisao = _usar(fatos_usados, _fato(fatos, "divisao_socios"), "divisao_socios[]")
                parcelas = _parcelas(divisao)
                apurados: dict[str, int] = {}
                for f in fatos:
                    if f.tipo == "apurado" and f.tributo != "":
                        apurados[f.tributo] = _valor(f)
                try:
                    trib_vencedor, esperado, obtido = cf07_lp_par_de_maior_diferenca(
                        apurados, parcelas
                    )
                except DadoAusente as e:
                    raise _FatoAusente(e.chave) from e
                # Só os apurados usados no cálculo (os dos tributos conferidos) entram em
                # fatos_usados, para a chave de idempotência refletir o que foi lido.
                for f in fatos:
                    if f.tipo == "apurado" and f.tributo in apurados:
                        fatos_usados.append({"fato_id": f.id, "versao": f.versao})
            else:
                # SN (§9.1): apurado[DAS] = tributo da guia de entrada do item de entrega; a
                # regra não declara `apurado` nas entradas do E04.
                entrega = regra.item_de_entrega
                trib = _tributo_exigido(entrega.entradas if entrega else [], ctx, "guia")
                apur = _usar(fatos_usados, _fato(fatos, "apurado", trib), f"apurado[{trib}]")
                divisao = _usar(fatos_usados, _fato(fatos, "divisao_socios"), "divisao_socios[]")
                parcelas = _parcelas(divisao)
                esperado = _valor(apur)
                obtido = soma_parcelas(parcelas)
        elif cf == "CF-08":
            fat = _usar(fatos_usados, _fato(fatos, "faturamento_mes"), "faturamento_mes[]")
            pro = _usar(fatos_usados, _fato(fatos, "prolabore"), "prolabore[]")
            params = uow.parametros_em_uso()
            esperado = esperado_prolabore(_valor(fat), params.prolabore_percentual_bp)
            obtido = _valor(pro)
        elif cf == "CF-09":
            folha = _usar(fatos_usados, _fato(fatos, "folha"), "folha[]")
            guia_inss = _usar(fatos_usados, _fato(fatos, "guia", "INSS"), "guia[INSS]")
            guia_fgts = _usar(fatos_usados, _fato(fatos, "guia", "FGTS"), "guia[FGTS]")
            guia_irrf = _usar(fatos_usados, _fato(fatos, "guia", "IRRF"), "guia[IRRF]")
            # IRRF (v4): esperado vem da folha do MÊS ANTERIOR (competencia_relativa −1), que
            # pode ser de competência sem caso aberto. Lido pela chave explícita (§7.1, §9.1).
            comp_anterior = Competencia(caso.competencia).somar(-1).valor
            folha_ant = uow.fato_vigente(caso.titular_id, comp_anterior, "folha", "")
            folha_ant = _usar(fatos_usados, folha_ant, f"folha[{comp_anterior}]")
            inss_esp = _campo_int(folha, "inss_centavos")
            fgts_esp = _campo_int(folha, "fgts_centavos")
            irrf_esp = _campo_int(folha_ant, "irrf_centavos")
            # §9.1: três pares; a diferença da conferência é a MAIOR; empate: INSS, FGTS, IRRF.
            esperado, obtido = par_de_maior_diferenca([
                (inss_esp, _valor(guia_inss)),
                (fgts_esp, _valor(guia_fgts)),
                (irrf_esp, _valor(guia_irrf)),
            ])
        elif cf == "CF-12":
            # ok se existe recibo_obrigacao com o tributo exigido pela entrada.
            trib_recibo = _tributo_exigido(entradas, ctx, "recibo_obrigacao")
            recibo = _fato(fatos, "recibo_obrigacao", trib_recibo)
            _usar(fatos_usados, recibo, f"recibo_obrigacao[{trib_recibo}]")
            resultado = "ok"
            diferenca = {"centavos": 0}
            return _gravar(
                uow, caso, entregavel, cf, tipo, tol.versao_id, fatos_usados,
                resultado, None, None, diferenca, severidade,
            )
        else:
            raise ValueError(f"CF não implementada: {cf}")
    except _FatoAusente as e:
        return _gravar(
            uow, caso, entregavel, cf, tipo, tol.versao_id, fatos_usados,
            "divergente", None, None, {"erro": f"fato ausente: {e.chave}"}, tipo.severidade,
            classe="S",
        )

    assert esperado is not None and obtido is not None
    resultado, severidade, dif = classificar_numerica(
        esperado, obtido, tol.ok_ate_centavos, tol.alerta_ate_centavos, tipo.severidade
    )
    diferenca = {"centavos": dif}
    return _gravar(
        uow, caso, entregavel, cf, tipo, tol.versao_id, fatos_usados,
        resultado, esperado, obtido, diferenca, severidade,
    )


def _valor(f: Fato) -> int:
    if f.valor_centavos is None:
        raise _FatoAusente(f"{f.tipo}[{f.tributo}] sem valor")
    return f.valor_centavos


def _gravar(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    entregavel: Entregavel,
    cf: str,
    tipo: TipoConferencia,
    tolerancia_versao_id: str,
    fatos_usados: list[dict[str, object]],
    resultado: str,
    esperado: int | None,
    obtido: int | None,
    diferenca: dict[str, object],
    severidade: str,
    classe: str | None = None,
) -> tuple[str, str, str, str, bool]:
    # esperado/obtido vão para colunas bigint: o cálculo em Python não estoura, o insert sim.
    garantir_int64(esperado, "esperado_centavos")
    garantir_int64(obtido, "obtido_centavos")
    fatos_usados = sorted(fatos_usados, key=lambda d: str(d["fato_id"]))
    classe_final = classe if classe is not None else tipo.classe_padrao
    eh = entrada_hash(cf, fatos_usados, tolerancia_versao_id, SEMANTICA_CALCULO)
    existente = uow.conferencia_existente(cf, entregavel.id, eh)
    if existente is not None:
        # Reaproveitada (A→B→A): passa a ser a avaliação corrente do entregável.
        # Se já tinha override (A com override → B → A), segue sobrescrita: não bloqueia.
        uow.marcar_conferencia_corrente(existente[2])
        return existente
    conferencia_id = uow.inserir_conferencia(
        cf=cf,
        caso_id=caso.id,
        entregavel_id=entregavel.id,
        regra_versao_id=tolerancia_versao_id,
        fatos_usados=fatos_usados,
        entrada_hash=eh,
        resultado=resultado,
        esperado_centavos=esperado,
        obtido_centavos=obtido,
        diferenca=diferenca,
        severidade=severidade,
        classe=classe_final,
        semantica_calculo=SEMANTICA_CALCULO,
    )
    return (resultado, severidade, conferencia_id, classe_final, False)
