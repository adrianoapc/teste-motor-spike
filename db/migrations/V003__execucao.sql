-- V003 · Execução: casos, entregáveis, transições, fatos, conferências, trabalho, eventos e fila
-- Fonte: spec do spike v0.3 §5.2. Sem FOREIGN KEY entre esquemas (ver V001).
-- Particionamento por competência fica para o ADR-013; no spike, só índices.

-- Tabelas só-inserção recusam UPDATE e DELETE com erro (não em silêncio).
CREATE OR REPLACE FUNCTION public.recusar_alteracao() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'tabela % é só inserção: % não permitido', TG_TABLE_SCHEMA || '.' || TG_TABLE_NAME, TG_OP
        USING ERRCODE = 'restrict_violation';
END;
$$;

-- ---------------------------------------------------------------------------
-- fechamento
-- ---------------------------------------------------------------------------
CREATE TABLE fechamento.caso_competencia (
    id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    titular_id                  text NOT NULL,          -- pseudônimo no spike; CNPJ/CPF em produção
    competencia                 char(6) NOT NULL CHECK (competencia ~ '^[0-9]{4}(0[1-9]|1[0-2])$'),
    snapshot                    jsonb NOT NULL,         -- dados da empresa na abertura (ver contrato)
    carteira                    text NOT NULL,          -- cópia do snapshot, para indicador e RLS
    regra_entregaveis_versao_id uuid NOT NULL,          -- versão de 'fechamento.entregaveis' usada
    estado                      text NOT NULL CHECK (estado IN ('aberto','encerrado','reaberto')),
    version                     integer NOT NULL DEFAULT 1,
    aberto_em                   timestamptz NOT NULL DEFAULT now(),
    encerrado_em                timestamptz,
    UNIQUE (titular_id, competencia)
);

CREATE TABLE fechamento.entregavel (
    id            uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    caso_id       uuid NOT NULL REFERENCES fechamento.caso_competencia(id),
    tipo          text NOT NULL REFERENCES fechamento.entregavel_tipo(chave),
    estado        text NOT NULL REFERENCES fechamento.estado_entregavel(estado),
    executor      text NOT NULL CHECK (executor IN ('sistema','humano')),
    area          text NOT NULL,
    responsavel   text,
    prazo         date,
    version       integer NOT NULL DEFAULT 1,
    estado_desde  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (caso_id, tipo)
);
CREATE INDEX entregavel_estado_idx ON fechamento.entregavel (estado);

-- Histórico de estados (spec do spike v0.3 §5.2). Só inserção.
-- Gravada NA MESMA TRANSAÇÃO que muda fechamento.entregavel.estado.
CREATE TABLE fechamento.entregavel_transicao (
    id                bigserial PRIMARY KEY,               -- define a ordem
    entregavel_id     uuid NOT NULL REFERENCES fechamento.entregavel(id),
    caso_id           uuid NOT NULL REFERENCES fechamento.caso_competencia(id),
    de_estado         text REFERENCES fechamento.estado_entregavel(estado),   -- NULL na criação
    para_estado       text NOT NULL REFERENCES fechamento.estado_entregavel(estado),
    motivo            text NOT NULL,                       -- comando ou evento que causou
    causado_por_tipo  text CHECK (causado_por_tipo IN ('fato','conferencia','tarefa','comando','dependencia')),
    causado_por_id    text,
    ator              text NOT NULL,                       -- 'sistema' ou pessoa
    regra_versao_id   uuid,
    em                timestamptz NOT NULL DEFAULT clock_timestamp()
);
CREATE INDEX entregavel_transicao_entregavel_idx ON fechamento.entregavel_transicao (entregavel_id, id);

CREATE TRIGGER entregavel_transicao_so_insercao
    BEFORE UPDATE OR DELETE ON fechamento.entregavel_transicao
    FOR EACH ROW EXECUTE FUNCTION public.recusar_alteracao();

-- Grafo materializado por caso, para a cascata ser consulta
CREATE TABLE fechamento.entregavel_dependencia (
    entregavel_id   uuid NOT NULL REFERENCES fechamento.entregavel(id),
    depende_de_id   uuid NOT NULL REFERENCES fechamento.entregavel(id),
    estado_minimo   text NOT NULL REFERENCES fechamento.estado_entregavel(estado),
    PRIMARY KEY (entregavel_id, depende_de_id)
);

-- Que fatos cada entregável usou (fato_id sem FK: outro módulo)
CREATE TABLE fechamento.entregavel_fato (
    entregavel_id  uuid NOT NULL REFERENCES fechamento.entregavel(id),
    fato_id        uuid NOT NULL,
    papel          text NOT NULL CHECK (papel IN ('entrada','saida')),
    PRIMARY KEY (entregavel_id, fato_id, papel)
);

-- ---------------------------------------------------------------------------
-- fatos: registro imutável e versionado
-- ---------------------------------------------------------------------------
CREATE TABLE fatos.fato (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    titular_id     text NOT NULL,
    competencia    char(6) NOT NULL CHECK (competencia ~ '^[0-9]{4}(0[1-9]|1[0-2])$'),
    tipo           text NOT NULL,
    tributo        text NOT NULL DEFAULT '',            -- '' quando não se aplica
    versao         integer NOT NULL CHECK (versao > 0),
    valor_centavos bigint,
    payload        jsonb NOT NULL DEFAULT '{}'::jsonb,
    fonte          text NOT NULL,
    origem_ref     text,
    hash           text NOT NULL,                       -- sha256 de valor + payload normalizados
    substitui_id   uuid REFERENCES fatos.fato(id),
    observado_em   timestamptz NOT NULL,
    recebido_em    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (titular_id, competencia, tipo, tributo, versao)
);
CREATE INDEX fato_chave_idx ON fatos.fato (titular_id, competencia, tipo, tributo, versao DESC);

CREATE TRIGGER fato_so_insercao
    BEFORE UPDATE OR DELETE ON fatos.fato
    FOR EACH ROW EXECUTE FUNCTION public.recusar_alteracao();

-- Versão vigente de cada chave
CREATE VIEW fatos.fato_vigente AS
SELECT DISTINCT ON (titular_id, competencia, tipo, tributo) *
FROM fatos.fato
ORDER BY titular_id, competencia, tipo, tributo, versao DESC;

-- ---------------------------------------------------------------------------
-- conferencias
-- ---------------------------------------------------------------------------
CREATE TABLE conferencias.conferencia (
    id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    cf               text NOT NULL REFERENCES conferencias.conferencia_tipo(chave),
    caso_id          uuid NOT NULL,
    entregavel_id    uuid NOT NULL,
    regra_versao_id  uuid NOT NULL,                     -- versão de 'fiscal.tolerancia' usada
    fatos_usados     jsonb NOT NULL,                    -- [{"fato_id":..., "versao":...}]
    entrada_hash     text NOT NULL,                     -- sha256(cf + fatos_usados + regra) -> idempotência
    resultado        text NOT NULL CHECK (resultado IN ('ok','divergente','informativo')),
    esperado_centavos bigint,
    obtido_centavos   bigint,
    diferenca        jsonb NOT NULL DEFAULT '{}'::jsonb,
    severidade       text NOT NULL CHECK (severidade IN ('B','A','I')),
    classe           text NOT NULL CHECK (classe IN ('S','O')),
    override_autor   text,
    override_motivo  text,
    override_em      timestamptz,
    em               timestamptz NOT NULL DEFAULT now(),
    UNIQUE (cf, entregavel_id, entrada_hash),
    CHECK ((override_autor IS NULL) = (override_motivo IS NULL))
);
CREATE INDEX conferencia_entregavel_idx ON conferencias.conferencia (entregavel_id);

-- ---------------------------------------------------------------------------
-- trabalho: exceções e tarefas humanas
-- ---------------------------------------------------------------------------
CREATE TABLE trabalho.excecao (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    caso_id        uuid NOT NULL,
    entregavel_id  uuid,
    tipo           text NOT NULL,      -- 'CF-05', 'insumo_faltante', 'substituicao', 'guia_vencida'...
    classe         text NOT NULL CHECK (classe IN ('S','O')),
    dono           text NOT NULL,      -- carteira (no spike, a carteira do caso)
    prazo          date,
    estado         text NOT NULL CHECK (estado IN ('aberta','resolvida','cancelada')),
    resolucao      text,
    aberta_em      timestamptz NOT NULL DEFAULT now(),
    resolvida_em   timestamptz,
    CHECK ((estado = 'aberta') = (resolvida_em IS NULL))
);
CREATE INDEX excecao_caso_idx ON trabalho.excecao (caso_id);

CREATE TABLE trabalho.tarefa_humana (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    caso_id        uuid NOT NULL,
    entregavel_id  uuid NOT NULL,
    tipo           text NOT NULL,      -- 'executar_entregavel', 'tratar_excecao'
    responsavel    text NOT NULL,      -- carteira no spike
    prazo          date,
    estado         text NOT NULL CHECK (estado IN ('aberta','concluida','cancelada')),
    criada_em      timestamptz NOT NULL DEFAULT now(),
    concluida_em   timestamptz
);
CREATE INDEX tarefa_entregavel_idx ON trabalho.tarefa_humana (entregavel_id, estado);

-- ---------------------------------------------------------------------------
-- eventos: outbox, entregas e fila de trabalho
-- ---------------------------------------------------------------------------
CREATE TABLE eventos.evento (
    id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    nome           text NOT NULL,      -- objeto.fato_no_passado
    versao         integer NOT NULL DEFAULT 1,
    caso_id        uuid,
    entregavel_id  uuid,
    payload        jsonb NOT NULL DEFAULT '{}'::jsonb,   -- só ids, nunca dado pessoal
    ocorrido_em    timestamptz NOT NULL DEFAULT clock_timestamp(),
    despachado_em  timestamptz
);
CREATE INDEX evento_caso_idx ON eventos.evento (caso_id, nome);
CREATE INDEX evento_pendente_idx ON eventos.evento (ocorrido_em) WHERE despachado_em IS NULL;

CREATE TABLE eventos.entrega_evento (
    evento_id    uuid NOT NULL REFERENCES eventos.evento(id),
    assinante    text NOT NULL,
    status       text NOT NULL CHECK (status IN ('pendente','entregue','falhou')),
    tentativas   integer NOT NULL DEFAULT 0,
    ultimo_erro  text,
    PRIMARY KEY (evento_id, assinante)
);

-- Fila de trabalho no próprio Postgres (ADR-014 §3.1, regra 5).
-- Consumo: SELECT ... FOR UPDATE SKIP LOCKED.
CREATE TABLE eventos.fila (
    id             bigserial PRIMARY KEY,
    tipo           text NOT NULL,
    payload        jsonb NOT NULL,
    disponivel_em  timestamptz NOT NULL DEFAULT now(),
    tentativas     integer NOT NULL DEFAULT 0,
    travado_ate    timestamptz,
    ultimo_erro    text,
    criado_em      timestamptz NOT NULL DEFAULT now(),
    concluido_em   timestamptz
);
CREATE INDEX fila_pendente_idx ON eventos.fila (disponivel_em) WHERE concluido_em IS NULL;
