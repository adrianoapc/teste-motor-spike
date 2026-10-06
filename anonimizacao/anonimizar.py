#!/usr/bin/env python3
"""Anonimização local de amostras do fechamento (spec do spike §7.3).

RODA SÓ NO MAC DO ADRIANO. A chave nunca sai da máquina e nunca vai para o Git.
Entrada: CSV exportado (planilha do mês, OneFlow, metadados de guias).
Saída: CSV com identificadores trocados por pseudônimos estáveis (HMAC-SHA256) e
colunas pessoais removidas. Valores monetários são mantidos (não identificam sozinhos).

Uso:
  python anonimizar.py --entrada amostra.csv --saida ../dados-anon/amostra.csv \
      --pseudonimizar CNPJ,CPF_SOCIO --remover RAZAO_SOCIAL,NOME_SOCIO,EMAIL \
      [--separador ";"]

Chave: variável RISSI_ANON_CHAVE (hex, 64 caracteres) ou o arquivo
~/.rissi-spike/chave-anon (criado na primeira execução, permissão 600).

O mesmo CNPJ vira sempre o mesmo pseudônimo com a mesma chave, em qualquer arquivo,
então os cruzamentos entre planilhas continuam funcionando.
"""
from __future__ import annotations

import argparse
import csv
import hashlib
import hmac
import os
import re
import secrets
import sys
from pathlib import Path

ARQUIVO_CHAVE = Path.home() / ".rissi-spike" / "chave-anon"


def carregar_chave() -> bytes:
    hex_env = os.environ.get("RISSI_ANON_CHAVE")
    if hex_env:
        return bytes.fromhex(hex_env)
    if not ARQUIVO_CHAVE.exists():
        ARQUIVO_CHAVE.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        ARQUIVO_CHAVE.write_text(secrets.token_hex(32))
        ARQUIVO_CHAVE.chmod(0o600)
        print(f"chave nova criada em {ARQUIVO_CHAVE} (guarde fora do Git)", file=sys.stderr)
    return bytes.fromhex(ARQUIVO_CHAVE.read_text().strip())


def normalizar(valor: str) -> str:
    """CNPJ/CPF: só dígitos, para '12.345.678/0001-90' e '12345678000190' darem o mesmo pseudônimo."""
    digitos = re.sub(r"\D", "", valor)
    return digitos if len(digitos) in (11, 14) else valor.strip().upper()


def pseudonimo(chave: bytes, valor: str, prefixo: str) -> str:
    if not valor.strip():
        return ""
    mac = hmac.new(chave, normalizar(valor).encode("utf-8"), hashlib.sha256).hexdigest()
    return f"{prefixo}{mac[:12]}"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--entrada", type=Path, required=True)
    ap.add_argument("--saida", type=Path, required=True)
    ap.add_argument("--pseudonimizar", default="", help="colunas trocadas por pseudônimo, separadas por vírgula")
    ap.add_argument("--remover", default="", help="colunas removidas, separadas por vírgula")
    ap.add_argument("--separador", default=",")
    ap.add_argument("--prefixo", default="T", help="prefixo do pseudônimo (padrão T, como nos cenários)")
    args = ap.parse_args()

    chave = carregar_chave()
    pseudo = [c.strip() for c in args.pseudonimizar.split(",") if c.strip()]
    remover = {c.strip() for c in args.remover.split(",") if c.strip()}

    with args.entrada.open(newline="", encoding="utf-8-sig") as fe:
        leitor = csv.DictReader(fe, delimiter=args.separador)
        colunas = leitor.fieldnames or []
        faltando = [c for c in pseudo + sorted(remover) if c not in colunas]
        if faltando:
            print(f"colunas inexistentes na entrada: {faltando}", file=sys.stderr)
            return 2
        saida_cols = [c for c in colunas if c not in remover]
        args.saida.parent.mkdir(parents=True, exist_ok=True)
        with args.saida.open("w", newline="", encoding="utf-8") as fs:
            escritor = csv.DictWriter(fs, fieldnames=saida_cols, delimiter=args.separador)
            escritor.writeheader()
            n = 0
            for linha in leitor:
                for c in pseudo:
                    linha[c] = pseudonimo(chave, linha[c] or "", args.prefixo)
                escritor.writerow({c: linha[c] for c in saida_cols})
                n += 1
    print(f"{n} linha(s) anonimizada(s) → {args.saida}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
