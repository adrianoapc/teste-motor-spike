#!/usr/bin/env python3
"""Gera cenarios/c*.json. Fonte única dos cenários do spike.

Os valores esperados são derivados à mão de docs/semantica-do-motor.md (v4); cada
cenário cita as seções que exercita. Rodar da raiz:  python cenarios/fonte/gerar_cenarios.py
"""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

SAIDA = Path(__file__).resolve().parent.parent
# v5 (§6.4): observado_em decide a ordem entre versões de uma chave, e a saída de tarefa (§8) usa o
# relógio do servidor. Por isso, por padrão, o cenário pede ao verificador o instante do envio
# ("$agora"), que fica na mesma linha do tempo das tarefas. Instantes fixos só onde o cenário testa
# ordem (C21, C22), e só em chaves que nenhuma tarefa produz.
AGORA = "$agora"
SEQ4 = ["aguardando_insumo", "pronto", "processado", "validado"]
E11_PROT = SEQ4 + ["liberado", "disponibilizado"]
E12_ENC = SEQ4 + ["encerrado"]


# ---------------------------------------------------------------------------
# Construtores de passo
# ---------------------------------------------------------------------------
def empresa(tit: str, regime: str, folha: bool = False, prolabore: bool = False, taxa: bool = False,
            carteira: str = "C-01", fator_r: bool | None = None) -> dict[str, Any]:
    e: dict[str, Any] = {"titular_id": tit, "regime": regime, "tem_folha": folha, "tem_prolabore": prolabore,
                         "tem_taxa_municipal": taxa, "filial": False, "carteira": carteira, "municipio": "3549805"}
    if fator_r is not None:  # v4: campo opcional; ausente = false (§3)
        e["sujeito_fator_r"] = fator_r
    return e


def abrir(comp: str, *empresas: dict[str, Any], criados: int | None = None, existentes: int = 0,
          descricao: str | None = None) -> dict[str, Any]:
    p: dict[str, Any] = {"acao": "abrir_competencias", "corpo": {"competencia": comp, "ator": "agenda", "empresas": list(empresas)},
                         "espera": {"status": 200, "criados": len(empresas) if criados is None else criados, "existentes": existentes}}
    if descricao:
        p = {"acao": p["acao"], "descricao": descricao, "corpo": p["corpo"], "espera": p["espera"]}
    return p


def fato(tit: str, comp: str, tipo: str, valor: int | None = None, tributo: str = "", payload: dict[str, Any] | None = None,
         fonte: str = "spike", efeito: str = "novo", descricao: str | None = None, sem_efeito: bool = False,
         observado_em: str | None = None, chave_idempotencia: str | None = None) -> dict[str, Any]:
    obs = observado_em if observado_em is not None else AGORA
    corpo: dict[str, Any] = {"titular_id": tit, "competencia": comp, "tipo": tipo, "fonte": fonte, "observado_em": obs}
    if chave_idempotencia is not None:
        corpo["chave_idempotencia"] = chave_idempotencia
    if tributo:
        corpo["tributo"] = tributo
    if valor is not None:
        corpo["valor_centavos"] = valor
    if payload is not None:
        corpo["payload"] = payload
    espera: dict[str, Any] = {"efeito": efeito}
    if sem_efeito:  # v4 §6 passo 2: idempotência da reavaliação
        espera["sem_efeito_nos_casos"] = True
    p: dict[str, Any] = {"acao": "publicar_fato", "corpo": corpo, "espera": espera}
    if descricao:
        p = {"acao": "publicar_fato", "descricao": descricao, "corpo": corpo, "espera": espera}
    return p


def ruido(tit: str, comp: str, descricao: str) -> dict[str, Any]:
    """Fato de um tipo que nenhuma regra consome: reavaliar o titular inteiro é permitido, mas não pode gravar nada."""
    return fato(tit, comp, "anotacao_interna", None, payload={"texto": descricao}, fonte="intranet", descricao=descricao, sem_efeito=True)


def concluir(tit: str, comp: str, tipo: str, saidas: list[dict[str, Any]], status: int = 200,
             ator: str = "analista-c01") -> dict[str, Any]:
    return {"acao": "concluir_entregavel", "titular_id": tit, "competencia": comp, "tipo": tipo,
            "corpo": {"ator": ator, "saidas": saidas}, "espera": {"status": status}}


def override(tit: str, comp: str, tipo: str, cf: str, motivo: str, status: int, ator: str = "coord-fiscal",
             descricao: str | None = None) -> dict[str, Any]:
    p: dict[str, Any] = {"acao": "override", "titular_id": tit, "competencia": comp, "tipo": tipo,
                         "corpo": {"ator": ator, "cf": cf, "motivo": motivo}, "espera": {"status": status}}
    if descricao:
        p["descricao"] = descricao
    return p


AGUARDAR = {"acao": "aguardar_fila"}


def verificar(nome: str, esperado: dict[str, Any], final: bool = False) -> dict[str, Any]:
    v: dict[str, Any] = {"acao": "verificar", "nome": nome}
    if final:
        v["final"] = True
    v["esperado"] = esperado
    return v


def ent(tit: str, comp: str, tipo: str, estado: str, transicoes: list[str] | None = None) -> dict[str, Any]:
    e: dict[str, Any] = {"titular_id": tit, "competencia": comp, "tipo": tipo, "estado": estado}
    if transicoes is not None:
        e["transicoes"] = transicoes
    return e


def conf(tit: str, comp: str, tipo: str, cf: str, resultado: str = "ok", quantidade: int = 1) -> dict[str, Any]:
    return {"titular_id": tit, "competencia": comp, "tipo": tipo, "cf": cf, "resultado": resultado, "quantidade": quantidade}


def conf_seq(tit: str, comp: str, tipo: str, cf: str, resultados: list[str], override: list[int] | None = None,
             valores: list[list[int | None]] | None = None, classes: list[str] | None = None,
             semantica: int | None = None) -> dict[str, Any]:
    c: dict[str, Any] = {"titular_id": tit, "competencia": comp, "tipo": tipo, "cf": cf, "resultados": resultados}
    if override is not None:
        c["override"] = override
    if valores is not None:
        c["valores"] = valores
    if classes is not None:
        c["classes"] = classes
    if semantica is not None:
        c["semantica_calculo"] = semantica
    return c


def exc(tit: str, comp: str, entregavel: str | None, tipo: str, estados: list[str], classe: str | None = None) -> dict[str, Any]:
    x: dict[str, Any] = {"titular_id": tit, "competencia": comp, "entregavel": entregavel, "tipo": tipo, "estados": estados}
    if classe:
        x["classe"] = classe
    return x


def eventos(tit: str, comp: str, **contagens: int) -> list[dict[str, Any]]:
    return [{"titular_id": tit, "competencia": comp, "nome": k.replace("__", "."), "quantidade": v}
            for k, v in contagens.items()]


def tarefa(tit: str, comp: str, tipo: str) -> dict[str, str]:
    return {"titular_id": tit, "competencia": comp, "tipo": tipo}


def versoes(tit: str, comp: str, tipo: str, n: int, tributo: str = "") -> dict[str, Any]:
    return {"titular_id": tit, "competencia": comp, "tipo": tipo, "tributo": tributo, "versoes": n}


def guia_payload(comp: str) -> dict[str, str]:
    return {"competencia_impressa": comp, "vencimento": "2026-10-20"}


def cenario(cid: str, fase: float, nome: str, descricao: str, secoes: list[str], regras: list[str],
            titulares: list[str], passos: list[dict[str, Any]]) -> dict[str, Any]:
    return {"id": cid, "fase": fase, "nome": nome, "descricao": descricao, "secoes_semantica": secoes,
            "regras_usadas": regras, "titulares": titulares, "passos": passos}


R_ALL = ["fechamento.entregaveis v3", "fiscal.tolerancia v2", "fiscal.parametros v1"]
R_SEM_PARAM = ["fechamento.entregaveis v3", "fiscal.tolerancia v2"]
ANT = "202608"  # competência anterior a C: a folha dela entra na CF-09 de C (v4, par IRRF)
C = "202609"


# ---------------------------------------------------------------------------
# C1 · fase 1
# ---------------------------------------------------------------------------
def c1() -> dict[str, Any]:
    t = "T001"
    emp = empresa(t, "SN", folha=True, prolabore=True, fator_r=True)
    passos = [
        abrir(C, emp),
        abrir(C, emp, criados=0, existentes=1, descricao="repetir a abertura é idempotente"),
        AGUARDAR,
        verificar("após abertura", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, x, "aguardando_insumo") for x in ["E01", "E02", "E03", "E04", "E07", "E08", "E10", "E11", "E12"]]
                           + [ent(t, C, "E06", "pronto")],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": "E05"}],
            "tarefas_abertas": [tarefa(t, C, "E06")]}),
        fato(t, C, "faturamento_mes", 10000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 10000000, fonte="oneflow"),
        fato(t, C, "notas_oneflow", 10000000, fonte="oneflow", efeito="sem_mudanca"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        verificar("insumos completos", {
            "entregaveis": [ent(t, C, "E01", "validado"), ent(t, C, "E02", "validado"),
                            ent(t, C, "E03", "pronto"), ent(t, C, "E07", "aguardando_insumo")],
            "tarefas_abertas": [tarefa(t, C, "E03"), tarefa(t, C, "E06")],
            "fatos": [versoes(t, C, "notas_oneflow", 1)]}),
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 600000}]),
        concluir(t, C, "E06", [{"valor_centavos": 500000, "payload": {"inss_centavos": 110000, "fgts_centavos": 40000, "irrf_centavos": 52000}}]),
        AGUARDAR,
        concluir(t, C, "E07", [{"valor_centavos": 2800000}]),
        fato(t, ANT, "folha", 500000, payload={"inss_centavos": 110000, "fgts_centavos": 40000, "irrf_centavos": 51500}, fonte="dp",
             descricao="folha do mês anterior (sem caso aberto): o IRRF dela é declarado na DCTFWeb deste mês (v4)"),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 360000}, {"socio": "S2", "valor_centavos": 240000}]}, fonte="calculo"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "R-0001"}, fonte="integra"),
        fato(t, C, "guia", 110000, tributo="INSS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 40000, tributo="FGTS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 51500, tributo="IRRF", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 600000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental"),
        AGUARDAR,
        verificar("guias validadas, antes da entrega", {
            "entregaveis": [ent(t, C, "E11", "liberado"), ent(t, C, "E12", "aguardando_insumo")],
            "tarefas_abertas": []}),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado", "regra_entregaveis_versao": 3}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E04", "E07", "E08", "E10"]]
                           + [ent(t, C, "E06", "validado", SEQ4), ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": "E05"}],
            "conferencias": [conf(t, C, tp, cf) for tp, cf in [("E01", "CF-01"), ("E03", "CF-04"), ("E04", "CF-07"), ("E07", "CF-08"),
                                                               ("E08", "CF-09"), ("E10", "CF-12"), ("E11", "CF-05"), ("E11", "CF-06")]],
            "conferencias_total": 8,
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "notas_oneflow", 1), versoes(t, C, "apurado", 1, "DAS"), versoes(t, C, "folha", 1), versoes(t, C, "prolabore", 1)],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=13, insumos__completos=1, apuracao__concluida=1,
                               folha__fechada=2, guias__validadas=2, documento__disponibilizado=1, recebimento__confirmado=0, guia__paga=0,
                               fechamento__concluido=1, conferencia__divergente=0, excecao__aberta=0, entregavel__invalidado=0),
        }, final=True),
    ]
    return cenario("C1", 1, "Feliz: Simples Nacional com folha e pró-labore",
                   "Empresa do Simples com folha e pró-labore fecha a competência inteira sem divergência. Testa abertura por regra, prontidão por dependência e entrada, tarefas humanas, conferências CF-01/04/05/06/07/08/09/12, pós-validação do E11 até o protocolo de entrega, encerramento pelo E12 no protocolo (sem esperar pagamento), versão em uso da regra (v3), idempotência de fato e eventos. v4: empresa sujeita ao Fator R (CF-08 roda) e CF-09 com o IRRF da folha do mês anterior.",
                   ["§5", "§6", "§7", "§8", "§9", "§10", "§12"], R_ALL, [t], passos)


# ---------------------------------------------------------------------------
# Trecho LP 3º mês comum a C7 e C8
# ---------------------------------------------------------------------------
def lp_terceiro_mes(t: str) -> list[dict[str, Any]]:
    return [
        abrir(C, empresa(t, "LP", carteira="C-02")),
        fato(t, C, "faturamento_mes", 20000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 20000000, fonte="oneflow"),
        AGUARDAR,
        verificar("sem os meses anteriores, E03 não fica pronto", {
            "entregaveis": [ent(t, C, "E01", "validado"), ent(t, C, "E03", "aguardando_insumo")],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E02", "E05", "E06", "E07", "E08"]],
            "tarefas_abertas": []}),
        fato(t, "202607", "apurado", 150000, tributo="IRPJ", fonte="intranet-legado"),
        AGUARDAR,
        verificar("com só um mês anterior, continua aguardando", {
            "entregaveis": [ent(t, C, "E03", "aguardando_insumo")], "tarefas_abertas": []}),
        fato(t, "202608", "apurado", 155000, tributo="IRPJ", fonte="intranet-legado"),
        AGUARDAR,
        verificar("com os dois meses, E03 fica pronto", {
            "entregaveis": [ent(t, C, "E03", "pronto")], "tarefas_abertas": [tarefa(t, C, "E03")]}),
        concluir(t, C, "E03", [{"tributo": "IRPJ", "valor_centavos": 160000}]),
        AGUARDAR,
    ]


def c7() -> dict[str, Any]:
    t = "T002"
    passos = lp_terceiro_mes(t) + [
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, "E01", "validado", SEQ4), ent(t, C, "E03", "validado", SEQ4)]
                           + [ent(t, C, x, "aguardando_insumo", ["aguardando_insumo"]) for x in ["E04", "E10", "E11", "E12"]],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E02", "E05", "E06", "E07", "E08"]],
            "conferencias": [conf(t, C, "E01", "CF-01")],
            "conferencias_total": 1,
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, "202607", "apurado", 1, "IRPJ"), versoes(t, "202608", "apurado", 1, "IRPJ"), versoes(t, C, "apurado", 1, "IRPJ")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=3, insumos__completos=1, apuracao__concluida=1,
                               fechamento__concluido=0, conferencia__divergente=0),
        }, final=True),
    ]
    return cenario("C7", 1, "Lucro Presumido, 3º mês do trimestre",
                   "Empresa do Lucro Presumido na competência 09 (3º mês do trimestre). A apuração (E03) só fica pronta quando existirem os apurados de IRPJ das competências 07 e 08. Testa entrada com competência relativa, reavaliação disparada por fato de outra competência, e que E02/E06/E07/E08 não existem para essa empresa. Para na conclusão do E03; o encerramento do LP está no C8.",
                   ["§3", "§5", "§6", "§7.1", "§7.2", "§8"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C8 · fase 2 · LP ponta a ponta
# ---------------------------------------------------------------------------
def c8() -> dict[str, Any]:
    t = "T008"
    passos = lp_terceiro_mes(t) + [
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "tributo": "IRPJ", "valor_centavos": 96000},
                                                                 {"socio": "S2", "tributo": "IRPJ", "valor_centavos": 64000}]}, fonte="calculo"),
        fato(t, C, "recibo_obrigacao", None, tributo="DCTFWEB", payload={"numero_recibo": "D-0001"}, fonte="integra"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "X-0001"}, fonte="integra",
             descricao="recibo de outro regime: grava, mas não é entrada de nenhum entregável desta empresa LP"),
        AGUARDAR,
        verificar("E04 e E10 validados; E11 aguarda a guia de IRPJ", {
            "entregaveis": [ent(t, C, "E04", "validado"), ent(t, C, "E10", "validado"), ent(t, C, "E11", "aguardando_insumo")]}),
        fato(t, C, "guia", 160000, tributo="IRPJ", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "documento_disponibilizado", None, tributo="IRPJ", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E03", "E04", "E10"]]
                           + [ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E02", "E05", "E06", "E07", "E08"]],
            "conferencias": [conf(t, C, tp, cf) for tp, cf in [("E01", "CF-01"), ("E04", "CF-07"), ("E10", "CF-12"), ("E11", "CF-05"), ("E11", "CF-06")]],
            "conferencias_total": 5,
            "excecoes_total": 0,
            "tarefas_abertas": [],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=8, insumos__completos=1, apuracao__concluida=1,
                               guias__validadas=1, documento__disponibilizado=1, guia__paga=0, fechamento__concluido=1,
                               conferencia__divergente=0, excecao__aberta=0),
        }, final=True),
    ]
    return cenario("C8", 2, "Lucro Presumido, 3º mês, até o encerramento",
                   "Continua o C7 até o caso encerrar: divisão por sócio sobre o IRPJ (CF-07), recibo de DCTFWeb (CF-12), guia de IRPJ (CF-05 e CF-06), protocolo de entrega e E12. Cobre o caminho de encerramento do LP que o C7 não exercita (observação de 07/10). Um recibo de PGDAS-D publicado por engano para empresa LP é gravado e não afeta nada.",
                   ["§7.1", "§9.1", "§10.1", "§10.2"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C2 · fase 2 · R4: folha reaberta depois da entrega do pacote
# ---------------------------------------------------------------------------
def c2() -> dict[str, Any]:
    t = "T003"
    E08_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "aguardando_insumo",
               "pronto", "processado", "divergente", "invalidado", "pronto", "processado", "validado"]
    E06_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "pronto", "processado", "validado"]
    passos = [
        abrir(C, empresa(t, "SN", folha=True)),
        fato(t, ANT, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 0}, fonte="dp",
             descricao="folha do mês anterior sem IRRF retido (v4)"),
        fato(t, C, "faturamento_mes", 8000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 8000000, fonte="oneflow"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 480000}]),
        concluir(t, C, "E06", [{"valor_centavos": 400000, "payload": {"inss_centavos": 88000, "fgts_centavos": 32000}}]),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 288000}, {"socio": "S2", "valor_centavos": 192000}]}, fonte="calculo"),
        fato(t, C, "guia", 88000, tributo="INSS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 32000, tributo="FGTS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 0, tributo="IRRF", payload=guia_payload(C), fonte="porta_documental",
             descricao="DCTFWeb do mês sem código 0561: o adaptador publica IRRF = 0 (v4 §16)"),
        fato(t, C, "guia", 480000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("pacote protocolado; caso aberto esperando o recibo do PGDAS-D", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, "E06", "validado"), ent(t, C, "E08", "validado"),
                            ent(t, C, "E11", "disponibilizado"), ent(t, C, "E12", "aguardando_insumo")],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E05", "E07"]],
            "excecoes_total": 0, "tarefas_abertas": []}),
        fato(t, C, "folha", 400000, payload={"inss_centavos": 92400, "fgts_centavos": 32000}, fonte="dp", efeito="nova_versao",
             descricao="folha reaberta pelo DP (correção de INSS no eSocial), publicada pelo adaptador"),
        AGUARDAR,
        verificar("folha reaberta: E06 volta a pronto, E08 aguarda, pacote entregue vira exceção de substituição", {
            "entregaveis": [ent(t, C, "E06", "pronto"), ent(t, C, "E08", "aguardando_insumo"),
                            ent(t, C, "E11", "disponibilizado"), ent(t, C, "E12", "aguardando_insumo")],
            "tarefas_abertas": [tarefa(t, C, "E06")],
            "excecoes": [exc(t, C, "E11", "substituicao", ["aberta:"], "O")],
            "excecoes_total": 1}),
        concluir(t, C, "E06", [{"valor_centavos": 400000, "payload": {"inss_centavos": 92400, "fgts_centavos": 32000}}]),
        AGUARDAR,
        verificar("DP conclui de novo: guia de INSS antiga não bate com a folha nova", {
            "entregaveis": [ent(t, C, "E06", "validado"), ent(t, C, "E08", "divergente")],
            "excecoes": [exc(t, C, "E08", "CF-09", ["aberta:"], "O")],
            "fatos": [versoes(t, C, "folha", 2)]}),
        fato(t, C, "guia", 92400, tributo="INSS", payload=guia_payload(C), fonte="porta_documental", efeito="nova_versao",
             descricao="guia de INSS recalculada"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "R-0003"}, fonte="integra",
             descricao="recibo do PGDAS-D: último insumo; o caso encerra"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E04", "E10"]]
                           + [ent(t, C, "E06", "validado", E06_SEQ), ent(t, C, "E08", "validado", E08_SEQ),
                              ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "conferencias": [conf(t, C, tp, cf) for tp, cf in [("E01", "CF-01"), ("E03", "CF-04"), ("E04", "CF-07"), ("E10", "CF-12"),
                                                               ("E11", "CF-05"), ("E11", "CF-06")]]
                            + [conf_seq(t, C, "E08", "CF-09", ["ok:B", "divergente:B", "ok:B"])],
            "conferencias_total": 9,
            "excecoes": [exc(t, C, "E11", "substituicao", ["aberta:"]), exc(t, C, "E08", "CF-09", ["resolvida:fato_alterado"])],
            "excecoes_total": 2,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "folha", 2), versoes(t, C, "guia", 2, "INSS"), versoes(t, C, "guia", 1, "FGTS"), versoes(t, C, "guia", 1, "IRRF")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=14, insumos__completos=1, apuracao__concluida=1,
                               folha__fechada=2, guias__validadas=3, documento__disponibilizado=1, guia__paga=0,
                               fechamento__concluido=1, conferencia__divergente=1, excecao__aberta=2, entregavel__invalidado=3),
        }, final=True),
    ]
    return cenario("C2", 2, "R4: folha reaberta depois de o pacote ser entregue",
                   "Simples com folha. O pacote da competência já foi disponibilizado ao cliente (E11) quando o DP reabre a folha e corrige o INSS. A folha nova invalida o E06 (que volta a pronto com tarefa nova) e, em cascata, o E08. O E11, já disponibilizado, não muda de estado: ganha exceção de substituição. Ao concluir de novo, a guia de INSS antiga diverge da folha (CF-09); a guia recalculada invalida o E08 de novo e resolve a exceção. O recibo do PGDAS-D, último insumo, encerra o caso com a substituição ainda aberta (o protocolo já tinha acontecido; pagamento não entra no fechamento). O E12 não é afetado por estar em aguardando_insumo. Fator R do mês seguinte fica fora do spike.",
                   ["§8", "§9.4", "§11.1", "§11.2"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C3 · fase 2 · R1: nota cancelada depois da apuração, DAS já protocolado
# ---------------------------------------------------------------------------
def c3() -> dict[str, Any]:
    t = "T004"
    E01_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "pronto", "processado", "divergente",
               "invalidado", "pronto", "processado", "validado"]
    E03_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "aguardando_insumo", "pronto", "processado", "validado"]
    E04_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "aguardando_insumo", "pronto", "processado",
               "divergente", "invalidado", "pronto", "processado", "validado"]
    passos = [
        abrir(C, empresa(t, "SN")),
        fato(t, C, "faturamento_mes", 10000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 10000000, fonte="oneflow"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 600000}]),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 360000}, {"socio": "S2", "valor_centavos": 240000}]}, fonte="calculo"),
        fato(t, C, "guia", 600000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("DAS protocolado, caso aberto esperando o recibo do PGDAS-D", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, "E11", "disponibilizado"), ent(t, C, "E10", "aguardando_insumo"), ent(t, C, "E12", "aguardando_insumo")],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E05", "E06", "E07", "E08"]]}),
        fato(t, C, "faturamento_mes", 9500000, fonte="intranet", efeito="nova_versao",
             descricao="nota de R$ 5.000,00 cancelada: faturamento do mês cai"),
        AGUARDAR,
        verificar("cancelamento: E01 diverge do OneFlow, cascata invalida E03 e E04, DAS já protocolado vira exceção de substituição", {
            "entregaveis": [ent(t, C, "E01", "divergente"), ent(t, C, "E02", "validado"), ent(t, C, "E03", "aguardando_insumo"),
                            ent(t, C, "E04", "aguardando_insumo"), ent(t, C, "E10", "aguardando_insumo"), ent(t, C, "E11", "disponibilizado")],
            "excecoes": [exc(t, C, "E11", "substituicao", ["aberta:"], "O"), exc(t, C, "E01", "CF-01", ["aberta:"], "O")],
            "excecoes_total": 2, "tarefas_abertas": []}),
        fato(t, C, "notas_oneflow", 9500000, fonte="oneflow", efeito="nova_versao", descricao="OneFlow sincroniza o cancelamento"),
        AGUARDAR,
        verificar("insumos batem de novo; apuração volta para o analista", {
            "entregaveis": [ent(t, C, "E01", "validado"), ent(t, C, "E03", "pronto"), ent(t, C, "E04", "aguardando_insumo")],
            "tarefas_abertas": [tarefa(t, C, "E03")],
            "excecoes": [exc(t, C, "E01", "CF-01", ["resolvida:fato_alterado"])]}),
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 570000}]),
        AGUARDAR,
        verificar("nova apuração: divisão antiga não fecha com o DAS novo", {
            "entregaveis": [ent(t, C, "E03", "validado"), ent(t, C, "E04", "divergente")],
            "excecoes": [exc(t, C, "E04", "CF-07", ["aberta:"], "S")],
            "fatos": [versoes(t, C, "apurado", 2, "DAS")]}),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 342000}, {"socio": "S2", "valor_centavos": 228001}]},
             fonte="calculo", efeito="nova_versao", descricao="divisão refeita com 1 centavo de arredondamento: divergência A, não bloqueia"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "R-0004"}, fonte="integra"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, "E01", "validado", E01_SEQ), ent(t, C, "E02", "validado", SEQ4), ent(t, C, "E03", "validado", E03_SEQ),
                            ent(t, C, "E04", "validado", E04_SEQ), ent(t, C, "E10", "validado", SEQ4),
                            ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "conferencias": [conf_seq(t, C, "E01", "CF-01", ["ok:B", "divergente:B", "ok:B"]),
                             conf_seq(t, C, "E03", "CF-04", ["ok:B", "ok:B"]),
                             conf_seq(t, C, "E04", "CF-07", ["ok:B", "divergente:B", "divergente:A"]),
                             conf(t, C, "E10", "CF-12"), conf(t, C, "E11", "CF-05"), conf(t, C, "E11", "CF-06")],
            "conferencias_total": 11,
            "excecoes": [exc(t, C, "E11", "substituicao", ["aberta:"]), exc(t, C, "E01", "CF-01", ["resolvida:fato_alterado"]),
                         exc(t, C, "E04", "CF-07", ["resolvida:fato_alterado"])],
            "excecoes_total": 3,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "faturamento_mes", 2), versoes(t, C, "notas_oneflow", 2), versoes(t, C, "apurado", 2, "DAS"),
                      versoes(t, C, "divisao_socios", 2), versoes(t, C, "guia", 1, "DAS")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=12, insumos__completos=2, apuracao__concluida=2,
                               guias__validadas=1, documento__disponibilizado=1, guia__paga=0, fechamento__concluido=1,
                               conferencia__divergente=2, excecao__aberta=3, entregavel__invalidado=5),
        }, final=True),
    ]
    return cenario("C3", 2, "R1: nota cancelada depois da apuração, com o DAS já protocolado",
                   "Simples sem folha. Depois de o DAS ser protocolado ao cliente (e antes do recibo do PGDAS-D), uma nota é cancelada. O faturamento novo invalida o E01 e, em cascata, E03 e E04; o E11, já disponibilizado, ganha exceção de substituição (se o cliente já pagou o DAS antigo, a diferença é da Regularidade Fiscal, fora do fechamento). O E01 diverge do OneFlow até ele sincronizar. O analista refaz a apuração (a nova versão do apurado não invalida o próprio E03, §8 item 4). A divisão antiga diverge (B); a refeita tem 1 centavo de diferença (A) e não bloqueia. O recibo encerra o caso com a substituição ainda aberta.",
                   ["§8", "§9.2", "§9.3", "§9.4", "§11.1", "§11.2"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C4 · fase 2 · R6: guia retificada duas vezes
# ---------------------------------------------------------------------------
def c4() -> dict[str, Any]:
    t = "T005"
    E11_SEQ = ["aguardando_insumo", "pronto", "processado", "divergente", "invalidado", "pronto", "processado", "divergente",
               "invalidado", "pronto", "processado", "validado", "liberado", "disponibilizado"]
    passos = [
        abrir(C, empresa(t, "SN")),
        fato(t, C, "faturamento_mes", 5000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 5000000, fonte="oneflow"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 300000}]),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 180000}, {"socio": "S2", "valor_centavos": 120000}]}, fonte="calculo"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "R-0005"}, fonte="integra"),
        fato(t, C, "guia", 30000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental",
             descricao="guia salva com valor errado (R$ 300,00 em vez de R$ 3.000,00)"),
        AGUARDAR,
        verificar("guia v1: valor não bate com o apurado", {
            "entregaveis": [ent(t, C, "E11", "divergente")],
            "excecoes": [exc(t, C, "E11", "CF-05", ["aberta:"], "O")]}),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload("202608"), fonte="porta_documental", efeito="nova_versao",
             descricao="retificação 1: valor certo, mas salva com a competência anterior impressa"),
        AGUARDAR,
        verificar("guia v2: valor certo, competência errada", {
            "entregaveis": [ent(t, C, "E11", "divergente")],
            "excecoes": [exc(t, C, "E11", "CF-05", ["resolvida:fato_alterado"]), exc(t, C, "E11", "CF-06", ["aberta:"], "O")]}),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental", efeito="nova_versao",
             descricao="retificação 2: guia correta"),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental", efeito="sem_mudanca",
             descricao="mesma guia salva de novo pelo operacional: não muda nada"),
        AGUARDAR,
        verificar("guia v3 vale; nada foi apagado", {
            "entregaveis": [ent(t, C, "E11", "liberado")],
            "excecoes_abertas": 0,
            "fatos": [versoes(t, C, "guia", 3, "DAS")]}),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E04", "E10"]]
                           + [ent(t, C, "E11", "disponibilizado", E11_SEQ), ent(t, C, "E12", "encerrado", E12_ENC)],
            "conferencias": [conf(t, C, tp, cf) for tp, cf in [("E01", "CF-01"), ("E03", "CF-04"), ("E04", "CF-07"), ("E10", "CF-12")]]
                            + [conf_seq(t, C, "E11", "CF-05", ["divergente:B", "ok:B", "ok:B"]),
                               conf_seq(t, C, "E11", "CF-06", ["ok:B", "divergente:B", "ok:B"])],
            "conferencias_total": 10,
            "excecoes": [exc(t, C, "E11", "CF-05", ["resolvida:fato_alterado"]), exc(t, C, "E11", "CF-06", ["resolvida:fato_alterado"])],
            "excecoes_total": 2,
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "guia", 3, "DAS")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=10, insumos__completos=1, apuracao__concluida=1,
                               guias__validadas=1, documento__disponibilizado=1, guia__paga=0, fechamento__concluido=1,
                               conferencia__divergente=2, excecao__aberta=2, entregavel__invalidado=2),
        }, final=True),
    ]
    return cenario("C4", 2, "R6: guia retificada duas vezes",
                   "Simples sem folha. A guia do DAS chega três vezes: valor errado (CF-05 diverge), valor certo com competência impressa errada (CF-06 diverge), e correta. Cada versão invalida o E11 e resolve a exceção da versão anterior; só a última vale e nenhuma versão é apagada. Reenviar a mesma guia não muda nada. Substitui a volta H do as-is (exclusão manual de guia por guia).",
                   ["§6", "§9.1", "§9.4", "§11.2"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C5 · fase 2 · R7: alteração retroativa em competência encerrada
# ---------------------------------------------------------------------------
def c5() -> dict[str, Any]:
    t = "T006"
    J, A = "202607", "202609"
    E03_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "pronto", "processado", "validado"]
    passos = [
        abrir(J, empresa(t, "LP", carteira="C-03")),
        fato(t, J, "faturamento_mes", 12000000, fonte="intranet"),
        fato(t, J, "notas_oneflow", 12000000, fonte="oneflow"),
        AGUARDAR,
        concluir(t, J, "E03", [{"tributo": "IRPJ", "valor_centavos": 150000}]),
        fato(t, J, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "tributo": "IRPJ", "valor_centavos": 90000},
                                                                 {"socio": "S2", "tributo": "IRPJ", "valor_centavos": 60000}]}, fonte="calculo"),
        fato(t, J, "recibo_obrigacao", None, tributo="DCTFWEB", payload={"numero_recibo": "D-0607"}, fonte="integra"),
        fato(t, J, "guia", 150000, tributo="IRPJ", payload=guia_payload(J), fonte="porta_documental"),
        fato(t, J, "documento_disponibilizado", None, tributo="IRPJ", payload={"canal": "nibo"}, fonte="entrega"),
        AGUARDAR,
        verificar("julho encerrado no protocolo (LP, 1º mês do trimestre)", {
            "casos": [{"titular_id": t, "competencia": J, "estado": "encerrado"}]}),
        abrir(A, empresa(t, "LP", carteira="C-03")),
        fato(t, A, "faturamento_mes", 13000000, fonte="intranet"),
        fato(t, A, "notas_oneflow", 13000000, fonte="oneflow"),
        fato(t, "202608", "apurado", 155000, tributo="IRPJ", fonte="intranet-legado"),
        AGUARDAR,
        concluir(t, A, "E03", [{"tributo": "IRPJ", "valor_centavos": 160000}]),
        AGUARDAR,
        verificar("setembro com a apuração validada, usando julho e agosto", {
            "casos": [{"titular_id": t, "competencia": A, "estado": "aberto"}],
            "entregaveis": [ent(t, A, "E03", "validado")], "tarefas_abertas": []}),
        fato(t, J, "apurado", 90000, tributo="IRPJ", fonte="equiparacao", efeito="nova_versao",
             descricao="equiparação hospitalar com vigência retroativa reduz o IRPJ de julho"),
        AGUARDAR,
        verificar("julho não reabre; setembro, que usa julho, volta para o analista", {
            "casos": [{"titular_id": t, "competencia": J, "estado": "encerrado"}, {"titular_id": t, "competencia": A, "estado": "aberto"}],
            "entregaveis": [ent(t, A, "E03", "pronto")],
            "tarefas_abertas": [tarefa(t, A, "E03")],
            "excecoes": [exc(t, J, None, "competencia_encerrada", ["aberta:"], "O")]}),
        concluir(t, A, "E03", [{"tributo": "IRPJ", "valor_centavos": 160000}]),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": J, "estado": "encerrado"}, {"titular_id": t, "competencia": A, "estado": "aberto"}],
            "entregaveis": [ent(t, J, x, "validado", SEQ4) for x in ["E01", "E03", "E04", "E10"]]
                           + [ent(t, J, "E11", "disponibilizado", E11_PROT), ent(t, J, "E12", "encerrado", E12_ENC),
                              ent(t, A, "E01", "validado", SEQ4), ent(t, A, "E03", "validado", E03_SEQ)]
                           + [ent(t, A, x, "aguardando_insumo", ["aguardando_insumo"]) for x in ["E04", "E10", "E11", "E12"]],
            "conferencias": [conf(t, J, tp, cf) for tp, cf in [("E01", "CF-01"), ("E04", "CF-07"), ("E10", "CF-12"), ("E11", "CF-05"), ("E11", "CF-06")]]
                            + [conf(t, A, "E01", "CF-01")],
            "conferencias_total": 6,
            "excecoes": [exc(t, J, None, "competencia_encerrada", ["aberta:"], "O")],
            "excecoes_total": 1,
            "tarefas_abertas": [],
            "fatos": [versoes(t, J, "apurado", 2, "IRPJ"), versoes(t, "202608", "apurado", 1, "IRPJ"), versoes(t, A, "apurado", 1, "IRPJ")],
            "eventos": eventos(t, J, competencia__aberta=1, fato__publicado=8, fechamento__concluido=1, excecao__aberta=1, entregavel__invalidado=0)
                       + eventos(t, A, competencia__aberta=1, fato__publicado=3, apuracao__concluida=2, entregavel__invalidado=1, excecao__aberta=0),
        }, final=True),
    ]
    return cenario("C5", 2, "R7: alteração retroativa em competência encerrada",
                   "Lucro Presumido. Julho (1º mês do trimestre) fecha e encerra no protocolo de entrega. Setembro (3º mês) apura usando os IRPJ de julho e agosto. Uma equiparação com vigência retroativa muda o IRPJ de julho: o caso de julho não reabre nem muda nenhum estado, só ganha a exceção competencia_encerrada (em produção, o gatilho do serviço retroativo S10); o caso de setembro, aberto, tem a apuração invalidada porque usou o IRPJ de julho como entrada.",
                   ["§7.1", "§9.4", "§11.1", "§11.2", "§11.3"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C9 · fase 2 · override
# ---------------------------------------------------------------------------
def c9() -> dict[str, Any]:
    t = "T009"
    motivo = "Nota emitida fora do OneFlow, conferida no portal da prefeitura"
    passos = [
        abrir(C, empresa(t, "SN")),
        fato(t, C, "faturamento_mes", 5000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 4995000, fonte="oneflow", descricao="OneFlow sem uma nota de R$ 50,00"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        verificar("E01 diverge (CF-01, B) e segura a apuração", {
            "entregaveis": [ent(t, C, "E01", "divergente"), ent(t, C, "E02", "validado"), ent(t, C, "E03", "aguardando_insumo")],
            "excecoes": [exc(t, C, "E01", "CF-01", ["aberta:"], "O")], "tarefas_abertas": []}),
        override(t, C, "E01", "CF-01", "ok", 422, descricao="motivo curto demais"),
        override("T999", C, "E01", "CF-01", motivo, 404, descricao="caso inexistente"),
        override(t, C, "E02", "CF-01", motivo, 409, descricao="entregável não está divergente"),
        override(t, C, "E01", "CF-04", motivo, 409, descricao="CF sem conferência no entregável"),
        override(t, C, "E01", "CF-01", motivo, 200, descricao="coordenação aceita a divergência com motivo"),
        override(t, C, "E01", "CF-01", motivo, 409, descricao="override repetido"),
        AGUARDAR,
        verificar("override libera o E01 e a apuração", {
            "entregaveis": [ent(t, C, "E01", "validado", ["aguardando_insumo", "pronto", "processado", "divergente", "validado"]),
                            ent(t, C, "E03", "pronto")],
            "conferencias": [conf_seq(t, C, "E01", "CF-01", ["divergente:B"], override=[0])],
            "excecoes": [exc(t, C, "E01", "CF-01", ["resolvida:override"])],
            "tarefas_abertas": [tarefa(t, C, "E03")]}),
        fato(t, C, "notas_oneflow", 5000000, fonte="oneflow", efeito="nova_versao", descricao="OneFlow sincroniza a nota que faltava"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, "E01", "validado", ["aguardando_insumo", "pronto", "processado", "divergente", "validado",
                                                          "invalidado", "pronto", "processado", "validado"]),
                            ent(t, C, "E02", "validado", SEQ4),
                            ent(t, C, "E03", "pronto", ["aguardando_insumo", "pronto", "invalidado", "aguardando_insumo", "pronto"])],
            "conferencias": [conf_seq(t, C, "E01", "CF-01", ["divergente:B", "ok:B"], override=[0])],
            "conferencias_total": 2,
            "excecoes": [exc(t, C, "E01", "CF-01", ["resolvida:override"])],
            "excecoes_total": 1,
            "tarefas_abertas": [tarefa(t, C, "E03")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=4, insumos__completos=2, conferencia__divergente=1,
                               conferencia__override=1, excecao__aberta=1, entregavel__invalidado=2, apuracao__concluida=0),
        }, final=True),
    ]
    return cenario("C9", 2, "Override de conferência",
                   "Simples sem folha. O OneFlow está sem uma nota e a CF-01 diverge (B). Validações do override (422, 404, 409 em três situações) e o override válido: grava autor e motivo na conferência, resolve a exceção, leva o E01 a validado e libera a apuração. Quando o OneFlow sincroniza, a nova versão invalida o E01 já validado por override e, em cascata, o E03 que estava pronto com tarefa: a tarefa é cancelada, o E03 passa por aguardando_insumo enquanto o E01 é reconferido (§7.4) e volta a pronto com tarefa nova.",
                   ["§8.1", "§9.4", "§11.2"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C10 · fase 2 · acompanhamento depois do protocolo (v3, §10.3)
# ---------------------------------------------------------------------------
def c10() -> dict[str, Any]:
    t = "T010"
    canal_email = {"canal": "email"}
    passos = [
        abrir(C, empresa(t, "SN", carteira="C-04")),
        fato(t, C, "faturamento_mes", 6000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 6000000, fonte="oneflow"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 360000}]),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 360000}]}, fonte="calculo"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": "R-0010"}, fonte="integra"),
        fato(t, C, "guia", 360000, tributo="DAS", payload=guia_payload(C), fonte="porta_documental"),
        AGUARDAR,
        fato(t, C, "recebimento_pendente", None, tributo="DAS", payload={"dias_sem_leitura": 0}, fonte="agendador",
             descricao="agendador dispara antes do protocolo: o fato só é gravado (§10.3, quando aplicar)"),
        AGUARDAR,
        verificar("guia liberada; pendência antecipada não abre exceção", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "aberto"}],
            "entregaveis": [ent(t, C, "E11", "liberado"), ent(t, C, "E12", "aguardando_insumo")],
            "excecoes_total": 0}),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload=canal_email, fonte="entrega",
             descricao="protocolo: e-mail disparado"),
        AGUARDAR,
        verificar("protocolo encerra o caso", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado", "regra_entregaveis_versao": 3}],
            "entregaveis": [ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "excecoes_total": 0,
            "eventos": eventos(t, C, documento__disponibilizado=1, fechamento__concluido=1)}),
        fato(t, C, "entrega_falhou", None, tributo="DAS", payload={"canal": "email", "motivo": "caixa_inexistente"}, fonte="entrega",
             descricao="e-mail voltou depois do encerramento"),
        fato(t, C, "entrega_falhou", None, tributo="DAS", payload={"canal": "email", "motivo": "caixa_inexistente"}, fonte="entrega",
             efeito="sem_mudanca", descricao="o mesmo aviso de falha chega de novo: nada muda"),
        AGUARDAR,
        verificar("falha depois do encerramento: exceção com dono, caso continua encerrado, nada transiciona", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "excecoes": [exc(t, C, "E11", "entrega_falhou", ["aberta:"], "O")],
            "excecoes_total": 1}),
        fato(t, C, "recebimento_pendente", None, tributo="DAS", payload={"dias_sem_leitura": 5}, fonte="agendador",
             efeito="nova_versao", descricao="agendador: 5 dias sem leitura, e o fato agora é aplicado"),
        AGUARDAR,
        verificar("sem leitura: segunda exceção do acompanhamento", {
            "excecoes": [exc(t, C, "E11", "entrega_falhou", ["aberta:"], "O"), exc(t, C, "E11", "recebimento_pendente", ["aberta:"], "O")],
            "excecoes_abertas": 2}),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "app"}, fonte="entrega", efeito="nova_versao",
             descricao="reenvio pelo App: novo protocolo, resolve a falha"),
        AGUARDAR,
        verificar("reenvio resolve a falha; pendência de leitura continua", {
            "excecoes": [exc(t, C, "E11", "entrega_falhou", ["resolvida:reenviado"]), exc(t, C, "E11", "recebimento_pendente", ["aberta:"])],
            "excecoes_abertas": 1,
            "eventos": eventos(t, C, documento__disponibilizado=2, recebimento__confirmado=0)}),
        fato(t, C, "entrega_confirmada", None, tributo="DAS", payload={"canal": "app", "como": "leitura_pelo_link"}, fonte="entrega",
             descricao="cliente abriu pelo link da Rissi"),
        fato(t, C, "recebimento_pendente", None, tributo="DAS", payload={"dias_sem_leitura": 10}, fonte="agendador", efeito="nova_versao",
             descricao="agendador roda de novo: já há confirmação, nada acontece"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado", "regra_entregaveis_versao": 3}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E04", "E10"]]
                           + [ent(t, C, "E11", "disponibilizado", E11_PROT), ent(t, C, "E12", "encerrado", E12_ENC)],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": x} for x in ["E05", "E06", "E07", "E08"]],
            "conferencias_total": 6,
            "excecoes": [exc(t, C, "E11", "entrega_falhou", ["resolvida:reenviado"], "O"),
                         exc(t, C, "E11", "recebimento_pendente", ["resolvida:recebido"], "O")],
            "excecoes_total": 2,
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "documento_disponibilizado", 2, "DAS"), versoes(t, C, "entrega_falhou", 1, "DAS"),
                      versoes(t, C, "recebimento_pendente", 3, "DAS"), versoes(t, C, "entrega_confirmada", 1, "DAS")],
            "eventos": eventos(t, C, competencia__aberta=1, fato__publicado=14, documento__disponibilizado=2, recebimento__confirmado=1,
                               fechamento__concluido=1, excecao__aberta=2, entregavel__invalidado=0, guia__paga=0),
        }, final=True),
    ]
    return cenario("C10", 2, "Acompanhamento depois do protocolo",
                   "Simples sem folha. O caso encerra no protocolo (e-mail disparado), sem esperar leitura nem pagamento. Depois do encerramento, o e-mail volta: abre exceção entrega_falhou com dono, sem reabrir o caso e sem nenhuma transição. O agendador avisa que não houve leitura (recebimento_pendente; antes do protocolo o mesmo aviso só foi gravado). O reenvio pelo App gera novo protocolo e resolve a falha; a confirmação de leitura gera recebimento.confirmado e resolve a pendência; um aviso de pendência posterior à confirmação não faz nada. O N dias vem do agendador, porque o motor não tem relógio.",
                   ["§6", "§9.4", "§10.1", "§10.2", "§10.3", "§12", "§15"], R_SEM_PARAM, [t], passos)


# ---------------------------------------------------------------------------
# C6 · fase 3 · escala
# ---------------------------------------------------------------------------
def entregaveis_esperados_massa(massa_param: dict[str, Any]) -> int:
    import importlib.util
    spec = importlib.util.spec_from_file_location("g", SAIDA.parent / "massa" / "gerar_massa_sintetica.py")
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    m = mod.gerar(massa_param["empresas"], massa_param["competencia"], massa_param["semente"],
                  0.70, 0.30, 0.85, 0.20, 50, 300)
    total = 0
    for e in m["abrir"]["empresas"]:
        n = 6  # E01, E03, E04, E10, E11, E12
        n += e["regime"] == "SN"                      # E02
        n += e["tem_taxa_municipal"]                  # E05
        n += e["tem_folha"]                           # E06
        n += e["tem_prolabore"]                       # E07
        n += e["tem_folha"] or e["tem_prolabore"]     # E08
        total += n
    return total


def c6() -> dict[str, Any]:
    massa = {"empresas": 4500, "competencia": C, "semente": 20261006}
    passos = [
        {"acao": "abrir_massa", "massa": massa, "lote": 500, "espera": {"criados": 4500, "limite_segundos": 300}},
        {"acao": "publicar_lote", "massa": massa, "origem": "guias_dctfweb", "concorrencia": 20,
         "espera": {"p95_segundos": 30, "limite_segundos": 600}},
        verificar("final", {
            "casos_total": 4500,
            "entregaveis_total": entregaveis_esperados_massa(massa),
            "fatos_total": [{"tipo": "guia", "tributo": "INSS", "quantidade": 300}],
        }, final=True),
    ]
    return cenario("C6", 3, "Escala: 4.500 casos no dia 1 e 300 guias em lote",
                   "Massa sintética determinística (massa/gerar_massa_sintetica.py, semente 20261006): abre 4.500 casos em lotes de 500 (limite total de 300 s) e publica 300 guias de INSS com 20 requisições simultâneas (p95 abaixo de 30 s). Confere totais, as invariantes e o tempo das consultas de indicador (I1–I5 abaixo de 2 s) com esse volume.",
                   ["§5", "§6", "§13"], R_SEM_PARAM, [], passos)


# ---------------------------------------------------------------------------
# Fase 4 · manutenção M1 (semântica v4, §16)
# ---------------------------------------------------------------------------
def c11() -> dict[str, Any]:
    a, b = "T011", "T012"
    E01_B = ["aguardando_insumo", "pronto", "processado", "divergente", "invalidado", "pronto", "processado", "validado"]
    passos = [
        abrir(C, empresa(a, "SN", prolabore=True, fator_r=True), empresa(b, "SN", prolabore=True, carteira="C-02")),
        fato(a, C, "faturamento_mes", 10000000, fonte="intranet"),
        fato(a, C, "notas_oneflow", 9999990, fonte="oneflow", descricao="10 centavos a menos: alerta, não bloqueia"),
        fato(a, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        fato(b, C, "faturamento_mes", 10000000, fonte="intranet"),
        fato(b, C, "notas_oneflow", 9999989, fonte="oneflow", descricao="11 centavos a menos: bloqueia"),
        fato(b, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        verificar("10 centavos passa como alerta; 11 centavos bloqueia", {
            "entregaveis": [ent(a, C, "E01", "validado"), ent(a, C, "E03", "pronto"),
                            ent(b, C, "E01", "divergente"), ent(b, C, "E03", "aguardando_insumo")],
            "conferencias": [conf_seq(a, C, "E01", "CF-01", ["divergente:A"], valores=[[10000000, 9999990]]),
                             conf_seq(b, C, "E01", "CF-01", ["divergente:B"], valores=[[10000000, 9999989]])],
            "excecoes": [exc(a, C, "E01", "CF-01", []), exc(b, C, "E01", "CF-01", ["aberta:"], "O")],
            "tarefas_abertas": [tarefa(a, C, "E03")]}),
        ruido(a, C, "anotação com tarefa aberta: não pode criar outra tarefa"),
        ruido(b, C, "anotação com exceção aberta: não pode abrir outra exceção nem reconferir"),
        concluir(a, C, "E03", [{"tributo": "DAS", "valor_centavos": 600000}]),
        AGUARDAR,
        concluir(a, C, "E07", [{"valor_centavos": 2800000}]),
        fato(b, C, "notas_oneflow", 10000000, fonte="oneflow", efeito="nova_versao", descricao="OneFlow sincroniza a nota de 11 centavos"),
        AGUARDAR,
        concluir(b, C, "E03", [{"tributo": "DAS", "valor_centavos": 600000}]),
        AGUARDAR,
        concluir(b, C, "E07", [{"valor_centavos": 1500000}],
                 ator="analista-c02"),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(a, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E07"]]
                           + [ent(b, C, "E01", "validado", E01_B)] + [ent(b, C, x, "validado", SEQ4) for x in ["E02", "E03", "E07"]],
            "conferencias": [conf_seq(a, C, "E01", "CF-01", ["divergente:A"]), conf_seq(a, C, "E03", "CF-04", ["ok:B"]),
                             conf_seq(a, C, "E07", "CF-08", ["ok:B"], valores=[[2800000, 2800000]], semantica=3),
                             conf_seq(b, C, "E01", "CF-01", ["divergente:B", "ok:B"]), conf_seq(b, C, "E03", "CF-04", ["ok:B"]),
                             conf(b, C, "E07", "CF-08", quantidade=0)],
            "conferencias_total": 6,
            "excecoes": [exc(a, C, "E01", "CF-01", []), exc(b, C, "E01", "CF-01", ["resolvida:fato_alterado"], "O")],
            "tarefas_abertas": [],
            "eventos": eventos(a, C, conferencia__divergente=0, excecao__aberta=0, entregavel__invalidado=0, folha__fechada=1)
                       + eventos(b, C, conferencia__divergente=1, excecao__aberta=1, entregavel__invalidado=1, folha__fechada=1),
        }, final=True),
    ]
    return cenario("C11", 4, "M1: tolerância de 10 centavos e CF-08 só com Fator R",
                   "Duas empresas do Simples com pró-labore. Na primeira (sujeita ao Fator R) o OneFlow tem 10 centavos a menos: a CF-01 grava divergência A e o E01 segue validado; a CF-08 roda e confere os 28%. Na segunda (sem o campo sujeito_fator_r, que vale false) faltam 11 centavos: a CF-01 bloqueia (B) até o OneFlow sincronizar; o pró-labore de 15% passa sem CF-08, porque a empresa não está sujeita ao Fator R. Fatos sem consumidor publicados com tarefa e exceção abertas não podem gravar nada (idempotência da reavaliação, §6 passo 2).",
                   ["§3", "§6", "§9.1", "§9.2", "§16"], R_ALL, [a, b], passos)


def c12() -> dict[str, Any]:
    t, u, w = "T014", "T015", "T016"
    M2 = "202608"  # 2º mês do trimestre: a apuração do LP não pede meses anteriores
    E04_SEQ = ["aguardando_insumo", "pronto", "processado", "divergente", "invalidado", "pronto", "processado", "validado"]

    def parcelas(*itens: tuple[str, str, int]) -> dict[str, Any]:
        return {"parcelas": [{"socio": s_, "tributo": tr, "valor_centavos": v} for s_, tr, v in itens]}

    def lp_apurado(x: str, saidas: list[dict[str, Any]]) -> list[dict[str, Any]]:
        return [fato(x, M2, "faturamento_mes", 20000000, fonte="intranet"), fato(x, M2, "notas_oneflow", 20000000, fonte="oneflow"),
                AGUARDAR, concluir(x, M2, "E03", saidas, ator="analista-c02")]

    passos = [
        abrir(M2, empresa(t, "LP", carteira="C-02"), empresa(u, "LP", carteira="C-02"), empresa(w, "LP", carteira="C-02")),
        *lp_apurado(t, [{"tributo": "IRPJ", "valor_centavos": 160000}, {"tributo": "CSLL", "valor_centavos": 144000},
                        {"tributo": "PIS", "valor_centavos": 13000}, {"tributo": "COFINS", "valor_centavos": 60000}]),
        *lp_apurado(u, [{"tributo": "IRPJ", "valor_centavos": 160000}]),
        *lp_apurado(w, [{"tributo": "IRPJ", "valor_centavos": 160000}]),
        fato(t, M2, "divisao_socios", None, fonte="calculo", descricao="PIS dividido com truncamento: 39 centavos a menos",
             payload=parcelas(("S1", "IRPJ", 96000), ("S2", "IRPJ", 64000), ("S1", "CSLL", 86400), ("S2", "CSLL", 57600),
                              ("S1", "PIS", 7780), ("S2", "PIS", 5181), ("S1", "COFINS", 36000), ("S2", "COFINS", 24000))),
        fato(u, M2, "divisao_socios", None, fonte="calculo", descricao="parcela de ISS sem apurado de ISS no caso",
             payload=parcelas(("S1", "IRPJ", 160000), ("S1", "ISS", 5000))),
        fato(w, M2, "divisao_socios", None, fonte="calculo", descricao="parcela sem tributo no Presumido",
             payload={"parcelas": [{"socio": "S1", "tributo": "IRPJ", "valor_centavos": 100000}, {"socio": "S2", "valor_centavos": 60000}]}),
        AGUARDAR,
        verificar("CF-07 tributo a tributo: o PIS bloqueia; tributo sem apurado e parcela sem tributo são dado ausente (S)", {
            "entregaveis": [ent(t, M2, "E03", "validado"), ent(t, M2, "E04", "divergente"),
                            ent(u, M2, "E04", "divergente"), ent(w, M2, "E04", "divergente")],
            "conferencias": [conf_seq(t, M2, "E04", "CF-07", ["divergente:B"], valores=[[13000, 12961]], classes=["S"], semantica=3),
                             conf(u, M2, "E04", "CF-07", "divergente"), conf_seq(u, M2, "E04", "CF-07", ["divergente:B"], classes=["S"]),
                             conf(w, M2, "E04", "CF-07", "divergente"), conf_seq(w, M2, "E04", "CF-07", ["divergente:B"], classes=["S"])],
            "excecoes": [exc(t, M2, "E04", "CF-07", ["aberta:"], "S"), exc(u, M2, "E04", "CF-07", ["aberta:"], "S"),
                         exc(w, M2, "E04", "CF-07", ["aberta:"], "S")]}),
        ruido(t, M2, "anotação com o E04 divergente: nada muda"),
        fato(t, M2, "divisao_socios", None, fonte="calculo", efeito="nova_versao",
             descricao="divisão refeita: PIS fecha; CSLL fica 5 centavos abaixo (alerta)",
             payload=parcelas(("S1", "IRPJ", 96000), ("S2", "IRPJ", 64000), ("S1", "CSLL", 86400), ("S2", "CSLL", 57595),
                              ("S1", "PIS", 7800), ("S2", "PIS", 5200), ("S1", "COFINS", 36000), ("S2", "COFINS", 24000))),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(t, M2, "E01", "validado", SEQ4), ent(t, M2, "E03", "validado", SEQ4), ent(t, M2, "E04", "validado", E04_SEQ),
                            ent(u, M2, "E04", "divergente"), ent(w, M2, "E04", "divergente")],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": M2, "tipo": x} for x in ["E02", "E05", "E06", "E07", "E08"]],
            "conferencias": [conf_seq(t, M2, "E04", "CF-07", ["divergente:B", "divergente:A"],
                                      valores=[[13000, 12961], [144000, 143995]], classes=["S", "S"], semantica=3),
                             conf_seq(t, M2, "E01", "CF-01", ["ok:B"])],
            "conferencias_total": 7,
            "excecoes": [exc(t, M2, "E04", "CF-07", ["resolvida:fato_alterado"], "S"), exc(u, M2, "E04", "CF-07", ["aberta:"], "S"),
                         exc(w, M2, "E04", "CF-07", ["aberta:"], "S")],
            "tarefas_abertas": [],
            "fatos": [versoes(t, M2, "apurado", 1, x) for x in ["IRPJ", "CSLL", "PIS", "COFINS"]] + [versoes(t, M2, "divisao_socios", 2)],
            "eventos": eventos(t, M2, conferencia__divergente=1, excecao__aberta=1, entregavel__invalidado=1, apuracao__concluida=1),
        }, final=True),
    ]
    return cenario("C12", 4, "M1: CF-07 do Presumido tributo a tributo",
                   "Três empresas do Lucro Presumido no 2º mês do trimestre. A primeira apura IRPJ, CSLL, PIS e COFINS; a divisão por sócio trunca o PIS e fica 39 centavos abaixo (caso real da amostra de 14/10): a CF-07 bloqueia e grava o par do PIS, o tributo de maior diferença. A divisão refeita fecha o PIS e deixa a CSLL 5 centavos abaixo: alerta, o E04 valida e a conferência grava o par da CSLL. A segunda tem parcela de ISS sem apurado de ISS no caso e a terceira tem parcela sem tributo: as duas são dado ausente (classe S) e bloqueiam. Um fato sem consumidor com o E04 divergente não grava nada.",
                   ["§9.1", "§9.2", "§9.3", "§16"], R_SEM_PARAM, [t, u, w], passos)


def c13() -> dict[str, Any]:
    t, u = "T017", "T018"
    E08_SEQ = ["aguardando_insumo", "pronto", "processado", "validado", "invalidado", "pronto", "processado", "divergente",
               "invalidado", "pronto", "processado", "validado"]
    folha_c = {"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 30000}
    passos = [
        abrir(C, empresa(t, "SN", folha=True, carteira="C-03"), empresa(u, "SN", folha=True, carteira="C-03")),
        concluir(t, C, "E06", [{"valor_centavos": 400000, "payload": folha_c}], ator="dp-c03"),
        concluir(u, C, "E06", [{"valor_centavos": 400000, "payload": folha_c}], ator="dp-c03"),
        fato(t, C, "guia", 88000, tributo="INSS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 32000, tributo="FGTS", payload=guia_payload(C), fonte="porta_documental"),
        fato(t, C, "guia", 25000, tributo="IRRF", payload=guia_payload(C), fonte="porta_documental",
             descricao="IRRF da DCTFWeb de setembro: é o retido na folha de agosto"),
        AGUARDAR,
        verificar("sem a folha de agosto o E08 não fica pronto", {
            "entregaveis": [ent(t, C, "E06", "validado"), ent(t, C, "E08", "aguardando_insumo")], "tarefas_abertas": []}),
        fato(t, ANT, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 25000}, fonte="dp",
             descricao="folha de agosto chega (competência sem caso): reavalia o E08 de setembro"),
        AGUARDAR,
        verificar("E08 confere INSS, FGTS e o IRRF de agosto", {
            "entregaveis": [ent(t, C, "E08", "validado")],
            "conferencias": [conf_seq(t, C, "E08", "CF-09", ["ok:B"], valores=[[88000, 88000]], semantica=3)]}),
        ruido(t, C, "anotação com o E08 validado: nada muda"),
        fato(t, ANT, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 26000}, fonte="dp",
             efeito="nova_versao", descricao="DP reabre a folha de agosto: IRRF sobe R$ 10,00"),
        AGUARDAR,
        verificar("folha do mês anterior reaberta invalida o E08 deste mês, que diverge no IRRF", {
            "entregaveis": [ent(t, C, "E08", "divergente")],
            "conferencias": [conf_seq(t, C, "E08", "CF-09", ["ok:B", "divergente:B"], valores=[[88000, 88000], [26000, 25000]])],
            "excecoes": [exc(t, C, "E08", "CF-09", ["aberta:"], "O")]}),
        fato(t, C, "guia", 26000, tributo="IRRF", payload=guia_payload(C), fonte="porta_documental", efeito="nova_versao",
             descricao="DCTFWeb retificada com o IRRF novo"),
        fato(u, C, "guia", 88000, tributo="INSS", payload=guia_payload(C), fonte="porta_documental"),
        fato(u, C, "guia", 32000, tributo="FGTS", payload=guia_payload(C), fonte="porta_documental"),
        fato(u, C, "guia", 0, tributo="IRRF", payload=guia_payload(C), fonte="porta_documental"),
        fato(u, ANT, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000}, fonte="dp",
             descricao="folha de agosto sem irrf_centavos: operando ausente"),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(t, C, "E06", "validado", SEQ4), ent(t, C, "E08", "validado", E08_SEQ),
                            ent(u, C, "E06", "validado", SEQ4), ent(u, C, "E08", "divergente", ["aguardando_insumo", "pronto", "processado", "divergente"])],
            "entregaveis_inexistentes": [{"titular_id": t, "competencia": C, "tipo": "E07"}],
            "conferencias": [conf_seq(t, C, "E08", "CF-09", ["ok:B", "divergente:B", "ok:B"],
                                      valores=[[88000, 88000], [26000, 25000], [88000, 88000]], classes=["O", "O", "O"], semantica=3),
                             conf_seq(u, C, "E08", "CF-09", ["divergente:B"], classes=["S"])],
            "conferencias_total": 4,
            "excecoes": [exc(t, C, "E08", "CF-09", ["resolvida:fato_alterado"], "O"), exc(u, C, "E08", "CF-09", ["aberta:"], "S")],
            "tarefas_abertas": [],
            "fatos": [versoes(t, ANT, "folha", 2), versoes(t, C, "folha", 1), versoes(t, C, "guia", 2, "IRRF")],
            "eventos": eventos(t, C, folha__fechada=1, guias__validadas=2, conferencia__divergente=1, excecao__aberta=1, entregavel__invalidado=2),
        }, final=True),
    ]
    return cenario("C13", 4, "M1: CF-09 com o IRRF da folha do mês anterior",
                   "Simples com folha. O IRRF retido na folha de agosto é declarado na DCTFWeb de setembro (amostra real de 14/10, 5 de 5 pares). O E08 de setembro só fica pronto quando chega a folha de agosto, uma competência sem caso aberto (entrada com competencia_relativa -1, §7.1). A folha de agosto reaberta pelo DP invalida o E08 de setembro, que diverge no IRRF até a DCTFWeb ser retificada. Na segunda empresa a folha de agosto vem sem irrf_centavos: dado ausente, classe S. Um fato sem consumidor com o E08 validado não grava nada.",
                   ["§6", "§7.1", "§9.1", "§11", "§16"], R_SEM_PARAM, [t, u], passos)


# ---------------------------------------------------------------------------
# Fase 4.5 · semântica v5 (manutenção M2): contrato de fato, hash v2, recebimento, concorrência
# ---------------------------------------------------------------------------
R_V5 = R_SEM_PARAM + ["contratos de tipo de fato v1 (V009)"]
F45 = 4.5


def rejeitado(tit: str, comp: str, tipo: str, valor: int | None, status: int, erro: str, violacoes: list[dict[str, str]] | None = None,
              tributo: str = "", payload: dict[str, Any] | None = None, fonte: str = "porta_documental", descricao: str = "") -> dict[str, Any]:
    p = fato(tit, comp, tipo, valor, tributo=tributo, payload=payload, fonte=fonte, descricao=descricao)
    p["espera"] = {"status": status, "erro": erro, "nada_gravado": True}
    if violacoes:
        p["espera"]["violacoes"] = violacoes
    return p


def recebs(tit: str, comp: str, tipo: str, tributo: str = "", **kw: Any) -> dict[str, Any]:
    return {"titular_id": tit, "competencia": comp, "tipo": tipo, "tributo": tributo} | kw


def vig(tit: str, comp: str, tipo: str, tributo: str = "", **kw: Any) -> dict[str, Any]:
    return {"titular_id": tit, "competencia": comp, "tipo": tipo, "tributo": tributo} | kw


def sn_ate_e11(t: str, guia_extra: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    """Simples sem folha até o E11 liberado (mesmo caminho do C4, sem divergência)."""
    return [
        abrir(C, empresa(t, "SN")),
        fato(t, C, "faturamento_mes", 5000000, fonte="intranet"),
        fato(t, C, "notas_oneflow", 5000000, fonte="oneflow"),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        AGUARDAR,
        concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 300000}]),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S1", "valor_centavos": 180000}, {"socio": "S2", "valor_centavos": 120000}]}, fonte="calculo"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": f"R-{t}"}, fonte="integra"),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload(C) | (guia_extra or {}), fonte="porta_documental"),
        AGUARDAR,
    ]


def c19() -> dict[str, Any]:
    t = "T019"
    E11_SEQ = E11_PROT[:-1] + ["invalidado", "pronto", "processado", "validado", "liberado", "disponibilizado"]
    passos = sn_ate_e11(t, {"documento_id": "doc-1", "extrator_versao": "x-1.0"}) + [
        verificar("E11 liberado", {"entregaveis": [ent(t, C, "E11", "liberado")]}),
        fato(t, C, "divisao_socios", None, payload={"parcelas": [{"socio": "S2", "valor_centavos": 120000}, {"socio": "S1", "valor_centavos": 180000}]},
             fonte="calculo", efeito="sem_mudanca", sem_efeito=True, descricao="mesmas parcelas em outra ordem (ordenar_arrays)"),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload(C) | {"documento_id": "doc-2", "extrator_versao": "x-1.1"},
             fonte="porta_documental", efeito="sem_mudanca", sem_efeito=True, descricao="mesma guia relida por outro extrator: rastro fora do hash"),
        fato(t, C, "recibo_obrigacao", None, tributo="PGDAS-D", payload={"numero_recibo": f"  R-{t}\t"}, fonte="integra",
             efeito="sem_mudanca", sem_efeito=True, descricao="espaço e tabulação nas pontas"),
        fato(t, C, "anotacao_interna", None, payload={"texto": "Revisão  feita\npor Ana"}, fonte="intranet", sem_efeito=True,
             descricao="anotação (nenhuma regra consome)"),
        fato(t, C, "anotacao_interna", None, payload={"texto": "Revisão feita por Ana "}, fonte="intranet", efeito="sem_mudanca",
             sem_efeito=True, descricao="mesmo texto em Unicode decomposto (NFC) e com espaços diferentes"),
        fato(t, C, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000}, fonte="dp", sem_efeito=True,
             descricao="folha sem irrf_centavos (empresa sem folha: ninguém consome)"),
        fato(t, C, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": None}, fonte="dp",
             efeito="sem_mudanca", sem_efeito=True, descricao="irrf_centavos: null é o mesmo que ausente"),
        fato(t, C, "guia", 300000, tributo="DAS", payload=guia_payload(C) | {"vencimento": "2026-10-21", "documento_id": "doc-3"},
             fonte="porta_documental", efeito="nova_versao", descricao="controle: vencimento está no hash, então é versão nova"),
        AGUARDAR,
        verificar("controle invalidou e revalidou o E11", {"entregaveis": [ent(t, C, "E11", "liberado")]}),
        fato(t, C, "documento_disponibilizado", None, tributo="DAS", payload={"canal": "app"}, fonte="entrega"),
        AGUARDAR,
        verificar("final", {
            "casos": [{"titular_id": t, "competencia": C, "estado": "encerrado"}],
            "entregaveis": [ent(t, C, x, "validado", SEQ4) for x in ["E01", "E02", "E03", "E04", "E10"]]
                           + [ent(t, C, "E11", "disponibilizado", E11_SEQ), ent(t, C, "E12", "encerrado", E12_ENC)],
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "divisao_socios", 1), versoes(t, C, "guia", 2, "DAS"), versoes(t, C, "recibo_obrigacao", 1, "PGDAS-D"),
                      versoes(t, C, "anotacao_interna", 1), versoes(t, C, "folha", 1)],
            "recebimentos": [recebs(t, C, "divisao_socios", efeitos=["novo", "sem_mudanca"]),
                             recebs(t, C, "guia", "DAS", efeitos=["novo", "sem_mudanca", "nova_versao"]),
                             recebs(t, C, "recibo_obrigacao", "PGDAS-D", efeitos=["novo", "sem_mudanca"]),
                             recebs(t, C, "anotacao_interna", efeitos=["novo", "sem_mudanca"]),
                             recebs(t, C, "folha", efeitos=["novo", "sem_mudanca"])],
            "vigentes": [vig(t, C, "guia", "DAS", versao=2, tipo_versao=1)],
            "eventos": eventos(t, C, entregavel__invalidado=1, documento__disponibilizado=1, fechamento__concluido=1),
        }, final=True),
    ]
    return cenario("C19", F45, "M2: hash normalizado",
                   "Simples sem folha. Republicações que só mudam o que não importa não viram versão: parcelas em outra ordem (ordenar_arrays), campos de rastro fora de campos_hash (documento_id, extrator_versao), espaços e tabulação nas pontas, Unicode decomposto, null igual a ausente. Nenhuma delas grava efeito nos casos. O controle (vencimento da guia, que está no hash) é versão nova e invalida o E11 liberado, que revalida e segue até o protocolo. Toda publicação deixa recebimento.",
                   ["§6.1", "§6.3", "§6.4", "§11.2"], R_V5, [t], passos)


def c20() -> dict[str, Any]:
    t = "T020"
    passos = [
        abrir(C, empresa(t, "SN", folha=True, carteira="C-02")),
        rejeitado(t, C, "guia", 88000, 422, "payload_invalido", [{"campo": "payload.competencia_impressa", "regra": "obrigatorio"}],
                  tributo="INSS", payload={"vencimento": "2026-10-20"}, descricao="guia sem competencia_impressa"),
        rejeitado(t, C, "guia", 88000, 422, "payload_invalido", [{"campo": "tributo", "regra": "fora_da_lista"}],
                  tributo="ISS", payload=guia_payload(C), descricao="tributo fora da lista do contrato"),
        rejeitado(t, C, "guia", 88000, 422, "payload_invalido", [{"campo": "tributo", "regra": "obrigatorio"}],
                  payload=guia_payload(C), descricao="guia sem tributo"),
        rejeitado(t, C, "faturamento_mes", 5000000, 422, "payload_invalido", [{"campo": "tributo", "regra": "proibido"}],
                  tributo="DAS", fonte="intranet", descricao="tipo sem qualificador recebe tributo"),
        rejeitado(t, C, "aliquota_mes", 100, 422, "payload_invalido", [{"campo": "valor_centavos", "regra": "proibido"}],
                  payload={"aliquota_bp": 600}, fonte="calculo", descricao="alíquota com valor_centavos"),
        rejeitado(t, C, "faturamento_mes", None, 422, "payload_invalido", [{"campo": "valor_centavos", "regra": "obrigatorio"}],
                  fonte="intranet", descricao="faturamento sem valor"),
        rejeitado(t, C, "folha", 400000, 422, "payload_invalido", [{"campo": "payload.inss_centavos", "regra": "obrigatorio"},
                                                                   {"campo": "payload.fgts_centavos", "regra": "obrigatorio"}],
                  payload={}, fonte="dp", descricao="v5.1: dois campos obrigatórios ausentes = duas violações"),
        rejeitado(t, C, "folha", 400000, 422, "payload_invalido", [{"campo": "payload.inss_centavos", "regra": "tipo"}],
                  payload={"inss_centavos": "88000", "fgts_centavos": 32000}, fonte="dp", descricao="número como texto"),
        rejeitado(t, C, "guia", 88000, 422, "payload_invalido", [{"campo": "payload.competencia_impressa", "regra": "esquema"}],
                  tributo="INSS", payload={"competencia_impressa": "09/2026"}, descricao="competência impressa fora do formato"),
        rejeitado(t, C, "boleto_avulso", 1000, 422, "tipo_desconhecido", fonte="intranet", descricao="tipo sem contrato ativo"),
        rejeitado(t, C, "faturamento_mes", 5000000, 403, "fonte_nao_autorizada", fonte="oneflow", descricao="fonte fora de fontes_permitidas"),
        {"acao": "concluir_entregavel", "titular_id": t, "competencia": C, "tipo": "E06",
         "descricao": "saída da tarefa fora do contrato (folha sem fgts_centavos): rejeita o pedido inteiro",
         "corpo": {"ator": "dp-c02", "saidas": [{"valor_centavos": 400000, "payload": {"inss_centavos": 88000}}]},
         "espera": {"status": 422, "erro": "payload_invalido", "nada_gravado": True,
                    "violacoes": [{"campo": "saidas[0].payload.fgts_centavos", "regra": "obrigatorio"}]}},
        AGUARDAR,
        verificar("nada gravado; a tarefa do E06 segue aberta", {
            "entregaveis": [ent(t, C, "E06", "pronto", ["aguardando_insumo", "pronto"])],
            "tarefas_abertas": [tarefa(t, C, "E06")],
            "fatos_total": [{"tipo": "guia", "tributo": "INSS", "quantidade": 0}, {"tipo": "folha", "quantidade": 0}],
            "recebimentos": [recebs(t, C, "guia", "INSS", total=0), recebs(t, C, "faturamento_mes", total=0)]}),
        fato(t, C, "guia", 88000, tributo="INSS", payload=guia_payload(C), fonte="porta_documental", descricao="a mesma guia, válida"),
        concluir(t, C, "E06", [{"valor_centavos": 400000, "payload": {"inss_centavos": 88000, "fgts_centavos": 32000}}], ator="dp-c02"),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(t, C, "E06", "validado", SEQ4)],
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "fatos": [versoes(t, C, "guia", 1, "INSS"), versoes(t, C, "folha", 1)],
            "recebimentos": [recebs(t, C, "guia", "INSS", efeitos=["novo"]), recebs(t, C, "folha", efeitos=["novo"])],
            "vigentes": [vig(t, C, "guia", "INSS", tipo_versao=1), vig(t, C, "folha", tipo_versao=1)],
        }, final=True),
    ]
    return cenario("C20", F45, "M2: contrato de fato na entrada",
                   "Publicações fora do contrato ativo do tipo são rejeitadas sem gravar nada (nem fato, nem recebimento, nem evento): campo obrigatório ausente, tributo fora da lista, tributo ausente ou proibido, valor_centavos proibido ou ausente, número como texto, formato fora do esquema, tipo sem contrato e fonte não autorizada. A saída de uma tarefa passa pela mesma validação e, se falha, a tarefa continua aberta. Depois, as versões válidas entram normalmente.",
                   ["§6.1", "§6.2", "§8"], R_V5, [t], passos)


def c21() -> dict[str, Any]:
    tits = [f"T021{x}" for x in "ABCDE"]
    folha_c = {"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 30000}

    def corpo(t: str, tipo: str, valor: int | None, minuto: int, tributo: str = "", payload: dict[str, Any] | None = None,
              fonte: str = "porta_documental", chave: str | None = None) -> dict[str, Any]:
        c = fato(t, C, tipo, valor, tributo=tributo, payload=payload, fonte=fonte,
                 observado_em=f"2026-10-01T10:{minuto:02d}:00-03:00", chave_idempotencia=chave)["corpo"]
        return c

    corpos: list[dict[str, Any]] = []
    for t in tits:
        gp = guia_payload(C)
        corpos += [
            corpo(t, "faturamento_mes", 5000000, 0, fonte="intranet"),
            corpo(t, "notas_oneflow", 4900000, 1, fonte="oneflow"),
            corpo(t, "notas_oneflow", 4950000, 2, fonte="oneflow"),
            corpo(t, "notas_oneflow", 5000000, 3, fonte="oneflow"),
            corpo(t, "aliquota_mes", None, 4, payload={"aliquota_bp": 600}, fonte="calculo"),
            corpo(t, "divisao_socios", None, 5, payload={"parcelas": [{"socio": "S1", "valor_centavos": 180000}, {"socio": "S2", "valor_centavos": 120000}]}, fonte="calculo"),
            corpo(t, "recibo_obrigacao", None, 6, tributo="PGDAS-D", payload={"numero_recibo": f"R-{t}"}, fonte="integra"),
            corpo(t, "guia", 80000, 7, tributo="INSS", payload=gp),
            corpo(t, "guia", 85000, 8, tributo="INSS", payload=gp),
            corpo(t, "guia", 88000, 9, tributo="INSS", payload=gp),
            corpo(t, "guia", 25000, 11, tributo="IRRF", payload=gp),
            corpo(t, "guia", 300000, 12, tributo="DAS", payload=gp),
        ] + [corpo(t, "guia", 32000, 10, tributo="FGTS", payload=gp, chave=f"fgts-{t}") for _ in range(5)]
        # folha do mês anterior (par IRRF da CF-09, v4)
        corpos.append(fato(t, ANT, "folha", 400000, payload={"inss_centavos": 88000, "fgts_centavos": 32000, "irrf_centavos": 25000},
                           fonte="dp", observado_em="2026-10-01T10:13:00-03:00")["corpo"])
    passos: list[dict[str, Any]] = [abrir(C, *[empresa(t, "SN", folha=True, carteira="C-04") for t in tits])]
    passos += [concluir(t, C, "E06", [{"valor_centavos": 400000, "payload": folha_c}], ator="dp-c04") for t in tits]
    passos += [
        {"acao": "publicar_paralelo", "descricao": f"{len(corpos)} publicações de 5 titulares, embaralhadas, 20 em paralelo",
         "semente": 20261027, "concorrencia": 20, "corpos": corpos,
         "espera": {"efeitos_aceitos": ["novo", "nova_versao", "sem_mudanca", "obsoleto"]}},
        AGUARDAR,
        # v5.1 (§6.5): a chave de idempotência é global por fonte. Dez titulares mandam, ao mesmo tempo, a mesma
        # chave: um só recebimento, as outras nove respostas repetidas, nenhum 500 no índice único.
    ] + [
        # oito rodadas, cada uma com uma chave nova: a corrida é probabilística, e repetir aumenta a chance de pegá-la
        {"acao": "publicar_paralelo", "descricao": f"rodada {n + 1}: a mesma chave_idempotencia de 10 titulares, ao mesmo tempo",
         "semente": 20261030 + n, "concorrencia": 10,
         "corpos": [fato(f"T021{x}", C, "anotacao_interna", None, payload={"texto": f"lote importado {n + 1}"}, fonte="intranet",
                         observado_em=f"2026-10-01T10:{14 + n:02d}:00-03:00", chave_idempotencia=f"lote-importado-{n + 1}")["corpo"]
                    for x in "ABCDEFGHIJ"],
         "espera": {"efeitos_aceitos": ["novo", "nova_versao"]}}  # da 2ª rodada em diante o titular vencedor já tem anotação
        for n in range(8)
    ] + [
        AGUARDAR,
    ]
    passos += [concluir(t, C, "E03", [{"tributo": "DAS", "valor_centavos": 300000}]) for t in tits]
    passos += [
        AGUARDAR,
        verificar("final: igual à execução em série, em todos os titulares", {
            "entregaveis": [ent(t, C, x, "validado") for t in tits for x in ["E01", "E02", "E03", "E04", "E06", "E08", "E10"]]
                           + [ent(t, C, "E11", "liberado") for t in tits] + [ent(t, C, "E12", "aguardando_insumo") for t in tits],
            "excecoes_abertas": 0,
            "tarefas_abertas": [],
            "vigentes": [v for t in tits for v in (vig(t, C, "notas_oneflow", valor_centavos=5000000), vig(t, C, "guia", "INSS", valor_centavos=88000))],
            "fatos": [versoes(t, C, "guia", 1, "FGTS") for t in tits],
            "recebimentos_por_chave": [{"fonte": "intranet", "chave": f"lote-importado-{n + 1}", "total": 1} for n in range(8)],
            "recebimentos": [r for t in tits for r in (recebs(t, C, "guia", "FGTS", contagem={"novo": 1}),
                                                        recebs(t, C, "notas_oneflow", total=3), recebs(t, C, "guia", "INSS", total=3))],
        }, final=True),
    ]
    return cenario("C21", F45, "M2: concorrência por titular",
                   "Cinco empresas do Simples com folha. Os insumos de cada uma chegam embaralhados e em paralelo (20 de cada vez), com três versões de notas e de guia de INSS observadas em instantes diferentes e cinco envios da mesma guia de FGTS com a mesma chave de idempotência. Em qualquer ordem de chegada, a vigente é a observação mais nova, a chave de idempotência grava um só recebimento e o estado final é o da execução em série. Rodar três vezes seguidas sem nenhuma falha.",
                   ["§6.4", "§6.5", "§13"], R_V5, tits, passos)


def c22() -> dict[str, Any]:
    t, p, q = "T022", "T022P", "T022Q"
    h10, h09 = "2026-10-01T10:00:00-03:00", "2026-10-01T09:00:00-03:00"
    passos = [
        abrir(C, empresa(t, "SN", carteira="C-05")),
        fato(t, C, "aliquota_mes", None, payload={"aliquota_bp": 600}, fonte="calculo"),
        fato(t, C, "notas_oneflow", 5000000, fonte="oneflow", observado_em=h10, descricao="notas observadas às 10h"),
        fato(t, C, "notas_oneflow", 4000000, fonte="oneflow", observado_em=h09, efeito="obsoleto", sem_efeito=True,
             descricao="leitura das 9h chega atrasada: não vira versão"),
        fato(t, C, "notas_oneflow", 4500000, fonte="oneflow", observado_em=h10, efeito="conflito_fonte",
             descricao="a mesma fonte diz outro valor no mesmo instante"),
        fato(t, C, "notas_oneflow", 4500000, fonte="oneflow", observado_em=h10, efeito="conflito_fonte",
             descricao="de novo: a exceção não duplica"),
        AGUARDAR,
        verificar("conflito vira exceção do caso, sem entregável", {
            "entregaveis": [ent(t, C, "E01", "aguardando_insumo")],
            "excecoes": [exc(t, C, None, "conflito_fonte", ["aberta:"], "S")],
            "vigentes": [vig(t, C, "notas_oneflow", versao=1, valor_centavos=5000000)]}),
        {"acao": "ativar_contrato", "arquivo": "notas_oneflow-v2-duas-fontes.json",
         "descricao": "contrato de teste: notas_oneflow aceita também a fonte oneflow_api"},
        fato(t, C, "notas_oneflow", 4800000, fonte="oneflow_api", observado_em=h10, efeito="nova_versao",
             descricao="outra fonte no mesmo instante: vale a regra normal"),
        fato(t, C, "faturamento_mes", 4800000, fonte="intranet"),
        # v5.1 (§6.4): observado_em truncado para microssegundo antes de comparar e de gravar. Titulares sem caso:
        # só o recebimento conta. Arredondando (como o Postgres), os dois pares dariam obsoleto.
        fato(p, C, "notas_oneflow", 100, fonte="oneflow", observado_em="2026-10-01T10:00:00.1234569-03:00",
             descricao="7 dígitos de fração: grava .123456"),
        fato(p, C, "notas_oneflow", 200, fonte="oneflow", observado_em="2026-10-01T10:00:00.123456-03:00", efeito="conflito_fonte",
             descricao="mesmo microssegundo depois de truncar: conflito, não obsoleto"),
        fato(q, C, "notas_oneflow", 100, fonte="oneflow", observado_em="2026-10-01T10:00:00.9999996-03:00",
             descricao="arredondar levaria para o segundo seguinte; truncar mantém .999999"),
        fato(q, C, "notas_oneflow", 200, fonte="oneflow", observado_em="2026-10-01T10:00:00.999999-03:00", efeito="conflito_fonte"),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(t, C, "E01", "validado", SEQ4), ent(t, C, "E02", "validado")],
            "excecoes": [exc(t, C, None, "conflito_fonte", ["aberta:"], "S")],
            "excecoes_total": 1,
            "fatos": [versoes(t, C, "notas_oneflow", 2)],
            "vigentes": [vig(t, C, "notas_oneflow", versao=2, valor_centavos=4800000, tipo_versao=2)],
            "recebimentos": [recebs(t, C, "notas_oneflow", efeitos=["novo", "obsoleto", "conflito_fonte", "conflito_fonte", "nova_versao"]),
                             recebs(p, C, "notas_oneflow", efeitos=["novo", "conflito_fonte"]),
                             recebs(q, C, "notas_oneflow", efeitos=["novo", "conflito_fonte"])],
            "eventos": eventos(t, C, excecao__aberta=1, entregavel__invalidado=0),
        }, final=True),
    ]
    return cenario("C22", F45, "M2: fato fora de ordem e conflito de fonte",
                   "Simples sem folha. A vigente de uma chave é a observação mais nova. Uma leitura antiga que chega atrasada fica só no recebimento (obsoleto) e não mexe em nada. A mesma fonte dizendo outro valor no mesmo instante é conflito_fonte: não vira versão e abre uma única exceção no caso, classe S. Outra fonte autorizada no mesmo instante segue a regra normal.",
                   ["§6.4", "§9.4"], R_V5 + ["contrato de teste notas_oneflow v2"], [t, p, q], passos)


def c23() -> dict[str, Any]:
    t = "T023"
    passos = [
        abrir(C, empresa(t, "SN", carteira="C-05")),
        {"acao": "ativar_contrato", "arquivo": "notas_oneflow-v2-com-quantidade.json",
         "descricao": "contrato de teste: quantidade_notas entra no hash"},
        fato(t, C, "notas_oneflow", 5000000, payload={"quantidade_notas": 10}, fonte="oneflow", sem_efeito=True),
        fato(t, C, "notas_oneflow", 5000000, payload={"quantidade_notas": 11}, fonte="oneflow", efeito="nova_versao", sem_efeito=True,
             descricao="com quantidade_notas no hash, mudar a quantidade é versão nova"),
        {"acao": "ativar_contrato", "arquivo": "notas_oneflow-v3-sem-quantidade.json",
         "descricao": "contrato v3: quantidade_notas sai do hash"},
        fato(t, C, "notas_oneflow", 5000000, payload={"quantidade_notas": 12}, fonte="oneflow", efeito="sem_mudanca", sem_efeito=True,
             descricao="sob o contrato ativo, a vigente é recalculada sem quantidade: nada mudou"),
        fato(t, C, "notas_oneflow", 5100000, payload={"quantidade_notas": 12}, fonte="oneflow", efeito="nova_versao", sem_efeito=True,
             descricao="valor diferente: versão nova, gravada com o contrato v3"),
        AGUARDAR,
        verificar("final", {
            "entregaveis": [ent(t, C, "E01", "aguardando_insumo", ["aguardando_insumo"])],
            "fatos": [versoes(t, C, "notas_oneflow", 3)],
            "vigentes": [vig(t, C, "notas_oneflow", versao=3, valor_centavos=5100000, tipo_versao=3)],
            "recebimentos": [recebs(t, C, "notas_oneflow", efeitos=["novo", "nova_versao", "sem_mudanca", "nova_versao"])],
            "excecoes_total": 0,
        }, final=True),
    ]
    return cenario("C23", F45, "M2: troca de campos_hash no contrato",
                   "A comparação de hash é sempre feita sob o contrato ativo: o motor recalcula o hash da vigente a partir do payload gravado. Tirar um campo do hash nunca gera versão nem cascata, e nenhum fato gravado é alterado. Fatos novos registram a versão do contrato em uso.",
                   ["§6.1", "§6.3", "§6.4"], R_V5 + ["contratos de teste notas_oneflow v2 e v3"], [t], passos)


def main() -> None:
    fabricas = {
        "c1-feliz-simples-com-folha.json": c1,
        "c2-r4-folha-reaberta-apos-entrega.json": c2,
        "c3-r1-nota-cancelada-das-protocolado.json": c3,
        "c4-r6-guia-retificada-duas-vezes.json": c4,
        "c5-r7-retroativo-competencia-encerrada.json": c5,
        "c6-escala-4500-casos.json": c6,
        "c7-lp-terceiro-mes-trimestre.json": c7,
        "c8-lp-terceiro-mes-ate-encerramento.json": c8,
        "c9-override-conferencia.json": c9,
        "c10-acompanhamento-apos-protocolo.json": c10,
        "c11-m1-tolerancia-e-fator-r.json": c11,
        "c12-m1-cf07-presumido-por-tributo.json": c12,
        "c13-m1-irrf-folha-mes-anterior.json": c13,
        "c19-m2-hash-normalizado.json": c19,
        "c20-m2-contrato-de-fato.json": c20,
        "c21-m2-concorrencia-por-titular.json": c21,
        "c22-m2-fora-de-ordem-e-conflito.json": c22,
        "c23-m2-troca-de-campos-hash.json": c23,
    }
    gerados: dict[str, dict[str, Any]] = {}
    for nome, fabrica in fabricas.items():
        gerados[nome] = fabrica()
    for nome, conteudo in gerados.items():
        (SAIDA / nome).write_text(json.dumps(conteudo, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
        print(f"{nome}: fase {conteudo['fase']}, {len(conteudo['passos'])} passos")


if __name__ == "__main__":
    main()
