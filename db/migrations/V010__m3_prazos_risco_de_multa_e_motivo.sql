-- V010 · Manutenção M3 (30/10/2026): prazos, risco de multa e motivo de catálogo (semântica v6, §18)
--
-- Terceiro teste comum de manutenção das três spikes (docs/regras-do-jogo.md, critério 8).
-- Origem: adendo da RFC-002 "Lições do G-Click" (29/10), §4.3 e §4.4.
--
-- Princípio: prazo é ATRIBUTO do entregável, não estado. Nada desta migração cria estado ou
-- transição. O motor continua sem relógio: o que depende de "hoje" recebe data_ref de quem chama.
--
--   1. Regras novas, todas 'provisoria' (R-02 e R-12 não validadas por Fiscal e DP):
--      - calendario.vencimentos: feriados nacionais e prorrogações. NÃO fixada no caso: quando a
--        Receita prorroga, o prazo do caso aberto muda (comando AplicarCalendario).
--      - obrigacoes.catalogo: versão mínima, só as obrigações que dão vencimento aos entregáveis do
--        spike (códigos de claude/rissi-catalogo-obrigacoes-v0-2026-10-29.md). NÃO fixada no caso.
--      - fechamento.prazos: por tipo de entregável, as obrigações que dão o vencimento, meta, data de
--        ação, se gera multa, estado de cumprimento e esforço estimado. FIXADA no caso, como
--        fechamento.entregaveis (coluna nova regra_prazos_versao_id).
--   2. fechamento.entregavel ganha vencimento, meta e acao; fechamento.entregavel_prazo guarda o
--      histórico (só inserção). A coluna prazo da V003 nunca foi preenchida e fica aposentada.
--   3. trabalho.motivo_catalogo; motivo de catálogo no override (conferencia) e na exceção.
--   4. indicadores.i7_status_prazo(data_ref) e indicadores.i8_multas_em_risco(data_ref), funções
--      porque dependem da data de referência. Como I1–I6, são da base: as spikes não as escrevem.

-- ---------------------------------------------------------------------------
-- 1 · Esquema
-- ---------------------------------------------------------------------------
ALTER TABLE fechamento.caso_competencia
    ADD COLUMN regra_prazos_versao_id uuid REFERENCES regras.regra_versao(id);   -- nulo só em caso aberto antes da V010

ALTER TABLE fechamento.entregavel
    ADD COLUMN vencimento date,
    ADD COLUMN meta       date,
    ADD COLUMN acao       date,
    ADD CONSTRAINT entregavel_datas_juntas CHECK ((vencimento IS NULL) = (meta IS NULL) AND (meta IS NULL) = (acao IS NULL)),
    ADD CONSTRAINT entregavel_datas_ordem  CHECK (acao <= meta AND meta <= vencimento);
COMMENT ON COLUMN fechamento.entregavel.prazo IS 'Aposentada na V010 (nunca preenchida). Use vencimento, meta e acao.';

CREATE TABLE fechamento.entregavel_prazo (
    id                   bigserial PRIMARY KEY,                 -- define a ordem
    entregavel_id        uuid NOT NULL REFERENCES fechamento.entregavel(id),
    caso_id              uuid NOT NULL REFERENCES fechamento.caso_competencia(id),
    vencimento           date NOT NULL,
    meta                 date NOT NULL,
    acao                 date NOT NULL,
    motivo               text NOT NULL CHECK (motivo = 'abertura' OR motivo ~ '^calendario_v[0-9]+$'),
    calendario_versao_id uuid NOT NULL REFERENCES regras.regra_versao(id),
    catalogo_versao_id   uuid NOT NULL REFERENCES regras.regra_versao(id),
    prazos_versao_id     uuid NOT NULL REFERENCES regras.regra_versao(id),
    em                   timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX entregavel_prazo_entregavel_idx ON fechamento.entregavel_prazo (entregavel_id, id);
CREATE TRIGGER entregavel_prazo_so_insercao
    BEFORE UPDATE OR DELETE ON fechamento.entregavel_prazo
    FOR EACH ROW EXECUTE FUNCTION public.recusar_alteracao();

CREATE TABLE trabalho.motivo_catalogo (
    codigo      text PRIMARY KEY,
    aplica_a    text NOT NULL CHECK (aplica_a IN ('override','impedimento','fato_manual')),
    descricao   text NOT NULL,
    exige_texto boolean NOT NULL DEFAULT true
);

ALTER TABLE conferencias.conferencia ADD COLUMN override_motivo_codigo text REFERENCES trabalho.motivo_catalogo(codigo);
ALTER TABLE trabalho.excecao         ADD COLUMN motivo_codigo text REFERENCES trabalho.motivo_catalogo(codigo);

-- Primeira lista de motivos (provisória; pergunta L-4 do adendo, dono: Rafael, Diretor)
INSERT INTO trabalho.motivo_catalogo (codigo, aplica_a, descricao, exige_texto) VALUES
('arredondamento_fonte',     'override',    'Diferença de arredondamento ou truncamento da fonte, conferida', false),
('nota_fora_da_fonte',       'override',    'Nota emitida fora do sistema de origem, conferida em outra fonte', true),
('acordo_coordenacao',       'override',    'Liberação acordada pela coordenação', true),
('cliente_sem_retorno',      'impedimento', 'Cliente não enviou o que falta depois de cobrado', false),
('portal_indisponivel',      'impedimento', 'Portal do órgão fora do ar ou com erro', false),
('terceiro_pendente',        'impedimento', 'Depende de terceiro (contador anterior, banco, prefeitura)', true),
('correcao_coordenacao',     'fato_manual', 'Correção lançada pela coordenação', true);

-- ---------------------------------------------------------------------------
-- 2 · Regras provisórias
-- ---------------------------------------------------------------------------
INSERT INTO regras.regra (chave, descricao) VALUES
('calendario.vencimentos', 'Feriados e prorrogações que movem vencimentos. Não fixada no caso (semântica §18.1)'),
('obrigacoes.catalogo',    'Catálogo de obrigações: aplicabilidade e regra de vencimento. Não fixado no caso (semântica §18.1)'),
('fechamento.prazos',      'Prazos por tipo de entregável: vencimento, meta, ação, multa, cumprimento. Fixada no caso (semântica §18.1)');

WITH conteudo(chave, versao, c) AS (VALUES
('calendario.vencimentos', 1, $json$
{
  "observacao": "PROVISÓRIA. v1 (30/10/2026). Feriados nacionais de lei (Lei 662/1949, Lei 6.802/1980, Lei 14.759/2023) de 2026 e 2027, mais a Sexta-feira Santa. Carnaval e Corpus Christi não entram (ponto facultativo): a confirmar com o Fiscal (R-02). Sem feriado estadual ou municipal no spike.",
  "abrangencia": "nacional",
  "feriados": ["2026-01-01", "2026-04-03", "2026-04-21", "2026-05-01", "2026-09-07", "2026-10-12", "2026-11-02", "2026-11-15", "2026-11-20", "2026-12-25",
               "2027-01-01", "2027-03-26", "2027-04-21", "2027-05-01", "2027-09-07", "2027-10-12", "2027-11-02", "2027-11-15", "2027-11-20", "2027-12-25"],
  "prorrogacoes": []
}
$json$::jsonb),
('obrigacoes.catalogo', 1, $json$
{
  "observacao": "PROVISÓRIA. v1 mínima (30/10/2026): só as obrigações que dão vencimento aos entregáveis do spike. Códigos e regras do catálogo v0 de 29/10 (confiança média; R-02). Na M3 o catálogo só alimenta prazos; obrigação como item do caso é a M4.",
  "obrigacoes": [
    {"codigo": "SN-01", "nome": "PGDAS-D e DAS", "esfera": "federal", "quando": [{"regime": "SN"}],
     "vencimento": {"meses_apos": 1, "dia": 20, "dia_nao_util": "posterga"}, "tem_guia": true},
    {"codigo": "LP-01", "nome": "IRPJ trimestral", "esfera": "federal", "quando": [{"regime": "LP", "mes_do_trimestre": 3}],
     "vencimento": {"meses_apos": 1, "dia": "ultimo_dia_util"}, "tem_guia": true},
    {"codigo": "LP-02", "nome": "CSLL trimestral", "esfera": "federal", "quando": [{"regime": "LP", "mes_do_trimestre": 3}],
     "vencimento": {"meses_apos": 1, "dia": "ultimo_dia_util"}, "tem_guia": true},
    {"codigo": "LP-03", "nome": "PIS", "esfera": "federal", "quando": [{"regime": "LP"}],
     "vencimento": {"meses_apos": 1, "dia": 25, "dia_nao_util": "antecipa"}, "tem_guia": true},
    {"codigo": "LP-04", "nome": "COFINS", "esfera": "federal", "quando": [{"regime": "LP"}],
     "vencimento": {"meses_apos": 1, "dia": 25, "dia_nao_util": "antecipa"}, "tem_guia": true},
    {"codigo": "LP-05", "nome": "DCTFWeb com MIT", "esfera": "federal", "quando": [{"regime": "LP"}],
     "vencimento": {"meses_apos": 1, "dia": "ultimo_dia_util"}, "tem_guia": false},
    {"codigo": "PJ-02", "nome": "DCTFWeb previdenciária (guia do INSS)", "esfera": "federal", "quando": [{"tem_folha": true}, {"tem_prolabore": true}],
     "vencimento": {"meses_apos": 1, "dia": 20, "dia_nao_util": "antecipa"}, "tem_guia": true},
    {"codigo": "PJ-03", "nome": "IRRF sobre folha (DARF 0561)", "esfera": "federal", "quando": [{"tem_folha": true}],
     "vencimento": {"meses_apos": 1, "dia": 20, "dia_nao_util": "antecipa"}, "tem_guia": true},
    {"codigo": "PJ-04", "nome": "FGTS Digital", "esfera": "federal", "quando": [{"tem_folha": true}],
     "vencimento": {"meses_apos": 1, "dia": 20, "dia_nao_util": "antecipa"}, "tem_guia": true}
  ]
}
$json$::jsonb),
('fechamento.prazos', 1, $json$
{
  "observacao": "PROVISÓRIA. v1 (30/10/2026). Meta = vencimento − 5 dias corridos (posição do Rafael, 07/10); ação = meta − 2 dias úteis; ambas antecipam para dia útil. Quais entregáveis geram multa: pergunta L-3, a validar com Fiscal e DP. Entregável fora desta lista não tem prazo.",
  "padrao": {"meta": [{"dias_corridos": -5}], "acao": [{"dias_uteis": -2}], "estado_cumprimento": "validado"},
  "entregaveis": [
    {"tipo": "E03", "vencimento_ref": ["SN-01", "LP-01", "LP-02", "LP-03", "LP-04"], "gera_multa": true, "esforco_estimado_min": 45},
    {"tipo": "E08", "vencimento_ref": ["PJ-02", "PJ-03", "PJ-04"], "gera_multa": true, "esforco_estimado_min": 20},
    {"tipo": "E10", "vencimento_ref": ["SN-01", "LP-05"], "gera_multa": true, "esforco_estimado_min": 15},
    {"tipo": "E11", "vencimento_ref": ["SN-01", "LP-01", "LP-02", "LP-03", "LP-04", "PJ-02", "PJ-03", "PJ-04"], "gera_multa": true,
     "estado_cumprimento": "disponibilizado", "esforco_estimado_min": 10}
  ]
}
$json$::jsonb)
)
INSERT INTO regras.regra_versao (regra_chave, versao, conteudo, status, autor, aprovador, hash)
SELECT chave, versao, c, 'provisoria', 'adriano', NULL,
       encode(sha256(convert_to(c::text, 'UTF8')), 'hex')
FROM conteudo;

-- ---------------------------------------------------------------------------
-- 3 · Indicadores I7 e I8 (funções: dependem da data de referência)
-- ---------------------------------------------------------------------------
-- Item da regra de prazos do caso para um tipo de entregável (com o padrão aplicado ao estado de cumprimento).
CREATE FUNCTION indicadores.prazo_item(p_regra_prazos uuid, p_tipo text)
RETURNS TABLE (gera_multa boolean, estado_cumprimento text)
LANGUAGE sql STABLE AS $$
    SELECT coalesce((i->>'gera_multa')::boolean, false),
           coalesce(i->>'estado_cumprimento', v.conteudo->'padrao'->>'estado_cumprimento', 'validado')
    FROM regras.regra_versao v
    CROSS JOIN LATERAL jsonb_array_elements(v.conteudo->'entregaveis') i
    WHERE v.id = p_regra_prazos AND i->>'tipo' = p_tipo
$$;

-- Base comum de I7 e I8: entregáveis com prazo, cumprido ou não (estado atual na ordem do
-- caminho principal >= o estado de cumprimento) e o instante do cumprimento.
CREATE VIEW indicadores.entregavel_com_prazo AS
SELECT e.id AS entregavel_id, c.id AS caso_id, c.titular_id, c.competencia, c.carteira, c.estado AS caso_estado,
       e.tipo, e.estado, e.vencimento, e.meta, e.acao, p.gera_multa, p.estado_cumprimento,
       (ea.ordem IS NOT NULL AND ea.ordem >= ec.ordem) AS cumprido,
       (SELECT max(t.em) FROM fechamento.entregavel_transicao t
         WHERE t.entregavel_id = e.id AND t.para_estado = p.estado_cumprimento) AS cumprido_em
FROM fechamento.entregavel e
JOIN fechamento.caso_competencia c ON c.id = e.caso_id
CROSS JOIN LATERAL indicadores.prazo_item(c.regra_prazos_versao_id, e.tipo) p
JOIN fechamento.estado_entregavel ea ON ea.estado = e.estado
JOIN fechamento.estado_entregavel ec ON ec.estado = p.estado_cumprimento
WHERE e.vencimento IS NOT NULL;

-- I7 · Status de prazo por entregável, na data de referência.
-- Não cumprido: atrasado_vencimento (> vencimento), atrasado_meta (> meta), atrasado_acao (> ação),
-- hoje (= ação), ate_3_dias (ação − 3 dias corridos ≤ data < ação), no_prazo.
-- Cumprido (data do cumprimento em America/Sao_Paulo): cumprido_na_meta, cumprido_fora_meta, cumprido_fora_prazo.
CREATE FUNCTION indicadores.i7_status_prazo(data_ref date)
RETURNS TABLE (entregavel_id uuid, titular_id text, competencia char(6), carteira text, tipo text,
               vencimento date, meta date, acao date, cumprido_em timestamptz, faixa text)
LANGUAGE sql STABLE AS $$
    SELECT x.entregavel_id, x.titular_id, x.competencia, x.carteira, x.tipo, x.vencimento, x.meta, x.acao, x.cumprido_em,
           CASE
             WHEN x.cumprido THEN
               CASE WHEN (x.cumprido_em AT TIME ZONE 'America/Sao_Paulo')::date <= x.meta THEN 'cumprido_na_meta'
                    WHEN (x.cumprido_em AT TIME ZONE 'America/Sao_Paulo')::date <= x.vencimento THEN 'cumprido_fora_meta'
                    ELSE 'cumprido_fora_prazo' END
             WHEN data_ref > x.vencimento THEN 'atrasado_vencimento'
             WHEN data_ref > x.meta THEN 'atrasado_meta'
             WHEN data_ref > x.acao THEN 'atrasado_acao'
             WHEN data_ref = x.acao THEN 'hoje'
             WHEN data_ref >= x.acao - 3 THEN 'ate_3_dias'
             ELSE 'no_prazo'
           END
    FROM indicadores.entregavel_com_prazo x
$$;

-- I8 · Multas em risco: entregável que gera multa, não cumprido, de caso aberto, com data_ref ≥ ação.
-- Causa: falta_insumo (aguardando_insumo, invalidado), falta_acao (pronto, processado), divergencia (divergente).
CREATE FUNCTION indicadores.i8_multas_em_risco(data_ref date)
RETURNS TABLE (entregavel_id uuid, titular_id text, competencia char(6), carteira text, tipo text,
               vencimento date, acao date, causa text, impedimento boolean)
LANGUAGE sql STABLE AS $$
    SELECT x.entregavel_id, x.titular_id, x.competencia, x.carteira, x.tipo, x.vencimento, x.acao,
           CASE WHEN x.estado IN ('aguardando_insumo','invalidado') THEN 'falta_insumo'
                WHEN x.estado IN ('pronto','processado') THEN 'falta_acao'
                WHEN x.estado = 'divergente' THEN 'divergencia'
                ELSE x.estado END,
           EXISTS (SELECT 1 FROM trabalho.excecao i
                   WHERE i.entregavel_id = x.entregavel_id AND i.tipo = 'impedimento_externo' AND i.estado = 'aberta')
    FROM indicadores.entregavel_com_prazo x
    WHERE x.gera_multa AND NOT x.cumprido AND x.caso_estado = 'aberto' AND data_ref >= x.acao
$$;
