-- V009 · Fase 4.5 (semântica v5, 27/10/2026): contrato de fato, hash normalizado, recebimento e concorrência
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

INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('aliquota_mes', 'Alíquota efetiva do mês em pontos-base', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('aliquota_mes', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"aliquota_bp":{"type":"integer"}},"required":["aliquota_bp"],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'proibido', ARRAY['aliquota_bp']::text[], '{}'::jsonb, ARRAY['calculo']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('anotacao_interna', 'Anotação livre da operação (nenhuma regra consome)', 'operacao');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('anotacao_interna', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"texto":{"minLength":1,"type":"string"}},"required":["texto"],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'proibido', ARRAY['texto']::text[], '{}'::jsonb, ARRAY['intranet']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('apurado', 'Tributo apurado na competência', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('apurado', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{},"required":[],"type":"object"}'::jsonb, '{"usa":true,"valores":["IRPJ","CSLL","PIS","COFINS","DAS"]}'::jsonb, 'obrigatorio', ARRAY[]::text[], '{}'::jsonb, ARRAY['tarefa', 'equiparacao', 'intranet-legado']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('divisao_socios', 'Divisão do lucro/pró-labore entre sócios', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('divisao_socios', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"parcelas":{"items":{"additionalProperties":true,"properties":{"socio":{"minLength":1,"type":"string"},"tributo":{"minLength":1,"type":"string"},"valor_centavos":{"type":"integer"}},"required":["socio","valor_centavos"],"type":"object"},"minItems":1,"type":"array"}},"required":["parcelas"],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'proibido', ARRAY['parcelas']::text[], '{"parcelas":["socio","tributo"]}'::jsonb, ARRAY['calculo']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('documento_disponibilizado', 'Documento disponibilizado ao cliente (protocolo)', 'entrega');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('documento_disponibilizado', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"canal":{"minLength":1,"type":"string"}},"required":["canal"],"type":"object"}'::jsonb, '{"usa":true,"valores":["DAS","INSS","FGTS","IRRF","IRPJ","CSLL","PIS","COFINS"]}'::jsonb, 'proibido', ARRAY['canal']::text[], '{}'::jsonb, ARRAY['entrega']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('entrega_confirmada', 'Confirmação de leitura/recebimento pelo cliente', 'entrega');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('entrega_confirmada', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"canal":{"minLength":1,"type":"string"},"como":{"minLength":1,"type":"string"}},"required":["canal"],"type":"object"}'::jsonb, '{"usa":true,"valores":["DAS","INSS","FGTS","IRRF","IRPJ","CSLL","PIS","COFINS"]}'::jsonb, 'proibido', ARRAY['canal', 'como']::text[], '{}'::jsonb, ARRAY['entrega']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('entrega_falhou', 'Falha na entrega do documento', 'entrega');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('entrega_falhou', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"canal":{"minLength":1,"type":"string"},"motivo":{"minLength":1,"type":"string"}},"required":["canal","motivo"],"type":"object"}'::jsonb, '{"usa":true,"valores":["DAS","INSS","FGTS","IRRF","IRPJ","CSLL","PIS","COFINS"]}'::jsonb, 'proibido', ARRAY['canal', 'motivo']::text[], '{}'::jsonb, ARRAY['entrega']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('faturamento_mes', 'Faturamento do mês informado pela Intranet', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('faturamento_mes', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{},"required":[],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'obrigatorio', ARRAY[]::text[], '{}'::jsonb, ARRAY['intranet']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('folha', 'Resumo da folha do mês', 'dp');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('folha', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"fgts_centavos":{"type":"integer"},"inss_centavos":{"type":"integer"},"irrf_centavos":{"type":["integer","null"]}},"required":["inss_centavos","fgts_centavos"],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'obrigatorio', ARRAY['inss_centavos', 'fgts_centavos', 'irrf_centavos']::text[], '{}'::jsonb, ARRAY['dp', 'tarefa']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('guia', 'Guia de recolhimento extraída do documento', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('guia', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"competencia_impressa":{"pattern":"^[0-9]{6}$","type":"string"},"vencimento":{"pattern":"^[0-9]{4}-[0-9]{2}-[0-9]{2}$","type":["string","null"]}},"required":["competencia_impressa"],"type":"object"}'::jsonb, '{"usa":true,"valores":["DAS","INSS","FGTS","IRRF","IRPJ","CSLL","PIS","COFINS"]}'::jsonb, 'obrigatorio', ARRAY['competencia_impressa', 'vencimento']::text[], '{}'::jsonb, ARRAY['porta_documental']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('notas_oneflow', 'Total das notas emitidas no mês, lido do OneFlow', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('notas_oneflow', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{},"required":[],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'obrigatorio', ARRAY[]::text[], '{}'::jsonb, ARRAY['oneflow']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('prolabore', 'Pró-labore do mês', 'dp');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('prolabore', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{},"required":[],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'obrigatorio', ARRAY[]::text[], '{}'::jsonb, ARRAY['tarefa']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('recebimento_pendente', 'Documento disponibilizado sem leitura', 'entrega');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('recebimento_pendente', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"dias_sem_leitura":{"minimum":0,"type":"integer"}},"required":["dias_sem_leitura"],"type":"object"}'::jsonb, '{"usa":true,"valores":["DAS","INSS","FGTS","IRRF","IRPJ","CSLL","PIS","COFINS"]}'::jsonb, 'proibido', ARRAY['dias_sem_leitura']::text[], '{}'::jsonb, ARRAY['agendador']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('recibo_obrigacao', 'Recibo de entrega de obrigação acessória', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('recibo_obrigacao', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{"numero_recibo":{"minLength":1,"type":"string"}},"required":["numero_recibo"],"type":"object"}'::jsonb, '{"usa":true,"valores":["PGDAS-D","DCTFWEB"]}'::jsonb, 'proibido', ARRAY['numero_recibo']::text[], '{}'::jsonb, ARRAY['integra']::text[], 'interna', NULL);
INSERT INTO fatos.tipo_fato (tipo, descricao, area_dona) VALUES ('taxa_municipal', 'Taxa municipal do mês (ou dispensa)', 'fiscal');
INSERT INTO fatos.tipo_fato_versao (tipo, versao, estado, esquema, qualificador, valor_centavos, campos_hash, ordenar_arrays, fontes_permitidas, sensibilidade, retencao) VALUES ('taxa_municipal', 1, 'ativa', '{"$schema":"https://json-schema.org/draft/2020-12/schema","additionalProperties":true,"properties":{},"required":[],"type":"object"}'::jsonb, '{"usa":false,"valores":[]}'::jsonb, 'opcional', ARRAY[]::text[], '{}'::jsonb, ARRAY['intranet']::text[], 'interna', NULL);
