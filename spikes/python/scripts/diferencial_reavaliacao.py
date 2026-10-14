"""Teste diferencial da reavaliação: recorte por sementes x percurso completo (§6 passo 2).

Roda a MESMA sequência aleatória de operações pela API (abrir, fatos coerentes e ruidosos,
concluir, override) em dois modos e compara transições, eventos, exceções, conferências, tarefas
e estados finais:

- completo: depois de cada comando, reavalia todos os entregáveis de todos os casos abertos do
  titular (o comportamento anterior ao recorte, sem ator nem sementes);
- recorte: o motor como está (`_reavaliar_titular`).

Também roda o modo completo duas vezes para separar divergência de não determinismo.

ATENÇÃO: faz TRUNCATE das tabelas de execução do banco de MOTOR_DB (como o verificador com
`--limpar`). Use um banco descartável.

    MOTOR_DB=postgresql://... python scripts/diferencial_reavaliacao.py [sequencias] [operacoes]
"""
from __future__ import annotations

import inspect
import os
import random
import sys
from typing import Any

import psycopg
from fastapi.testclient import TestClient

from motor.api.app import app
from motor.aplicacao import motor_fechamento as mf
from motor.aplicacao.portas import UnidadeDeTrabalho

DSN = os.environ["MOTOR_DB"]
TABELAS = (
    "eventos.evento", "conferencias.conferencia", "trabalho.excecao", "trabalho.tarefa_humana",
    "fechamento.entregavel_fato", "fechamento.entregavel_dependencia",
    "fechamento.entregavel_transicao", "fechamento.entregavel", "fechamento.caso_competencia",
    "fatos.fato",
)
COMPETENCIAS = ["202601", "202602", "202603"]  # 202603 = 3º mês (entradas com competência relativa)
SELECTS = (
    "SELECT k.titular_id, k.competencia, e.tipo, t.de_estado, t.para_estado, t.motivo, t.ator "
    "FROM fechamento.entregavel_transicao t JOIN fechamento.entregavel e ON e.id = t.entregavel_id "
    "JOIN fechamento.caso_competencia k ON k.id = t.caso_id ORDER BY 1, 2, t.em, 3",
    "SELECT k.titular_id, k.competencia, v.nome, coalesce(e.tipo, '-') FROM eventos.evento v "
    "LEFT JOIN fechamento.caso_competencia k ON k.id = v.caso_id "
    "LEFT JOIN fechamento.entregavel e ON e.id = v.entregavel_id ORDER BY 1, 2, v.ocorrido_em, 3",
    "SELECT k.titular_id, k.competencia, e.tipo, x.tipo, x.estado, x.resolucao "
    "FROM trabalho.excecao x JOIN fechamento.caso_competencia k ON k.id = x.caso_id "
    "LEFT JOIN fechamento.entregavel e ON e.id = x.entregavel_id ORDER BY 1, 2, x.aberta_em, 4",
    "SELECT k.titular_id, k.competencia, e.tipo, e.estado, k.estado "
    "FROM fechamento.entregavel e JOIN fechamento.caso_competencia k ON k.id = e.caso_id "
    "ORDER BY 1, 2, 3",
    "SELECT k.titular_id, k.competencia, e.tipo, f.cf, f.resultado, f.severidade, f.classe "
    "FROM conferencias.conferencia f JOIN fechamento.entregavel e ON e.id = f.entregavel_id "
    "JOIN fechamento.caso_competencia k ON k.id = f.caso_id ORDER BY 1, 2, f.em, 4",
    "SELECT k.titular_id, k.competencia, e.tipo, t.estado FROM trabalho.tarefa_humana t "
    "JOIN fechamento.entregavel e ON e.id = t.entregavel_id "
    "JOIN fechamento.caso_competencia k ON k.id = t.caso_id ORDER BY 1, 2, 3, 4",
)


def limpar() -> None:
    with psycopg.connect(DSN, autocommit=True) as c:
        for t in TABELAS:
            c.execute(f"TRUNCATE {t} CASCADE")  # type: ignore[call-overload]


def snapshot() -> list[tuple[str, ...]]:
    linhas: list[tuple[str, ...]] = []
    with psycopg.connect(DSN) as c:
        for sql in SELECTS:
            linhas += [tuple(map(str, r)) for r in c.execute(sql).fetchall()]  # type: ignore[call-overload]
    return linhas


def fato(rng: random.Random, regime: str) -> dict[str, Any]:
    """Fato coerente com o regime (a maioria) ou ruidoso, em competência aleatória."""
    comp = rng.choice(COMPETENCIAS)
    v = rng.choice([100, 200, 100, 100])
    a = v // 10
    trib = "DAS" if regime == "SN" else "IRPJ"
    if rng.random() < 0.8:
        recibo = "PGDAS-D" if regime == "SN" else "DCTFWEB"
        imp = comp if rng.random() < 0.85 else "209901"
        opcoes: list[tuple[str, str, int | None, dict[str, Any]]] = [
            ("faturamento_mes", "", v, {}), ("notas_oneflow", "", v, {}),
            ("aliquota_mes", "", None, {"aliquota_bp": 1000}), ("apurado", trib, a, {}),
            ("divisao_socios", "", None, {"parcelas": [{"valor_centavos": a}]}),
            ("taxa_municipal", "", v, {}), ("guia", trib, a, {"competencia_impressa": imp}),
            ("guia", "INSS", a, {}), ("guia", "FGTS", a, {}), ("recibo_obrigacao", recibo, v, {}),
            ("folha", "", None, {"inss_centavos": a, "fgts_centavos": a}),
            ("prolabore", "", v // 10, {}),
        ]
    else:
        opcoes = [
            ("guia", rng.choice(["DAS", "IRPJ", "INSS"]), v, {"competencia_impressa": comp}),
            ("apurado", rng.choice(["DAS", "IRPJ"]), v, {}),
            ("recibo_obrigacao", rng.choice(["PGDAS-D", "DCTFWEB"]), v, {}),
            ("aliquota_mes", "", None, {"aliquota_bp": rng.choice([1000, 2000])}),
        ]
    if rng.random() < 0.2:  # protocolo e acompanhamento (§10.3)
        opcoes = [(t, trib, None, {"n": rng.choice([1, 2])}) for t in (
            "documento_disponibilizado", "entrega_falhou", "entrega_confirmada",
            "recebimento_pendente",
        )]
    tipo, tributo, valor, payload = rng.choice(opcoes)
    return {"titular_id": "TX", "competencia": comp, "tipo": tipo, "tributo": tributo,
            "valor_centavos": valor, "payload": payload, "fonte": "dif",
            "observado_em": "2026-10-01T00:00:00Z"}


def roteiro(rng: random.Random, empresa: dict[str, Any]) -> list[tuple[str, dict[str, Any]]]:
    """Caminho feliz do 3º mês (202603) até o protocolo, com fatos em ordem embaralhada."""
    sn = empresa["regime"] == "SN"
    trib = "DAS" if sn else "IRPJ"
    fat, apurado, folha = 100000, 10000, 5000

    def f(tipo: str, tributo: str = "", valor: int | None = None, comp: str = "202603",
          payload: dict[str, Any] | None = None) -> tuple[str, dict[str, Any]]:
        return "/fatos", {"titular_id": "TX", "competencia": comp, "tipo": tipo,
                          "tributo": tributo, "valor_centavos": valor, "payload": payload or {},
                          "fonte": "dif", "observado_em": "2026-10-01T00:00:00Z"}

    fatos = [
        f("faturamento_mes", valor=fat), f("notas_oneflow", valor=fat),
        f("divisao_socios", payload={"parcelas": [{"valor_centavos": apurado}]}),
        f("recibo_obrigacao", "PGDAS-D" if sn else "DCTFWEB"),
        f("guia", trib, apurado, payload={"competencia_impressa": "202603"}),
    ]
    if sn:
        fatos.append(f("aliquota_mes", payload={"aliquota_bp": 1000}))
    else:  # LP no 3º mês: E03 exige o apurado dos dois meses anteriores
        fatos += [f("apurado", "IRPJ", apurado, "202601"), f("apurado", "IRPJ", apurado, "202602")]
    if empresa["tem_taxa_municipal"]:
        fatos.append(f("taxa_municipal", valor=1))
    if empresa["tem_folha"] or empresa["tem_prolabore"]:
        fatos.append(f("guia", "INSS", folha))
    if empresa["tem_folha"]:
        fatos.append(f("guia", "FGTS", folha))
    rng.shuffle(fatos)

    def c(tipo: str, saida: dict[str, Any]) -> tuple[str, dict[str, Any]]:
        return (f"/casos/TX/202603/entregaveis/{tipo}/concluir",
                {"ator": "dif", "saidas": [saida]})

    passos = [("/competencias/abrir", {"competencia": "202603", "ator": "dif",
                                       "empresas": [empresa]})] + fatos
    passos.append(c("E03", {"tributo": trib, "valor_centavos": apurado}))
    if empresa["tem_folha"]:
        passos.append(c("E06", {"payload": {"inss_centavos": folha, "fgts_centavos": folha}}))
    if empresa["tem_prolabore"]:
        passos.append(c("E07", {"valor_centavos": 28000}))
    passos.append(f("documento_disponibilizado", trib, payload={"canal": "dif"}))
    return passos


def sequencia(seed: int, operacoes: int) -> list[int]:
    rng = random.Random(seed)
    regime = rng.choice(["SN", "LP"])
    empresa = {"titular_id": "TX", "regime": regime, "tem_folha": rng.random() < 0.7,
               "tem_prolabore": rng.random() < 0.7, "tem_taxa_municipal": rng.random() < 0.5,
               "filial": False, "carteira": "C-01", "municipio": "1"}
    trib = "DAS" if regime == "SN" else "IRPJ"
    status: list[int] = []
    with TestClient(app) as cl:
        if seed % 3 != 0:  # 2 de 3 sequências começam por um caminho feliz até o protocolo
            for caminho, corpo in roteiro(rng, empresa):
                status.append(cl.post(caminho, json=corpo).status_code)
        for _ in range(operacoes):
            r, comp = rng.random(), rng.choice(COMPETENCIAS)
            if r < 0.10:
                resp = cl.post("/competencias/abrir", json={
                    "competencia": comp, "ator": "dif", "empresas": [empresa]})
            elif r < 0.72:
                resp = cl.post("/fatos", json=fato(rng, regime))
            elif r < 0.90:
                tipo = rng.choice(["E03", "E06", "E07"])
                saida = {
                    "E03": {"tributo": trib, "valor_centavos": rng.choice([10, 20])},
                    "E06": {"payload": {"inss_centavos": 10, "fgts_centavos": 10}},
                    "E07": {"valor_centavos": rng.choice([10, 20])},
                }[tipo]
                resp = cl.post(f"/casos/TX/{comp}/entregaveis/{tipo}/concluir",
                               json={"ator": "dif", "saidas": [saida]})
            else:
                tipo = rng.choice(["E01", "E03", "E04", "E07", "E08", "E10", "E11"])
                cf = rng.choice(["CF-01", "CF-04", "CF-05", "CF-06", "CF-07", "CF-08", "CF-09",
                                 "CF-12"])
                resp = cl.post(f"/casos/TX/{comp}/entregaveis/{tipo}/override",
                               json={"ator": "dif", "cf": cf, "motivo": "motivo valido"})
            status.append(resp.status_code)
    return status


def completo(uow: UnidadeDeTrabalho, titular_id: str, _pend: object) -> None:
    """Comportamento anterior ao recorte: todos os entregáveis de todos os casos abertos."""
    for caso in uow.casos_abertos_do_titular(titular_id):
        regra = mf._regra_do_caso(uow, caso)
        if "ator" in inspect.signature(mf.avaliar_caso).parameters:
            mf.avaliar_caso(uow, caso, regra, ator="sistema")  # type: ignore[call-arg]
        else:
            mf.avaliar_caso(uow, caso, regra)


def rodar(modo: Any, seed: int, operacoes: int) -> tuple[list[int], list[tuple[str, ...]]]:
    mf._reavaliar_titular = modo
    limpar()
    return sequencia(seed, operacoes), snapshot()


def main() -> int:
    n = int(sys.argv[1]) if len(sys.argv) > 1 else 20
    operacoes = int(sys.argv[2]) if len(sys.argv) > 2 else 100
    recorte = mf._reavaliar_titular
    ruins = 0
    cobertura: dict[str, int] = {}
    for seed in range(n):
        a = rodar(completo, seed, operacoes)
        b = rodar(recorte, seed, operacoes)
        c = rodar(completo, seed, operacoes)
        for linha in b[1]:
            if len(linha) == 7:  # transição: (titular, competência, tipo, de, para, motivo, ator)
                cobertura[linha[4]] = cobertura.get(linha[4], 0) + 1
        if a != c:
            print(f"seed {seed}: NAO DETERMINISTICO (completo x completo)")
            ruins += 1
        if a != b:
            print(f"seed {seed}: DIVERGE (recorte x completo): "
                  f"{len(a[1])} x {len(b[1])} linhas; status iguais: {a[0] == b[0]}")
            ruins += 1
    print(f"transicoes por estado de destino (modo recorte): {dict(sorted(cobertura.items()))}")
    print(f"{n} sequencias x {operacoes} operacoes: {ruins} problema(s)")
    return 1 if ruins else 0


if __name__ == "__main__":
    sys.exit(main())
