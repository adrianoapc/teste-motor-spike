#!/usr/bin/env python3
"""Verificador comum do spike do motor do fechamento.

Roda os cenários (cenarios/*.json) contra a API de uma spike e confere o banco.
É o mesmo juiz para .NET e Python. Nenhuma spike altera este arquivo.

Uso:
  python verificador.py --api http://localhost:8081 \
      --db postgresql://spike:spike@localhost:5432/spike_dotnet \
      --cenario ../cenarios/c1-feliz-simples-com-folha.json --limpar

  python verificador.py --api ... --db ... --todos ../cenarios --fase 2 --limpar --relatorio saida.json

--fase N roda os cenários das fases 1..N (regressão incluída). Sem --fase, roda todos.

Saída: relatório legível no terminal e, com --relatorio, JSON. Código 0 se tudo passou.
"""
from __future__ import annotations

import argparse
import importlib.util
import json
import statistics
import sys
import threading
import time
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import httpx
import psycopg
from psycopg.rows import dict_row

TABELAS_EXECUCAO = [
    "eventos.entrega_evento", "eventos.evento", "eventos.fila",
    "trabalho.tarefa_humana", "trabalho.excecao",
    "conferencias.conferencia",
    "fechamento.entregavel_fato", "fechamento.entregavel_dependencia",
    "fechamento.entregavel_transicao", "fechamento.entregavel", "fechamento.caso_competencia",
    "fatos.fato",
]

CONSULTAS_INDICADORES = {
    "I1": "SELECT * FROM indicadores.i1_entregaveis_por_estado",
    "I2": "SELECT * FROM indicadores.i2_tempo_em_divergente",
    "I3": "SELECT * FROM indicadores.i3_excecoes_por_tipo",
    "I4": "SELECT * FROM indicadores.i4_acerto_de_primeira",
    "I5": "SELECT * FROM indicadores.i5_entregaveis_travados WHERE dias_parado >= 0",
    "I6": "SELECT * FROM indicadores.i6_protocolo_e_recebimento",
}
LIMITE_INDICADOR_S = 2.0


@dataclass
class Resultado:
    cenario: str
    falhas: list[str] = field(default_factory=list)
    checagens: int = 0
    passos: list[dict[str, Any]] = field(default_factory=list)
    indicadores: dict[str, dict[str, Any]] = field(default_factory=dict)

    def checar(self, condicao: bool, mensagem: str) -> None:
        self.checagens += 1
        if not condicao:
            self.falhas.append(mensagem)

    @property
    def passou(self) -> bool:
        return not self.falhas


# ---------------------------------------------------------------------------
# Banco
# ---------------------------------------------------------------------------
def limpar(conn: psycopg.Connection) -> None:
    with conn.cursor() as cur:
        cur.execute("TRUNCATE " + ", ".join(TABELAS_EXECUCAO) + " RESTART IDENTITY CASCADE")
    conn.commit()


def caso_id(conn: psycopg.Connection, titular: str, comp: str) -> str | None:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT id::text FROM fechamento.caso_competencia WHERE titular_id = %s AND competencia = %s",
            (titular, comp),
        )
        row = cur.fetchone()
    return row[0] if row else None


def verificar_esperado(conn: psycopg.Connection, esp: dict[str, Any], titulares: list[str],
                       r: Resultado, rotulo: str) -> None:
    cur = conn.cursor(row_factory=dict_row)

    for c in esp.get("casos", []):
        cur.execute(
            """SELECT c.estado, v.versao AS regra_versao FROM fechamento.caso_competencia c
               LEFT JOIN regras.regra_versao v ON v.id = c.regra_entregaveis_versao_id
               WHERE c.titular_id = %s AND c.competencia = %s""",
            (c["titular_id"], c["competencia"]),
        )
        row = cur.fetchone()
        r.checar(row is not None, f"[{rotulo}] caso {c['titular_id']}/{c['competencia']} não existe")
        if row:
            r.checar(row["estado"] == c["estado"],
                     f"[{rotulo}] caso {c['titular_id']}/{c['competencia']}: estado {row['estado']!r}, esperado {c['estado']!r}")
            if "regra_entregaveis_versao" in c:
                r.checar(row["regra_versao"] == c["regra_entregaveis_versao"],
                         f"[{rotulo}] caso {c['titular_id']}/{c['competencia']}: usa fechamento.entregaveis v{row['regra_versao']}, esperado v{c['regra_entregaveis_versao']}")

    for e in esp.get("entregaveis", []):
        cur.execute(
            """SELECT e.id, e.estado FROM fechamento.entregavel e
               JOIN fechamento.caso_competencia c ON c.id = e.caso_id
               WHERE c.titular_id = %s AND c.competencia = %s AND e.tipo = %s""",
            (e["titular_id"], e["competencia"], e["tipo"]),
        )
        row = cur.fetchone()
        ident = f"{e['titular_id']}/{e['competencia']}/{e['tipo']}"
        r.checar(row is not None, f"[{rotulo}] entregável {ident} não existe")
        if not row:
            continue
        r.checar(row["estado"] == e["estado"],
                 f"[{rotulo}] entregável {ident}: estado {row['estado']!r}, esperado {e['estado']!r}")
        if "transicoes" in e:
            cur.execute(
                "SELECT para_estado FROM fechamento.entregavel_transicao WHERE entregavel_id = %s ORDER BY id",
                (row["id"],),
            )
            seq = [x["para_estado"] for x in cur.fetchall()]
            r.checar(seq == e["transicoes"],
                     f"[{rotulo}] entregável {ident}: transições {seq}, esperado {e['transicoes']}")

    for e in esp.get("entregaveis_inexistentes", []):
        cur.execute(
            """SELECT 1 FROM fechamento.entregavel e
               JOIN fechamento.caso_competencia c ON c.id = e.caso_id
               WHERE c.titular_id = %s AND c.competencia = %s AND e.tipo = %s""",
            (e["titular_id"], e["competencia"], e["tipo"]),
        )
        r.checar(cur.fetchone() is None,
                 f"[{rotulo}] entregável {e['titular_id']}/{e['competencia']}/{e['tipo']} não deveria existir")

    for cf in esp.get("conferencias", []):
        cur.execute(
            """SELECT cf.resultado, cf.severidade, cf.override_autor FROM conferencias.conferencia cf
               JOIN fechamento.entregavel e ON e.id = cf.entregavel_id
               JOIN fechamento.caso_competencia c ON c.id = e.caso_id
               WHERE c.titular_id = %s AND c.competencia = %s AND e.tipo = %s AND cf.cf = %s
               ORDER BY cf.em, cf.id""",
            (cf["titular_id"], cf["competencia"], cf["tipo"], cf["cf"]),
        )
        rows = cur.fetchall()
        ident = f"{cf['titular_id']}/{cf['competencia']}/{cf['tipo']}/{cf['cf']}"
        if "resultados" in cf:
            obtido = [f"{x['resultado']}:{x['severidade']}" for x in rows]
            r.checar(obtido == cf["resultados"],
                     f"[{rotulo}] conferência {ident}: sequência {obtido}, esperado {cf['resultados']}")
        else:
            r.checar(len(rows) == cf.get("quantidade", 1),
                     f"[{rotulo}] conferência {ident}: {len(rows)} registro(s), esperado {cf.get('quantidade', 1)}")
            if rows:
                r.checar(rows[-1]["resultado"] == cf["resultado"],
                         f"[{rotulo}] conferência {ident}: resultado {rows[-1]['resultado']!r}, esperado {cf['resultado']!r}")
        if "override" in cf and rows:
            com_override = [i for i, x in enumerate(rows) if x["override_autor"]]
            r.checar(com_override == cf["override"],
                     f"[{rotulo}] conferência {ident}: override nas posições {com_override}, esperado {cf['override']}")

    if "conferencias_total" in esp:
        cur.execute(
            """SELECT count(*) AS n FROM conferencias.conferencia cf
               JOIN fechamento.caso_competencia c ON c.id = cf.caso_id
               WHERE c.titular_id = ANY(%s)""",
            (titulares,),
        )
        n = cur.fetchone()["n"]
        r.checar(n == esp["conferencias_total"],
                 f"[{rotulo}] total de conferências {n}, esperado {esp['conferencias_total']}")

    if "excecoes_abertas" in esp:
        cur.execute(
            """SELECT count(*) AS n FROM trabalho.excecao x
               JOIN fechamento.caso_competencia c ON c.id = x.caso_id
               WHERE c.titular_id = ANY(%s) AND x.estado = 'aberta'""",
            (titulares,),
        )
        n = cur.fetchone()["n"]
        r.checar(n == esp["excecoes_abertas"],
                 f"[{rotulo}] exceções abertas {n}, esperado {esp['excecoes_abertas']}")

    for x in esp.get("excecoes", []):
        tipo_ent = x.get("entregavel")
        cur.execute(
            """SELECT x.estado, x.resolucao, x.classe FROM trabalho.excecao x
               JOIN fechamento.caso_competencia c ON c.id = x.caso_id
               LEFT JOIN fechamento.entregavel e ON e.id = x.entregavel_id
               WHERE c.titular_id = %s AND c.competencia = %s AND x.tipo = %s
                 AND (%s::text IS NULL AND x.entregavel_id IS NULL OR e.tipo = %s)
               ORDER BY x.aberta_em, x.id""",
            (x["titular_id"], x["competencia"], x["tipo"], tipo_ent, tipo_ent),
        )
        rows = cur.fetchall()
        obtido = [f"{y['estado']}:{y['resolucao'] or ''}" for y in rows]
        ident = f"{x['titular_id']}/{x['competencia']}/{tipo_ent or '-'}/{x['tipo']}"
        r.checar(obtido == x["estados"], f"[{rotulo}] exceções {ident}: {obtido}, esperado {x['estados']}")
        if "classe" in x and rows:
            r.checar(all(y["classe"] == x["classe"] for y in rows),
                     f"[{rotulo}] exceções {ident}: classe {[y['classe'] for y in rows]}, esperado {x['classe']}")

    if "excecoes_total" in esp:
        cur.execute(
            """SELECT count(*) AS n FROM trabalho.excecao x
               JOIN fechamento.caso_competencia c ON c.id = x.caso_id WHERE c.titular_id = ANY(%s)""",
            (titulares,),
        )
        n = cur.fetchone()["n"]
        r.checar(n == esp["excecoes_total"], f"[{rotulo}] total de exceções {n}, esperado {esp['excecoes_total']}")

    if "casos_total" in esp:
        cur.execute("SELECT count(*) AS n FROM fechamento.caso_competencia")
        n = cur.fetchone()["n"]
        r.checar(n == esp["casos_total"], f"[{rotulo}] total de casos {n}, esperado {esp['casos_total']}")

    if "entregaveis_total" in esp:
        cur.execute("SELECT count(*) AS n FROM fechamento.entregavel")
        n = cur.fetchone()["n"]
        r.checar(n == esp["entregaveis_total"], f"[{rotulo}] total de entregáveis {n}, esperado {esp['entregaveis_total']}")

    for ft in esp.get("fatos_total", []):
        cur.execute("SELECT count(*) AS n FROM fatos.fato WHERE tipo = %s AND tributo = %s", (ft["tipo"], ft.get("tributo", "")))
        n = cur.fetchone()["n"]
        r.checar(n == ft["quantidade"], f"[{rotulo}] fatos {ft['tipo']}[{ft.get('tributo', '')}]: {n}, esperado {ft['quantidade']}")

    if "tarefas_abertas" in esp:
        cur.execute(
            """SELECT c.titular_id, c.competencia, e.tipo
               FROM trabalho.tarefa_humana t
               JOIN fechamento.entregavel e ON e.id = t.entregavel_id
               JOIN fechamento.caso_competencia c ON c.id = e.caso_id
               WHERE c.titular_id = ANY(%s) AND t.estado = 'aberta'""",
            (titulares,),
        )
        obtido = sorted((x["titular_id"], x["competencia"].strip(), x["tipo"]) for x in cur.fetchall())
        esperado = sorted((t["titular_id"], t["competencia"], t["tipo"]) for t in esp["tarefas_abertas"])
        r.checar(obtido == esperado, f"[{rotulo}] tarefas abertas {obtido}, esperado {esperado}")

    for f in esp.get("fatos", []):
        cur.execute(
            """SELECT count(*) AS n FROM fatos.fato
               WHERE titular_id = %s AND competencia = %s AND tipo = %s AND tributo = %s""",
            (f["titular_id"], f["competencia"], f["tipo"], f.get("tributo", "")),
        )
        n = cur.fetchone()["n"]
        ident = f"{f['titular_id']}/{f['competencia']}/{f['tipo']}[{f.get('tributo', '')}]"
        r.checar(n == f["versoes"], f"[{rotulo}] fato {ident}: {n} versão(ões), esperado {f['versoes']}")

    for ev in esp.get("eventos", []):
        cid = caso_id(conn, ev["titular_id"], ev["competencia"])
        cur.execute(
            "SELECT count(*) AS n FROM eventos.evento WHERE caso_id = %s AND nome = %s",
            (cid, ev["nome"]),
        )
        n = cur.fetchone()["n"]
        r.checar(n == ev["quantidade"],
                 f"[{rotulo}] evento {ev['nome']} em {ev['titular_id']}/{ev['competencia']}: {n}, esperado {ev['quantidade']}")
    cur.close()


def verificar_invariantes(conn: psycopg.Connection, r: Resultado) -> None:
    """Invariantes que valem para qualquer cenário (semântica v3 §4.1, §6, §9.2, §10, §12)."""
    cur = conn.cursor(row_factory=dict_row)

    # INV-1: a última transição de cada entregável bate com o estado atual
    cur.execute("""
        SELECT e.id, e.tipo, e.estado, t.para_estado
        FROM fechamento.entregavel e
        LEFT JOIN LATERAL (
            SELECT para_estado FROM fechamento.entregavel_transicao
            WHERE entregavel_id = e.id ORDER BY id DESC LIMIT 1) t ON true
        WHERE t.para_estado IS DISTINCT FROM e.estado""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-1] entregável {x['id']} ({x['tipo']}): estado {x['estado']!r}, última transição {x['para_estado']!r}")
    r.checagens += 1

    # INV-2: toda transição está na lista de permitidas
    cur.execute("""
        SELECT t.id, t.de_estado, t.para_estado
        FROM fechamento.entregavel_transicao t
        WHERE NOT EXISTS (
            SELECT 1 FROM fechamento.transicao_permitida p
            WHERE p.de_estado IS NOT DISTINCT FROM t.de_estado AND p.para_estado = t.para_estado)""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-2] transição {x['id']} não permitida: {x['de_estado']} → {x['para_estado']}")
    r.checagens += 1

    # INV-3: as transições de cada entregável formam uma cadeia (de = para anterior; primeira de = NULL)
    cur.execute("""
        SELECT entregavel_id, id, de_estado,
               lag(para_estado) OVER (PARTITION BY entregavel_id ORDER BY id) AS anterior
        FROM fechamento.entregavel_transicao""")
    for x in cur.fetchall():
        if x["de_estado"] != x["anterior"]:
            r.checar(False, f"[INV-3] transição {x['id']}: de_estado {x['de_estado']!r}, anterior {x['anterior']!r}")
    r.checagens += 1

    # INV-4: versões de fato contíguas 1..n por chave, e hashes consecutivos diferentes
    cur.execute("""
        SELECT titular_id, competencia, tipo, tributo, array_agg(versao ORDER BY versao) AS versoes,
               array_agg(hash ORDER BY versao) AS hashes
        FROM fatos.fato GROUP BY 1, 2, 3, 4""")
    for x in cur.fetchall():
        ok_seq = x["versoes"] == list(range(1, len(x["versoes"]) + 1))
        ok_hash = all(a != b for a, b in zip(x["hashes"], x["hashes"][1:]))
        r.checar(ok_seq and ok_hash,
                 f"[INV-4] fato {x['titular_id']}/{x['competencia']}/{x['tipo']}[{x['tributo']}]: versões {x['versoes']}, hashes repetidos={not ok_hash}")

    # INV-5: entregável que chegou a 'validado' publicou o evento do seu tipo, se houver
    cur.execute("""
        SELECT e.id, e.tipo, tp.evento_publicado
        FROM fechamento.entregavel e
        JOIN fechamento.entregavel_tipo tp ON tp.chave = e.tipo
        WHERE tp.evento_publicado IS NOT NULL
          AND EXISTS (SELECT 1 FROM fechamento.entregavel_transicao t
                      WHERE t.entregavel_id = e.id AND t.para_estado = 'validado')
          AND NOT EXISTS (SELECT 1 FROM eventos.evento ev
                          WHERE ev.entregavel_id = e.id AND ev.nome = tp.evento_publicado)""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-5] entregável {x['id']} ({x['tipo']}) validado sem evento {x['evento_publicado']}")
    r.checagens += 1

    # INV-6: transição grava a versão da regra do caso
    cur.execute("""
        SELECT t.id FROM fechamento.entregavel_transicao t
        JOIN fechamento.caso_competencia c ON c.id = t.caso_id
        WHERE t.regra_versao_id IS DISTINCT FROM c.regra_entregaveis_versao_id""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-6] transição {x['id']} sem a versão de regra do caso")
    r.checagens += 1

    # INV-7: payload de evento não carrega valor nem identificador de pessoa
    cur.execute("""
        SELECT id, nome, payload FROM eventos.evento
        WHERE EXISTS (SELECT 1 FROM jsonb_object_keys(payload) k
                      WHERE k NOT IN ('caso_id','entregavel_id','fato_id','conferencia_id','excecao_id'))""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-7] evento {x['nome']} ({x['id']}) com chave de payload não permitida: {list(x['payload'])}")
    r.checagens += 1

    # INV-8: conferência registra a versão de 'fiscal.tolerancia'
    cur.execute("""
        SELECT cf.id FROM conferencias.conferencia cf
        WHERE NOT EXISTS (SELECT 1 FROM regras.regra_versao v
                          WHERE v.id = cf.regra_versao_id AND v.regra_chave = 'fiscal.tolerancia')""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-8] conferência {x['id']} sem versão de fiscal.tolerancia")
    r.checagens += 1

    # INV-9: toda conferência divergente B tem exceção do mesmo tipo no caso
    cur.execute("""
        SELECT cf.id, cf.cf FROM conferencias.conferencia cf
        WHERE cf.resultado = 'divergente' AND cf.severidade = 'B' AND cf.override_autor IS NULL
          AND NOT EXISTS (SELECT 1 FROM trabalho.excecao x
                          WHERE x.entregavel_id = cf.entregavel_id AND x.tipo = cf.cf)""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-9] conferência divergente {x['id']} ({x['cf']}) sem exceção")
    r.checagens += 1

    # INV-10: no máximo uma exceção aberta por (entregável, tipo), ou por (caso, tipo) sem entregável (§9.4)
    cur.execute("""
        SELECT caso_id, entregavel_id, tipo, count(*) AS n FROM trabalho.excecao
        WHERE estado = 'aberta' GROUP BY caso_id, entregavel_id, tipo HAVING count(*) > 1""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-10] {x['n']} exceções abertas do tipo {x['tipo']} no entregável {x['entregavel_id']} (caso {x['caso_id']})")
    r.checagens += 1

    # INV-11: no máximo uma tarefa aberta por entregável, e só em entregável 'pronto'
    cur.execute("""
        SELECT t.entregavel_id, count(*) AS n, min(e.estado) AS estado
        FROM trabalho.tarefa_humana t JOIN fechamento.entregavel e ON e.id = t.entregavel_id
        WHERE t.estado = 'aberta' GROUP BY t.entregavel_id
        HAVING count(*) > 1 OR min(e.estado) <> 'pronto'""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-11] entregável {x['entregavel_id']} ({x['estado']}) com {x['n']} tarefa(s) aberta(s)")
    r.checagens += 1

    # INV-12: override resolve a exceção da mesma CF no entregável (§8.1)
    cur.execute("""
        SELECT cf.id, cf.cf FROM conferencias.conferencia cf
        WHERE cf.override_autor IS NOT NULL
          AND EXISTS (SELECT 1 FROM trabalho.excecao x
                      WHERE x.entregavel_id = cf.entregavel_id AND x.tipo = cf.cf AND x.estado = 'aberta')""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-12] conferência {x['id']} ({x['cf']}) com override e exceção ainda aberta")
    r.checagens += 1

    # INV-13: transição 'invalidado' sempre causada por fato
    cur.execute("""
        SELECT id FROM fechamento.entregavel_transicao
        WHERE para_estado = 'invalidado' AND (causado_por_tipo IS DISTINCT FROM 'fato' OR motivo <> 'fato_alterado')""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-13] transição {x['id']} para 'invalidado' sem motivo fato_alterado/causado_por fato")
    r.checagens += 1

    # INV-14: protocolo leva a guia (semântica v3 §10.1, §15): documento.disponibilizado com fato_id de uma guia
    cur.execute("""
        SELECT ev.id FROM eventos.evento ev
        WHERE ev.nome = 'documento.disponibilizado'
          AND NOT EXISTS (SELECT 1 FROM fatos.fato f
                          WHERE f.id::text = ev.payload->>'fato_id' AND f.tipo = 'guia')""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-14] evento documento.disponibilizado {x['id']} sem fato_id de guia")
    r.checagens += 1

    # INV-15: caso encerrado não muda (v3 §10.2, §10.3, §11.3): nenhuma transição depois da de encerramento
    cur.execute("""
        SELECT t.id, t.caso_id FROM fechamento.entregavel_transicao t
        JOIN (SELECT caso_id, min(id) AS enc FROM fechamento.entregavel_transicao
              WHERE para_estado = 'encerrado' GROUP BY caso_id) e ON e.caso_id = t.caso_id
        WHERE t.id > e.enc""")
    for x in cur.fetchall():
        r.checar(False, f"[INV-15] transição {x['id']} no caso {x['caso_id']} depois do encerramento")
    r.checagens += 1
    cur.close()


def medir_indicadores(conn: psycopg.Connection, r: Resultado) -> None:
    """Atualiza estatísticas (como o autovacuum faria) e mede I1–I6."""
    with conn.cursor() as cur:
        for tabela in TABELAS_EXECUCAO:
            cur.execute(f"ANALYZE {tabela}")
        conn.commit()
        for nome, sql in CONSULTAS_INDICADORES.items():
            t0 = time.perf_counter()
            try:
                cur.execute(sql)
                linhas = len(cur.fetchall())
                dt = time.perf_counter() - t0
                r.indicadores[nome] = {"linhas": linhas, "segundos": round(dt, 4)}
                r.checar(dt < LIMITE_INDICADOR_S, f"[{nome}] {dt:.2f}s, limite {LIMITE_INDICADOR_S}s")
            except psycopg.Error as e:
                conn.rollback()
                r.indicadores[nome] = {"erro": str(e)}
                r.checar(False, f"[{nome}] erro: {e}")


# ---------------------------------------------------------------------------
# API
# ---------------------------------------------------------------------------
def aguardar_fila(cliente: httpx.Client, limite_s: float = 120.0) -> float:
    t0 = time.perf_counter()
    while True:
        resp = cliente.get("/admin/fila")
        resp.raise_for_status()
        corpo = resp.json()
        if corpo.get("pendentes", 1) == 0:
            return time.perf_counter() - t0
        if time.perf_counter() - t0 > limite_s:
            raise TimeoutError(f"fila não esvaziou em {limite_s}s: {corpo}")
        time.sleep(0.1)


RAIZ = Path(__file__).resolve().parent.parent


def carregar_massa(param: dict[str, Any]) -> dict[str, Any]:
    """Gera a massa sintética pelo script comum (massa/gerar_massa_sintetica.py), sem arquivo intermediário."""
    spec = importlib.util.spec_from_file_location("gerar_massa_sintetica", RAIZ / "massa" / "gerar_massa_sintetica.py")
    assert spec and spec.loader
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    padrao = {"empresas": 4500, "competencia": "202609", "semente": 20261006, "pct_sn": 0.70, "pct_folha": 0.30,
              "pct_prolabore": 0.85, "pct_taxa": 0.20, "carteiras": 50, "guias": 300}
    a = padrao | param
    return mod.gerar(a["empresas"], a["competencia"], a["semente"], a["pct_sn"], a["pct_folha"],
                     a["pct_prolabore"], a["pct_taxa"], a["carteiras"], a["guias"])


_local = threading.local()


def _cliente_thread(api: str) -> httpx.Client:
    c = getattr(_local, "cliente", None)
    if c is None:
        c = httpx.Client(base_url=api, timeout=120.0)
        _local.cliente = c
    return c


def executar_passo(cliente: httpx.Client, conn: psycopg.Connection, passo: dict[str, Any],
                   titulares: list[str], r: Resultado, i: int) -> None:
    acao = passo["acao"]
    rotulo = f"passo {i} {acao}" + (f" '{passo['nome']}'" if "nome" in passo else "")
    t0 = time.perf_counter()
    esp = passo.get("espera", {})

    if acao == "abrir_competencias":
        resp = cliente.post("/competencias/abrir", json=passo["corpo"])
        r.checar(resp.status_code == esp.get("status", 200), f"[{rotulo}] HTTP {resp.status_code}: {resp.text[:300]}")
        if resp.status_code == 200:
            corpo = resp.json()
            if "criados" in esp:
                r.checar(len(corpo.get("criados", [])) == esp["criados"], f"[{rotulo}] criados {len(corpo.get('criados', []))}, esperado {esp['criados']}")
            if "existentes" in esp:
                r.checar(len(corpo.get("existentes", [])) == esp["existentes"], f"[{rotulo}] existentes {len(corpo.get('existentes', []))}, esperado {esp['existentes']}")

    elif acao == "publicar_fato":
        resp = cliente.post("/fatos", json=passo["corpo"])
        r.checar(resp.status_code == esp.get("status", 200), f"[{rotulo}] HTTP {resp.status_code}: {resp.text[:300]}")
        if resp.status_code == 200 and "efeito" in esp:
            r.checar(resp.json().get("efeito") == esp["efeito"],
                     f"[{rotulo}] efeito {resp.json().get('efeito')!r}, esperado {esp['efeito']!r} ({passo['corpo']['tipo']})")

    elif acao == "concluir_entregavel":
        url = f"/casos/{passo['titular_id']}/{passo['competencia']}/entregaveis/{passo['tipo']}/concluir"
        resp = cliente.post(url, json=passo["corpo"])
        r.checar(resp.status_code == esp.get("status", 200), f"[{rotulo}] {passo['tipo']}: HTTP {resp.status_code}: {resp.text[:300]}")

    elif acao == "override":
        url = f"/casos/{passo['titular_id']}/{passo['competencia']}/entregaveis/{passo['tipo']}/override"
        resp = cliente.post(url, json=passo["corpo"])
        r.checar(resp.status_code == esp.get("status", 200), f"[{rotulo}] HTTP {resp.status_code}: {resp.text[:300]}")

    elif acao == "aguardar_fila":
        aguardar_fila(cliente, passo.get("limite_segundos", 120.0))

    elif acao == "abrir_massa":
        massa = carregar_massa(passo["massa"])
        empresas = massa["abrir"]["empresas"]
        lote = passo.get("lote", 500)
        criados = 0
        t_ini = time.perf_counter()
        for i in range(0, len(empresas), lote):
            corpo = {"competencia": massa["abrir"]["competencia"], "ator": "agenda", "empresas": empresas[i:i + lote]}
            resp = cliente.post("/competencias/abrir", json=corpo, timeout=600.0)
            r.checar(resp.status_code == 200, f"[{rotulo}] lote {i // lote}: HTTP {resp.status_code}: {resp.text[:200]}")
            if resp.status_code != 200:
                break
            criados += len(resp.json().get("criados", []))
        aguardar_fila(cliente, esp.get("limite_segundos", 300.0))
        dt = time.perf_counter() - t_ini
        r.indicadores["abertura_massa"] = {"empresas": len(empresas), "criados": criados, "segundos": round(dt, 2)}
        r.checar(criados == esp.get("criados", len(empresas)), f"[{rotulo}] criados {criados}, esperado {esp.get('criados', len(empresas))}")
        r.checar(dt < esp.get("limite_segundos", 300.0), f"[{rotulo}] abertura em {dt:.1f}s, limite {esp.get('limite_segundos', 300.0)}s")

    elif acao == "publicar_lote":
        massa = carregar_massa(passo["massa"])
        corpos = massa[passo["origem"]]
        api = str(cliente.base_url)
        tempos: list[float] = []
        erros: list[str] = []
        trava = threading.Lock()

        def enviar(corpo: dict[str, Any]) -> None:
            t0 = time.perf_counter()
            resp = _cliente_thread(api).post("/fatos", json=corpo)
            dt = time.perf_counter() - t0
            with trava:
                tempos.append(dt)
                if resp.status_code != 200:
                    erros.append(f"HTTP {resp.status_code}: {resp.text[:120]}")

        t_ini = time.perf_counter()
        with ThreadPoolExecutor(max_workers=passo.get("concorrencia", 20)) as pool:
            list(pool.map(enviar, corpos))
        aguardar_fila(cliente, esp.get("limite_segundos", 600.0))
        total = time.perf_counter() - t_ini
        p95 = statistics.quantiles(tempos, n=20)[18] if len(tempos) >= 20 else max(tempos, default=0.0)
        r.indicadores["lote_fatos"] = {"quantidade": len(corpos), "p95_segundos": round(p95, 3),
                                       "total_segundos": round(total, 2), "erros": len(erros)}
        r.checar(not erros, f"[{rotulo}] {len(erros)} erro(s), ex.: {erros[:2]}")
        r.checar(p95 < esp.get("p95_segundos", 30.0), f"[{rotulo}] p95 {p95:.2f}s, limite {esp.get('p95_segundos', 30.0)}s")

    elif acao == "verificar":
        conn.rollback()  # garante leitura fresca
        verificar_esperado(conn, passo["esperado"], titulares, r, rotulo)

    else:
        r.checar(False, f"[{rotulo}] ação desconhecida no cenário")

    r.passos.append({"passo": i, "acao": acao, "segundos": round(time.perf_counter() - t0, 4)})


def rodar_cenario(api: str, dsn: str, caminho: Path, limpar_antes: bool) -> Resultado:
    cenario = json.loads(caminho.read_text(encoding="utf-8"))
    r = Resultado(cenario=f"{cenario['id']} · {cenario['nome']}")
    with psycopg.connect(dsn) as conn, httpx.Client(base_url=api, timeout=60.0) as cliente:
        saude = cliente.get("/health")
        if saude.status_code != 200:
            r.checar(False, f"/health devolveu {saude.status_code}")
            return r
        if limpar_antes:
            limpar(conn)
        for i, passo in enumerate(cenario["passos"], start=1):
            try:
                executar_passo(cliente, conn, passo, cenario["titulares"], r, i)
            except (httpx.HTTPError, TimeoutError) as e:
                r.checar(False, f"[passo {i} {passo['acao']}] erro: {e}")
                break
        conn.rollback()
        verificar_invariantes(conn, r)
        medir_indicadores(conn, r)
    return r


def imprimir(r: Resultado) -> None:
    status = "PASSOU" if r.passou else "FALHOU"
    print(f"\n== {r.cenario}: {status} ({r.checagens} checagens, {len(r.falhas)} falha(s))")
    for f in r.falhas:
        print(f"   ✗ {f}")
    if r.indicadores:
        def fmt(k: str, v: dict[str, Any]) -> str:
            if "erro" in v:
                return f"{k}=ERRO"
            if "p95_segundos" in v:
                return f"{k}=p95 {v['p95_segundos']}s ({v['quantidade']} fatos, {v['erros']} erro(s))"
            if "criados" in v:
                return f"{k}={v['segundos']}s ({v['criados']} casos)"
            return f"{k}={v['segundos']}s"
        ind = ", ".join(fmt(k, v) for k, v in r.indicadores.items())
        print(f"   indicadores: {ind}")


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--api", required=True, help="URL base da spike, ex.: http://localhost:8081")
    ap.add_argument("--db", required=True, help="DSN do banco da spike")
    grupo = ap.add_mutually_exclusive_group(required=True)
    grupo.add_argument("--cenario", type=Path, help="arquivo de cenário")
    grupo.add_argument("--todos", type=Path, help="pasta com cenários c*.json")
    ap.add_argument("--limpar", action="store_true", help="esvazia as tabelas de execução antes de cada cenário")
    ap.add_argument("--relatorio", type=Path, help="grava o resultado em JSON")
    ap.add_argument("--fase", type=int, help="roda só cenários das fases 1..N (padrão: todos)")
    args = ap.parse_args()

    arquivos = [args.cenario] if args.cenario else sorted(args.todos.glob("c*.json"), key=lambda a: int(a.name[1:].split("-")[0]))
    if args.fase is not None:
        arquivos = [a for a in arquivos
                    if json.loads(a.read_text(encoding="utf-8")).get("fase", 1) <= args.fase]
    resultados = [rodar_cenario(args.api, args.db, a, args.limpar) for a in arquivos]
    for r in resultados:
        imprimir(r)
    if args.relatorio:
        args.relatorio.write_text(json.dumps([r.__dict__ | {"passou": r.passou} for r in resultados],
                                             ensure_ascii=False, indent=2), encoding="utf-8")
    ok = all(r.passou for r in resultados)
    print(f"\nRESULTADO: {'todos passaram' if ok else 'há falhas'} ({sum(r.passou for r in resultados)}/{len(resultados)})")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())
