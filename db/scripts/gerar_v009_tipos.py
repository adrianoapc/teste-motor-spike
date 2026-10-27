#!/usr/bin/env python3
"""Gera db/migrations/V009__contrato_de_fato_e_concorrencia.sql a partir de contrato/tipos-fato/*.json.

Os contratos de tipo de fato vivem no repositório (revisados por PR) e o banco é espelho deles
(semântica v5 §6.1). Rodar da raiz depois de mudar um contrato:  python db/scripts/gerar_v009_tipos.py
O verificador confere repositório = banco com --checar-contratos.
"""
from __future__ import annotations

import json
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent.parent
SAIDA = RAIZ / "db" / "migrations" / "V009__contrato_de_fato_e_concorrencia.sql"

DDL = """-- V009 · Fase 4.5 (semântica v5, 27/10/2026): contrato de fato, hash normalizado, recebimento e concorrência
--
-- Segundo teste comum de manutenção (M2, docs/regras-do-jogo.md critério 8).
--   1. fatos.tipo_fato / fatos.tipo_fato_versao: contrato versionado de cada tipo de fato (§6.1).
--      Fonte da verdade: contrato/tipos-fato/*.json. Os INSERT abaixo são GERADOS por
--      db/scripts/gerar_v009_tipos.py; não editar à mão.
--   2. fatos.fato.tipo_versao: versão do contrato em uso quando o fato foi gravado.
--   3. fatos.recebimento: toda publicação válida, inclusive as que não viram fato (§6.4, §6.5).
-- A trava por titular (§13) é pg_advisory_xact_lock no código; não precisa de esquema.

CREATE TABLE fatos.tipo_fato (
    tipo        text PRIMARY KEY,
    descricao   text NOT NULL,
    area_dona   text NOT NULL
);

CREATE TABLE fatos.tipo_fato_versao (
    tipo              text NOT NULL REFERENCES fatos.tipo_fato(tipo),
    versao            integer NOT NULL CHECK (versao > 0),
    estado            text NOT NULL CHECK (estado IN ('rascunho','ativa','aposentada')),
    esquema           jsonb NOT NULL,           -- JSON Schema 2020-12 do payload
    qualificador      jsonb NOT NULL,           -- {"usa": bool, "valores": [...]} para a coluna tributo
    valor_centavos    text NOT NULL CHECK (valor_centavos IN ('obrigatorio','opcional','proibido')),
    campos_hash       text[] NOT NULL,          -- campos de primeiro nível do payload que entram no hash
    ordenar_arrays    jsonb NOT NULL DEFAULT '{}'::jsonb,  -- {"campo_array": ["campo_do_item", ...]}
    fontes_permitidas text[] NOT NULL,
    sensibilidade     text NOT NULL DEFAULT 'interna',
    retencao          text,
    criado_em         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (tipo, versao)
);
CREATE UNIQUE INDEX tipo_fato_uma_ativa ON fatos.tipo_fato_versao (tipo) WHERE estado = 'ativa';

ALTER TABLE fatos.fato ADD COLUMN tipo_versao integer;   -- nulo só em fato gravado antes da V009

-- A view foi criada com * na V003: recriada para expor tipo_versao (coluna nova no fim).
CREATE OR REPLACE VIEW fatos.fato_vigente AS
SELECT DISTINCT ON (titular_id, competencia, tipo, tributo) *
FROM fatos.fato
ORDER BY titular_id, competencia, tipo, tributo, versao DESC;

CREATE TABLE fatos.recebimento (
    id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    titular_id         text NOT NULL,
    competencia        char(6) NOT NULL,
    tipo               text NOT NULL,
    tributo            text NOT NULL DEFAULT '',
    fonte              text NOT NULL,
    chave_idempotencia text,
    observado_em       timestamptz NOT NULL,
    recebido_em        timestamptz NOT NULL DEFAULT clock_timestamp(),
    tipo_versao        integer NOT NULL,
    hash               text NOT NULL,             -- hash v2 do que chegou, sob o contrato ativo
    efeito             text NOT NULL CHECK (efeito IN ('novo','sem_mudanca','nova_versao','obsoleto','conflito_fonte')),
    fato_id            uuid NOT NULL REFERENCES fatos.fato(id),  -- criado (novo/nova_versao) ou o vigente (demais)
    payload            jsonb NOT NULL,            -- o que chegou, inclusive quando não vira fato
    valor_centavos     bigint
);
CREATE UNIQUE INDEX recebimento_idempotencia ON fatos.recebimento (fonte, chave_idempotencia)
    WHERE chave_idempotencia IS NOT NULL;
CREATE INDEX recebimento_chave_idx ON fatos.recebimento (titular_id, competencia, tipo, tributo, recebido_em);
CREATE INDEX recebimento_fato_idx ON fatos.recebimento (fato_id);

CREATE TRIGGER recebimento_so_insercao
    BEFORE UPDATE OR DELETE ON fatos.recebimento
    FOR EACH ROW EXECUTE FUNCTION public.recusar_alteracao();

-- ---------------------------------------------------------------------------
-- Contratos v1 (gerado)
-- ---------------------------------------------------------------------------
"""


def lit(v: object) -> str:
    if v is None:
        return "NULL"
    return "'" + str(v).replace("'", "''") + "'"


def arr(xs: list[str]) -> str:
    return "ARRAY[" + ", ".join(lit(x) for x in xs) + "]::text[]" if xs else "ARRAY[]::text[]"


def js(v: object) -> str:
    return lit(json.dumps(v, ensure_ascii=False, separators=(",", ":"), sort_keys=True)) + "::jsonb"


def main() -> None:
    linhas = [DDL]
    for arq in sorted((RAIZ / "contrato" / "tipos-fato").glob("*.json")):
        c = json.loads(arq.read_text(encoding="utf-8"))
        assert arq.stem == c["tipo"], arq
        linhas.append(f"INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ({lit(c['tipo'])}, {lit(c['descricao'])}, {lit(c['area_dona'])});")
        for v in c["versoes"]:
            for campo in v.get("ordenar_arrays", {}):
                assert campo in v["campos_hash"], f"{arq.name}: ordenar_arrays.{campo} fora de campos_hash"
            linhas.append(
                "INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ("
                f"{lit(c['tipo'])}, {v['versao']}, {lit(v['estado'])}, {js(v['esquema'])}, {js(v['qualificador'])}, {lit(v['valor_centavos'])}, "
                f"{arr(v['campos_hash'])}, {js(v.get('ordenar_arrays', {}))}, {arr(v['fontes_permitidas'])}, {lit(v.get('sensibilidade', 'interna'))}, {lit(v.get('retencao'))});")
    SAIDA.write_text("\n".join(linhas) + "\n", encoding="utf-8")
    print(f"gerado {SAIDA.relative_to(RAIZ)}")


if __name__ == "__main__":
    main()
