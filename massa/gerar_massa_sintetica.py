#!/usr/bin/env python3
"""Gera massa SINTÉTICA para o cenário 6 (escala). Nenhum dado de cliente.

Os percentuais vêm de parâmetros (padrões abaixo são estimativas; ajustar com a
distribuição da amostra anonimizada quando ela existir). Determinístico pela semente.

Uso:
  python gerar_massa_sintetica.py --empresas 4500 --competencia 202609 --saida massa-c6.json
Saída: {"abrir": <corpo de /competencias/abrir>, "guias_dctfweb": [<corpos de /fatos>]}
"""
from __future__ import annotations

import argparse
import json
import random
from pathlib import Path


def gerar(n: int, competencia: str, semente: int, pct_sn: float, pct_folha: float,
          pct_prolabore: float, pct_taxa: float, carteiras: int, guias: int) -> dict:
    rnd = random.Random(semente)
    empresas = []
    for i in range(1, n + 1):
        empresas.append({
            "titular_id": f"S{i:05d}",
            "regime": "SN" if rnd.random() < pct_sn else "LP",
            "tem_folha": rnd.random() < pct_folha,
            "tem_prolabore": rnd.random() < pct_prolabore,
            "tem_taxa_municipal": rnd.random() < pct_taxa,
            "filial": False,
            "carteira": f"C-{rnd.randint(1, carteiras):02d}",
            "municipio": "3549805",
        })
    com_folha = [e for e in empresas if e["tem_folha"] or e["tem_prolabore"]]
    lote = rnd.sample(com_folha, min(guias, len(com_folha)))
    guias_dctfweb = [{
        "titular_id": e["titular_id"], "competencia": competencia, "tipo": "guia", "tributo": "INSS",
        "valor_centavos": rnd.randint(50_000, 500_000),
        "payload": {"competencia_impressa": competencia}, "fonte": "porta_documental",
        "observado_em": "2026-10-15T10:00:00-03:00",
    } for e in lote]
    return {"abrir": {"competencia": competencia, "ator": "agenda", "empresas": empresas},
            "guias_dctfweb": guias_dctfweb}


def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--empresas", type=int, default=4500)
    ap.add_argument("--competencia", default="202609")
    ap.add_argument("--semente", type=int, default=20261006)
    ap.add_argument("--pct-sn", type=float, default=0.70, help="estimativa; ajustar com a amostra")
    ap.add_argument("--pct-folha", type=float, default=0.30, help="estimativa; ajustar com a amostra")
    ap.add_argument("--pct-prolabore", type=float, default=0.85, help="estimativa; ajustar com a amostra")
    ap.add_argument("--pct-taxa", type=float, default=0.20, help="estimativa; ajustar com a amostra")
    ap.add_argument("--carteiras", type=int, default=50)
    ap.add_argument("--guias", type=int, default=300)
    ap.add_argument("--saida", type=Path, required=True)
    a = ap.parse_args()
    massa = gerar(a.empresas, a.competencia, a.semente, a.pct_sn, a.pct_folha, a.pct_prolabore,
                  a.pct_taxa, a.carteiras, a.guias)
    a.saida.write_text(json.dumps(massa, ensure_ascii=False), encoding="utf-8")
    print(f"{len(massa['abrir']['empresas'])} empresas, {len(massa['guias_dctfweb'])} guias → {a.saida}")


if __name__ == "__main__":
    main()
