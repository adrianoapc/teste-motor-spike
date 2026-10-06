-- V002 · Configuração versionada e catálogos
-- Fonte: spec do spike v0.3 §5.1; spec da saga §1.2 e §3.

-- ---------------------------------------------------------------------------
-- regras: políticas versionadas, imutáveis depois de publicadas
-- ---------------------------------------------------------------------------
CREATE TABLE regras.regra (
    chave      text PRIMARY KEY,           -- ex.: 'fechamento.entregaveis'
    descricao  text NOT NULL
);

CREATE TABLE regras.regra_versao (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    regra_chave   text NOT NULL REFERENCES regras.regra(chave),
    versao        integer NOT NULL CHECK (versao > 0),
    conteudo      jsonb NOT NULL,
    -- 'provisoria' = em uso só no spike, sem aprovação da área (R-01 a R-04 não validadas)
    status        text NOT NULL CHECK (status IN ('rascunho','provisoria','aprovada','ativa','aposentada')),
    autor         text NOT NULL,
    aprovador     text,
    hash          text NOT NULL,           -- sha256 do conteúdo normalizado
    publicada_em  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (regra_chave, versao),
    CHECK (status NOT IN ('aprovada','ativa') OR (aprovador IS NOT NULL AND aprovador <> autor))
);

-- No máximo uma versão em uso (provisoria ou ativa) por regra
CREATE UNIQUE INDEX regra_versao_uma_em_uso
    ON regras.regra_versao (regra_chave)
    WHERE status IN ('provisoria','ativa');

CREATE TABLE regras.regra_caso_ouro (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    regra_versao_id  uuid NOT NULL REFERENCES regras.regra_versao(id),
    descricao        text NOT NULL,
    entrada          jsonb NOT NULL,
    saida_esperada   jsonb NOT NULL
);

-- ---------------------------------------------------------------------------
-- fechamento: catálogos do motor
-- ---------------------------------------------------------------------------
CREATE TABLE fechamento.entregavel_tipo (
    chave             text PRIMARY KEY,    -- E01..E12 (catálogo de capacidades §2.2)
    nome              text NOT NULL,
    area              text NOT NULL,
    evento_publicado  text                 -- evento de domínio ao chegar em 'validado'
);

-- Estados do entregável (spec da saga §1.2). `ordem` define "estado >= X" no caminho
-- principal; estados fora do caminho (divergente, invalidado) têm ordem NULL.
-- `final` = a operação concluiu a sua parte (validado em diante). Usado no I5.
CREATE TABLE fechamento.estado_entregavel (
    estado  text PRIMARY KEY,
    ordem   integer UNIQUE,
    final   boolean NOT NULL DEFAULT false
);

-- Transições permitidas. O verificador recusa qualquer transição gravada fora desta lista.
CREATE TABLE fechamento.transicao_permitida (
    de_estado    text REFERENCES fechamento.estado_entregavel(estado),  -- NULL = criação
    para_estado  text NOT NULL REFERENCES fechamento.estado_entregavel(estado),
    descricao    text NOT NULL,
    UNIQUE NULLS NOT DISTINCT (de_estado, para_estado)
);

CREATE TABLE fechamento.calendario (
    id           bigserial PRIMARY KEY,
    abrangencia  text NOT NULL CHECK (abrangencia IN ('nacional','estadual','municipal')),
    codigo       text NOT NULL,            -- 'BR', UF ou código IBGE do município
    data         date NOT NULL,
    tipo         text NOT NULL,            -- 'feriado', 'vencimento:DAS', ...
    UNIQUE (abrangencia, codigo, data, tipo)
);

-- ---------------------------------------------------------------------------
-- conferencias: catálogo CF-01..18 (spec da saga §3)
-- ---------------------------------------------------------------------------
CREATE TABLE conferencias.conferencia_tipo (
    chave             text PRIMARY KEY,    -- 'CF-01'
    nome              text NOT NULL,
    entregavel_tipo   text NOT NULL,       -- dono do confronto
    severidade        text NOT NULL CHECK (severidade IN ('B','A','I')),
    classe_padrao     text NOT NULL CHECK (classe_padrao IN ('S','O')),
    no_spike          boolean NOT NULL,    -- implementado no spike?
    formula_spike     text                 -- descrição exata usada no spike (docs/semantica-do-motor.md §6)
);
