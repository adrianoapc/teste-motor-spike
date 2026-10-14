#!/usr/bin/env python3
"""Harness único e independente de linguagem para o comparativo dos 3 spikes.

Só fala HTTP com a API do spike e lê o log do Postgres (log_statement=all) e o banco do spike.
Nada aqui depende da linguagem da aplicação. Subcomandos:

  ops     SQLs por operação (conjunto fixo de operações sobre 1 empresa SN com folha), N repetições
  c6      cenário C6 isolado, N repetições (chama o verificador comum)
  abrir   uma única requisição POST /competencias/abrir com 10.000 empresas, com amostragem de
          docker stats a cada 5 s

Exemplo (ver medicao/LEIAME.md):
  python sql_por_operacao.py ops --api http://localhost:58081 \
      --db postgresql://spike:spike@localhost:55432/spike_dotnet \
      --pg-container vfmedicao-postgres-1 --app-container vfmedicao-spike-dotnet-1 \
      --label dotnet --reps 3 --out ops-dotnet.json

Dependências: httpx, psycopg[binary] (as mesmas do verificador/requirements.txt) e a CLI docker.
"""
from __future__ import annotations

import argparse
import json
import re
import statistics
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Any

import httpx
import psycopg

TABELAS_EXECUCAO = [
    "eventos.entrega_evento", "eventos.evento", "eventos.fila",
    "trabalho.tarefa_humana", "trabalho.excecao",
    "conferencias.conferencia",
    "fechamento.entregavel_fato", "fechamento.entregavel_dependencia",
    "fechamento.entregavel_transicao", "fechamento.entregavel", "fechamento.caso_competencia",
    "fatos.fato",
]
# tabelas usadas para provar o que uma operação alterou
TABELAS_PROVA = [
    "fatos.fato", "fechamento.caso_competencia", "fechamento.entregavel", "fechamento.entregavel_transicao",
    "fechamento.entregavel_fato", "fechamento.entregavel_dependencia", "conferencias.conferencia",
    "trabalho.tarefa_humana", "trabalho.excecao", "eventos.evento", "eventos.fila", "eventos.entrega_evento",
]

COMPETENCIA = "202609"
OBS = "2026-10-01T09:00:00-03:00"
EMPRESA_T001 = {
    "titular_id": "T001", "regime": "SN", "tem_folha": True, "tem_prolabore": True,
    "tem_taxa_municipal": False, "filial": False, "carteira": "C-01", "municipio": "3549805",
}
# (nome, método, caminho, corpo, efeito esperado). Os payloads são os MESMOS nas 3 linguagens.
OPERACOES: list[dict[str, Any]] = [
    {"id": "1", "nome": "abrir 1 caso SN com folha", "caminho": "/competencias/abrir",
     "corpo": {"competencia": COMPETENCIA, "ator": "agenda", "empresas": [EMPRESA_T001]}},
    {"id": "2", "nome": "fato que não afeta nada (tipo sem consumidor em nenhuma regra)", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "anotacao_interna", "fonte": "intranet",
               "observado_em": OBS, "valor_centavos": 1, "payload": {"texto": "nota um"}}, "efeito": "novo"},
    {"id": "P", "nome": "preparação: faturamento_mes (1ª metade do insumo do E01)", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "faturamento_mes", "fonte": "intranet",
               "observado_em": OBS, "valor_centavos": 10000000}, "efeito": "novo", "preparacao": True},
    {"id": "3", "nome": "fato que leva o E01 a validado (notas_oneflow = faturamento_mes)", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "notas_oneflow", "fonte": "oneflow",
               "observado_em": OBS, "valor_centavos": 10000000}, "efeito": "novo", "e01_depois": "validado"},
    {"id": "4", "nome": "fato repetido (mesmo conteúdo, sem_mudanca)", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "notas_oneflow", "fonte": "oneflow",
               "observado_em": OBS, "valor_centavos": 10000000}, "efeito": "sem_mudanca"},
    {"id": "5", "nome": "nova versão de fato existente (faturamento_mes 10000000 -> 10000100)", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "faturamento_mes", "fonte": "intranet",
               "observado_em": OBS, "valor_centavos": 10000100}, "efeito": "nova_versao"},
    {"id": "5b", "nome": "(extra) nova versão do fato que não afeta nada", "caminho": "/fatos",
     "corpo": {"titular_id": "T001", "competencia": COMPETENCIA, "tipo": "anotacao_interna", "fonte": "intranet",
               "observado_em": OBS, "valor_centavos": 1, "payload": {"texto": "nota dois"}},
     "efeito": "nova_versao", "extra": True},
]

RE_LOG = re.compile(r"^(?P<ts>\S+ \S+ \S+)\|(?P<pid>\d+)\|(?P<app>[^|]*)\|(?P<user>[^|]*)\|(?P<db>[^|]*)\|"
                    r"LOG:\s+(?P<tipo>statement|execute [^:]*):\s*(?P<sql>.*)$")


def sh(*args: str, check: bool = True) -> str:
    r = subprocess.run(args, capture_output=True, text=True)
    if check and r.returncode != 0:
        raise RuntimeError(f"{args}: {r.stderr}")
    return r.stdout + r.stderr


def conn_harness(dsn: str) -> psycopg.Connection:
    # application_name 'harness' => as consultas do harness são descartadas da contagem
    return psycopg.connect(dsn, autocommit=True, application_name="harness")


def limpar(dsn: str) -> None:
    with conn_harness(dsn) as c:
        c.execute("TRUNCATE " + ", ".join(TABELAS_EXECUCAO) + " RESTART IDENTITY CASCADE")


class LogPg:
    """Lê o log do Postgres (docker logs) por deslocamento de linhas."""

    def __init__(self, container: str, dbname: str) -> None:
        self.container, self.dbname = container, dbname

    def linhas(self) -> list[str]:
        return sh("docker", "logs", self.container).splitlines()

    def marca(self) -> int:
        return len(self.linhas())

    def instrucoes_desde(self, marca: int) -> list[str]:
        out = []
        for ln in self.linhas()[marca:]:
            m = RE_LOG.match(ln)
            if not m:
                continue
            if m["db"] != self.dbname or m["user"] != "spike" or m["app"] == "harness":
                continue
            out.append(m["sql"].strip())
        return out


def classe(sql: str) -> str:
    p = sql.lstrip().split(None, 1)[0].upper().rstrip(";") if sql.strip() else "?"
    return p if p in {"BEGIN", "COMMIT", "ROLLBACK", "SELECT", "INSERT", "UPDATE", "DELETE", "SET", "DISCARD",
                      "SAVEPOINT", "RELEASE", "WITH", "LOCK", "START"} else "OUTRO"


def fila_vazia(dsn: str) -> bool:
    with conn_harness(dsn) as c:
        return c.execute("SELECT count(*) FROM eventos.fila WHERE concluido_em IS NULL").fetchone()[0] == 0  # type: ignore[index]


def assentar(log: LogPg, dsn: str, marca: int, limite_s: float = 120.0) -> list[str]:
    """Espera a fila esvaziar (consulta direta ao banco, sem passar pela API) e o log ficar estável por 1,5 s."""
    ini = time.time()
    ultimo_n, desde = -1, time.time()
    while time.time() - ini < limite_s:
        instr = log.instrucoes_desde(marca)
        if len(instr) != ultimo_n:
            ultimo_n, desde = len(instr), time.time()
        elif time.time() - desde >= 1.5 and fila_vazia(dsn):
            return instr
        time.sleep(0.25)
    raise TimeoutError("log/fila não assentaram")


def snapshot(dsn: str) -> dict[str, Any]:
    with conn_harness(dsn) as c:
        cont = {t: c.execute(f"SELECT count(*) FROM {t}").fetchone()[0] for t in TABELAS_PROVA}  # type: ignore[index]
        est = c.execute(
            "SELECT e.tipo, e.estado FROM fechamento.entregavel e ORDER BY e.tipo").fetchall()
        eventos = c.execute("SELECT nome, count(*) FROM eventos.evento GROUP BY nome ORDER BY nome").fetchall()
    return {"contagens": cont, "estados": {t: s for t, s in est}, "eventos_por_tipo": {t: n for t, n in eventos}}


def diff(a: dict[str, Any], b: dict[str, Any]) -> dict[str, Any]:
    d: dict[str, Any] = {}
    cont = {t: b["contagens"][t] - a["contagens"][t] for t in b["contagens"] if b["contagens"][t] != a["contagens"][t]}
    if cont:
        d["linhas_novas_por_tabela"] = cont
    est = {t: [a["estados"].get(t), s] for t, s in b["estados"].items() if a["estados"].get(t) != s}
    if est:
        d["estados_mudados"] = est
    ev = {t: b["eventos_por_tipo"].get(t, 0) - a["eventos_por_tipo"].get(t, 0)
          for t in set(a["eventos_por_tipo"]) | set(b["eventos_por_tipo"])
          if b["eventos_por_tipo"].get(t, 0) != a["eventos_por_tipo"].get(t, 0)}
    if ev:
        d["eventos_novos_por_tipo"] = ev
    return d


def configurar_log(dsn: str, ligar: bool) -> None:
    with conn_harness(dsn) as c:
        if ligar:
            c.execute("ALTER SYSTEM SET log_statement = 'all'")
            c.execute("ALTER SYSTEM SET log_line_prefix = '%m|%p|%a|%u|%d|'")
        else:
            c.execute("ALTER SYSTEM RESET log_statement")
            c.execute("ALTER SYSTEM RESET log_line_prefix")
        c.execute("SELECT pg_reload_conf()")
    time.sleep(1.0)


def post(cli: httpx.Client, caminho: str, corpo: dict[str, Any]) -> httpx.Response:
    return cli.post(caminho, json=corpo)


def cmd_ops(a: argparse.Namespace) -> None:
    dsn_admin = re.sub(r"/[^/]+$", "/postgres", a.db)
    dbname = a.db.rsplit("/", 1)[1]
    log = LogPg(a.pg_container, dbname)
    cli = httpx.Client(base_url=a.api, timeout=120)
    configurar_log(dsn_admin, True)
    try:
        # sondagem/aquecimento (DESCARTADA do contador): health, abertura e fato de um titular descartável
        limpar(a.db)
        assert cli.get("/health").status_code == 200
        emp = dict(EMPRESA_T001, titular_id="AQUECIMENTO")
        r = post(cli, "/competencias/abrir", {"competencia": COMPETENCIA, "ator": "agenda", "empresas": [emp]})
        assert r.status_code == 200, r.text
        for t, v in (("faturamento_mes", 5), ("notas_oneflow", 5)):
            r = post(cli, "/fatos", {"titular_id": "AQUECIMENTO", "competencia": COMPETENCIA, "tipo": t,
                                     "fonte": "x", "observado_em": OBS, "valor_centavos": v})
            assert r.status_code == 200, r.text
        assentar(log, a.db, 0)
        resultados: list[dict[str, Any]] = []
        for rep in range(1, a.reps + 1):
            limpar(a.db)
            time.sleep(0.5)
            rep_res: dict[str, Any] = {"rep": rep, "ops": {}}
            for op in OPERACOES:
                antes = snapshot(a.db)
                marca = log.marca()
                t0 = time.perf_counter()
                r = post(cli, op["caminho"], op["corpo"])
                dt = time.perf_counter() - t0
                instr = assentar(log, a.db, marca)
                depois = snapshot(a.db)
                corpo = r.json() if r.headers.get("content-type", "").startswith("application/json") else {}
                ok = r.status_code == 200
                if op.get("efeito"):
                    ok = ok and corpo.get("efeito") == op["efeito"]
                if op["id"] == "1":
                    ok = ok and len(corpo.get("criados", [])) == 1
                if op.get("e01_depois"):
                    ok = ok and depois["estados"].get("E01") == op["e01_depois"]
                cls: dict[str, int] = {}
                for s in instr:
                    cls[classe(s)] = cls.get(classe(s), 0) + 1
                rep_res["ops"][op["id"]] = {
                    "nome": op["nome"], "status": r.status_code, "efeito": corpo.get("efeito"),
                    "ok": ok, "sqls": len(instr), "por_classe": cls, "latencia_s": round(dt, 4),
                    "mudou": diff(antes, depois), "e01": depois["estados"].get("E01"),
                    "amostra_sql": instr if a.dump_sql else None,
                }
                if not ok:
                    print(f"ATENÇÃO op {op['id']} rep {rep}: {r.status_code} {r.text[:300]}", file=sys.stderr)
            resultados.append(rep_res)
    finally:
        configurar_log(dsn_admin, False)
        limpar(a.db)
    resumo: dict[str, Any] = {}
    for op in OPERACOES:
        v = [r["ops"][op["id"]]["sqls"] for r in resultados]
        resumo[op["id"]] = {"nome": op["nome"], "sqls": v, "mediana": statistics.median(v), "min": min(v), "max": max(v)}
    Path(a.out).write_text(json.dumps({"label": a.label, "reps": resultados, "resumo": resumo}, ensure_ascii=False, indent=1))
    print(f"== {a.label}: SQLs por operação (N={a.reps}) ==")
    for k, v in resumo.items():
        print(f"  op {k:>2}  mediana {v['mediana']:>5}  min {v['min']:>5}  max {v['max']:>5}  {v['sqls']}  {v['nome']}")


# ---------------------------------------------------------------- C6 isolado
def cmd_c6(a: argparse.Namespace) -> None:
    out = []
    for i in range(1, a.reps + 1):
        t0 = time.time()
        r = subprocess.run([sys.executable, a.verificador, "--api", a.api, "--db", a.db, "--cenario", a.cenario,
                            "--limpar"], capture_output=True, text=True)
        txt = r.stdout + r.stderr
        m = re.search(r"abertura_massa=([\d.]+)s \((\d+) casos\), lote_fatos=p95 ([\d.]+)s \((\d+) fatos, (\d+) erro", txt)
        passou = "PASSOU" in txt and r.returncode == 0
        out.append({"rep": i, "passou": passou, "parede_total_s": round(time.time() - t0, 1),
                    "abertura_s": float(m[1]) if m else None, "casos": int(m[2]) if m else None,
                    "p95_s": float(m[3]) if m else None, "fatos": int(m[4]) if m else None,
                    "erros": int(m[5]) if m else None, "saida_final": txt.strip().splitlines()[-1] if txt.strip() else ""})
        print(out[-1], flush=True)
    for chave in ("abertura_s", "p95_s", "erros"):
        v = [o[chave] for o in out if o[chave] is not None]
        if v:
            print(f"{a.label} {chave}: mediana {statistics.median(v)} min {min(v)} max {max(v)}")
    Path(a.out).write_text(json.dumps({"label": a.label, "reps": out}, ensure_ascii=False, indent=1))


# ---------------------------------------------------------------- abertura com 10.000 empresas
def empresas_10k(n: int) -> list[dict[str, Any]]:
    return [{"titular_id": f"M{i:05d}", "regime": "SN", "tem_folha": True, "tem_prolabore": True,
             "tem_taxa_municipal": False, "filial": False, "carteira": f"C-{(i % 20) + 1:02d}",
             "municipio": "3549805"} for i in range(n)]


def amostrar_stats(containers: list[str], parar: threading.Event, saida: list[dict[str, Any]]) -> None:
    t0 = time.time()
    while not parar.is_set():
        txt = sh("docker", "stats", "--no-stream", "--format", "{{json .}}", *containers, check=False)
        for ln in txt.splitlines():
            try:
                j = json.loads(ln)
                saida.append({"t": round(time.time() - t0, 1), "nome": j["Name"], "cpu": j["CPUPerc"], "mem": j["MemUsage"]})
            except Exception:
                pass
        parar.wait(max(0.0, 5 - (time.time() - t0) % 5))


def pico(amostras: list[dict[str, Any]], nome: str) -> dict[str, Any]:
    def num(s: str) -> float:
        return float(s.rstrip("%"))

    def mib(s: str) -> float:
        u = s.split("/")[0].strip()
        for suf, f in (("GiB", 1024), ("MiB", 1), ("KiB", 1 / 1024), ("B", 1 / 1048576)):
            if u.endswith(suf):
                return float(u[:-len(suf)]) * f
        return 0.0

    am = [x for x in amostras if x["nome"] == nome]
    if not am:
        return {}
    return {"amostras": len(am), "cpu_max_pct": max(num(x["cpu"]) for x in am),
            "cpu_media_pct": round(statistics.mean(num(x["cpu"]) for x in am), 1),
            "mem_max_mib": round(max(mib(x["mem"]) for x in am), 1)}


def cmd_abrir(a: argparse.Namespace) -> None:
    corpo = {"competencia": "202610", "ator": "agenda", "empresas": empresas_10k(a.empresas)}
    tamanho_req = len(json.dumps(corpo))
    res = []
    for i in range(1, a.reps + 1):
        limpar(a.db)
        cli = httpx.Client(base_url=a.api, timeout=httpx.Timeout(a.timeout))
        assert cli.get("/health").status_code == 200
        parar, amostras = threading.Event(), []
        th = threading.Thread(target=amostrar_stats, args=([a.app_container, a.pg_container], parar, amostras))
        th.start()
        t0 = time.perf_counter()
        info: dict[str, Any] = {"rep": i, "empresas": a.empresas, "tamanho_requisicao_bytes": tamanho_req}
        try:
            r = cli.post("/competencias/abrir", json=corpo)
            info.update(status=r.status_code, tamanho_resposta_bytes=len(r.content))
            if r.status_code == 200:
                j = r.json()
                info.update(criados=len(j["criados"]), existentes=len(j["existentes"]))
            else:
                info["corpo_erro"] = r.text[:500]
        except Exception as e:  # timeout, conexão derrubada...
            info.update(status=None, excecao=f"{type(e).__name__}: {e}")
        info["tempo_total_s"] = round(time.perf_counter() - t0, 1)
        # fila: tempo extra até esvaziar (efeitos assíncronos), medido direto no banco
        t1 = time.perf_counter()
        try:
            while not fila_vazia(a.db) and time.perf_counter() - t1 < 1800:
                time.sleep(1)
            info["fila_esvaziou_apos_s"] = round(time.perf_counter() - t1, 1)
        except Exception as e:
            info["fila_erro"] = str(e)
        parar.set()
        th.join()
        with conn_harness(a.db) as c:
            info["casos_no_banco"] = c.execute("SELECT count(*) FROM fechamento.caso_competencia").fetchone()[0]  # type: ignore[index]
            info["entregaveis_no_banco"] = c.execute("SELECT count(*) FROM fechamento.entregavel").fetchone()[0]  # type: ignore[index]
        insp = json.loads(sh("docker", "inspect", a.app_container))[0]["State"]
        info["app_oom_killed"] = insp["OOMKilled"]
        info["app_restarts"] = json.loads(sh("docker", "inspect", a.app_container))[0]["RestartCount"]
        info["app_cpu"] = pico(amostras, a.app_container)
        info["pg_cpu"] = pico(amostras, a.pg_container)
        info["amostras_stats"] = amostras
        logs = sh("docker", "logs", "--since", f"{int(info['tempo_total_s']) + 60}s", a.app_container, check=False)
        info["app_log_linhas_erro"] = [ln[:300] for ln in logs.splitlines()
                                       if re.search(r"error|exception|fatal|timeout|oom|killed|allowed memory", ln, re.I)][:20]
        res.append(info)
        print({k: v for k, v in info.items() if k not in ("amostras_stats",)}, flush=True)
        limpar(a.db)
    Path(a.out).write_text(json.dumps({"label": a.label, "reps": res}, ensure_ascii=False, indent=1))


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sp = ap.add_subparsers(dest="cmd", required=True)
    for nome, fn in (("ops", cmd_ops), ("c6", cmd_c6), ("abrir", cmd_abrir)):
        p = sp.add_parser(nome)
        p.add_argument("--api", required=True)
        p.add_argument("--db", required=True, help="DSN do banco do spike (postgresql://...)")
        p.add_argument("--label", required=True)
        p.add_argument("--reps", type=int, default=3)
        p.add_argument("--out", required=True)
        p.add_argument("--pg-container", default="vfmedicao-postgres-1")
        p.add_argument("--app-container", default="")
        p.add_argument("--dump-sql", action="store_true", help="guarda o texto de cada SQL contado")
        if nome == "c6":
            p.add_argument("--verificador", required=True)
            p.add_argument("--cenario", required=True)
        if nome == "abrir":
            p.add_argument("--empresas", type=int, default=10000)
            p.add_argument("--timeout", type=float, default=1800)
        p.set_defaults(fn=fn)
    a = ap.parse_args()
    a.fn(a)


if __name__ == "__main__":
    main()
