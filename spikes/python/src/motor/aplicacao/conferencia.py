"""Contexto do caso (§3) e cálculo das conferências (§9), sobre a unidade de trabalho."""
from __future__ import annotations

from motor.aplicacao.portas import UnidadeDeTrabalho
from motor.aplicacao.registros import Caso, Entregavel, Fato
from motor.dominio.competencia import Competencia
from motor.dominio.conferencias import (
    classificar_numerica,
    esperado_das_simples,
    esperado_prolabore,
    soma_parcelas,
)
from motor.dominio.hashing import entrada_hash


def contexto_do_caso(caso: Caso) -> dict[str, object]:
    """Snapshot da empresa mais `mes_do_trimestre` derivado da competência (§3)."""
    ctx: dict[str, object] = dict(caso.snapshot)
    ctx["mes_do_trimestre"] = Competencia(caso.competencia).mes_do_trimestre
    return ctx


def _indexar_por_tributo(fatos: list[Fato], tipo: str) -> dict[str, Fato]:
    return {f.tributo: f for f in fatos if f.tipo == tipo}


def _fato(fatos: list[Fato], tipo: str, tributo: str = "") -> Fato | None:
    for f in fatos:
        if f.tipo == tipo and f.tributo == tributo:
            return f
    return None


class _FatoAusente(Exception):
    def __init__(self, chave: str) -> None:
        super().__init__(chave)
        self.chave = chave


def _como_int(v: object) -> int:
    if isinstance(v, int):
        return v
    raise ValueError(f"esperado inteiro, veio {v!r}")


def _como_lista(v: object) -> list[dict[str, object]]:
    if isinstance(v, list):
        return v
    raise ValueError(f"esperado lista, veio {v!r}")


def _usar(fatos_usados: list[dict[str, object]], f: Fato | None, chave: str) -> Fato:
    if f is None:
        raise _FatoAusente(chave)
    fatos_usados.append({"fato_id": f.id, "versao": f.versao})
    return f


def _tributo_da_guia(uow: UnidadeDeTrabalho, caso: Caso) -> str:
    """Tributo da guia de entrada do E11 resolvida para o caso (DAS no SN, IRPJ no LP)."""
    regime = caso.snapshot.get("regime")
    return "DAS" if regime == "SN" else "IRPJ"


def conferir(
    uow: UnidadeDeTrabalho,
    caso: Caso,
    entregavel: Entregavel,
    cf: str,
) -> str:
    """Calcula, classifica e grava uma conferência (idempotente). Devolve o resultado.

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
            aliquota_bp = _como_int(aliq.payload["aliquota_bp"])
            esperado = esperado_das_simples(_valor(fat), aliquota_bp)
            obtido = _valor(apur)
        elif cf == "CF-05":
            t = _tributo_da_guia(uow, caso)
            apur = _usar(fatos_usados, _fato(fatos, "apurado", t), f"apurado[{t}]")
            guia = _usar(fatos_usados, _fato(fatos, "guia", t), f"guia[{t}]")
            esperado = _valor(apur)
            obtido = _valor(guia)
        elif cf == "CF-06":
            t = _tributo_da_guia(uow, caso)
            guia = _usar(fatos_usados, _fato(fatos, "guia", t), f"guia[{t}]")
            impressa = guia.payload.get("competencia_impressa")
            esperada = caso.competencia
            resultado = "ok" if impressa == esperada else "divergente"
            diferenca = {"esperada": esperada, "impressa": impressa}
            return _gravar(
                uow, caso, entregavel, cf, tipo, tol.versao_id, fatos_usados,
                resultado, None, None, diferenca, severidade,
            )
        elif cf == "CF-07":
            regime = caso.snapshot.get("regime")
            trib = "DAS" if regime == "SN" else "IRPJ"
            apur = _usar(fatos_usados, _fato(fatos, "apurado", trib), f"apurado[{trib}]")
            divisao = _usar(fatos_usados, _fato(fatos, "divisao_socios"), "divisao_socios[]")
            parcelas = divisao.payload.get("parcelas") or []
            esperado = _valor(apur)
            obtido = soma_parcelas(_como_lista(parcelas))
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
            inss_esp = _como_int(folha.payload["inss_centavos"])
            fgts_esp = _como_int(folha.payload["fgts_centavos"])
            dif_inss = abs(inss_esp - _valor(guia_inss))
            dif_fgts = abs(fgts_esp - _valor(guia_fgts))
            # A diferença da conferência é a MAIOR das duas (§9.1).
            if dif_inss >= dif_fgts:
                esperado, obtido = inss_esp, _valor(guia_inss)
            else:
                esperado, obtido = fgts_esp, _valor(guia_fgts)
        elif cf == "CF-12":
            # ok se existe recibo_obrigacao com o tributo exigido pela entrada.
            recibo = next((f for f in fatos if f.tipo == "recibo_obrigacao"), None)
            _usar(fatos_usados, recibo, "recibo_obrigacao[]")
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
            "divergente", None, None, {"erro": f"fato ausente: {e.chave}"}, "S", classe="S",
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
    tipo: object,
    tolerancia_versao_id: str,
    fatos_usados: list[dict[str, object]],
    resultado: str,
    esperado: int | None,
    obtido: int | None,
    diferenca: dict[str, object],
    severidade: str,
    classe: str | None = None,
) -> str:
    from motor.aplicacao.registros import TipoConferencia

    assert isinstance(tipo, TipoConferencia)
    fatos_usados = sorted(fatos_usados, key=lambda d: str(d["fato_id"]))
    classe_final = classe if classe is not None else tipo.classe_padrao
    eh = entrada_hash(cf, fatos_usados, tolerancia_versao_id)
    existente = uow.conferencia_existente(cf, entregavel.id, eh)
    if existente is not None:
        return existente
    uow.inserir_conferencia(
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
    )
    return resultado
