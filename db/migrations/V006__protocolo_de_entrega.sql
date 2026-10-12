-- V006 · O fechamento termina no protocolo de entrega (decisão de 07/10/2026)
--
-- O pagamento da guia NÃO é parte do fechamento: é sinal para a Regularidade Fiscal.
-- O fechamento termina quando o pacote é disponibilizado ao cliente (App) ou disparado
-- (e-mail): isso é o protocolo. O acompanhamento depois do protocolo (falha, recebimento,
-- lembrete) não reabre o caso; vira exceção com dono (semântica v3, §10.3).
--
-- Esta migração:
--   1. aposenta o estado 'pago' e retira a transição disponibilizado → pago;
--   2. aposenta a regra fechamento.entregaveis v1 e publica a v2 (E12 exige E11 em
--      'disponibilizado'; E11 ganha o bloco 'acompanhamento');
--   3. ajusta nomes do catálogo (E11, CF-17);
--   4. cria o indicador I6 (protocolo e recebimento).
-- Nada é apagado: estado e regra antigos ficam registrados como aposentados.

-- ---------------------------------------------------------------------------
-- 1 · Estado 'pago' aposentado
-- ---------------------------------------------------------------------------
ALTER TABLE fechamento.estado_entregavel
    ADD COLUMN aposentado boolean NOT NULL DEFAULT false;

UPDATE fechamento.estado_entregavel
   SET aposentado = true, ordem = NULL, final = false
 WHERE estado = 'pago';

DELETE FROM fechamento.transicao_permitida
 WHERE de_estado = 'disponibilizado' AND para_estado = 'pago';

-- ---------------------------------------------------------------------------
-- 2 · Regra fechamento.entregaveis: v1 aposentada, v2 em uso
-- ---------------------------------------------------------------------------
UPDATE regras.regra_versao
   SET status = 'aposentada'
 WHERE regra_chave = 'fechamento.entregaveis' AND versao = 1;

WITH conteudo(c) AS (VALUES ($json$
{
  "observacao": "PROVISÓRIA. R-01 não validada com Fiscal e DP. v2 (07/10/2026): o fechamento termina no protocolo de entrega; pagamento é da Regularidade Fiscal. Semântica em docs/semantica-do-motor.md (v3).",
  "estado_minimo_padrao": "validado",
  "entregaveis": [
    {"tipo": "E01", "executor": "sistema", "quando": [],
     "depende_de": [],
     "entradas": [{"tipo": "faturamento_mes"}, {"tipo": "notas_oneflow"}],
     "saida": null,
     "conferencias": [{"cf": "CF-01"}]},

    {"tipo": "E02", "executor": "sistema", "quando": [{"regime": "SN"}],
     "depende_de": [],
     "entradas": [{"tipo": "aliquota_mes"}],
     "saida": null,
     "conferencias": []},

    {"tipo": "E03", "executor": "humano", "quando": [],
     "depende_de": [{"tipo": "E01"}, {"tipo": "E02"}],
     "entradas": [
       {"tipo": "apurado", "tributo": "IRPJ", "competencia_relativa": -1, "quando": [{"regime": "LP", "mes_do_trimestre": 3}]},
       {"tipo": "apurado", "tributo": "IRPJ", "competencia_relativa": -2, "quando": [{"regime": "LP", "mes_do_trimestre": 3}]}
     ],
     "saida": {"tipo": "apurado"},
     "conferencias": [{"cf": "CF-04", "quando": [{"regime": "SN"}]}]},

    {"tipo": "E04", "executor": "sistema", "quando": [],
     "depende_de": [{"tipo": "E03"}],
     "entradas": [{"tipo": "divisao_socios"}],
     "saida": null,
     "conferencias": [{"cf": "CF-07"}]},

    {"tipo": "E05", "executor": "sistema", "quando": [{"tem_taxa_municipal": true}],
     "depende_de": [],
     "entradas": [{"tipo": "taxa_municipal"}],
     "saida": null,
     "conferencias": []},

    {"tipo": "E06", "executor": "humano", "quando": [{"tem_folha": true}],
     "depende_de": [],
     "entradas": [],
     "saida": {"tipo": "folha"},
     "conferencias": []},

    {"tipo": "E07", "executor": "humano", "quando": [{"tem_prolabore": true}],
     "depende_de": [{"tipo": "E03", "quando": [{"regime": "SN"}]}],
     "entradas": [],
     "saida": {"tipo": "prolabore"},
     "conferencias": [{"cf": "CF-08", "quando": [{"regime": "SN"}]}]},

    {"tipo": "E08", "executor": "sistema", "quando": [{"tem_folha": true}, {"tem_prolabore": true}],
     "depende_de": [{"tipo": "E06"}, {"tipo": "E07"}],
     "entradas": [
       {"tipo": "guia", "tributo": "INSS"},
       {"tipo": "guia", "tributo": "FGTS", "quando": [{"tem_folha": true}]}
     ],
     "saida": null,
     "conferencias": [{"cf": "CF-09", "quando": [{"tem_folha": true}]}]},

    {"tipo": "E10", "executor": "sistema", "quando": [],
     "depende_de": [{"tipo": "E03"}],
     "entradas": [
       {"tipo": "recibo_obrigacao", "tributo": "PGDAS-D", "quando": [{"regime": "SN"}]},
       {"tipo": "recibo_obrigacao", "tributo": "DCTFWEB", "quando": [{"regime": "LP"}]}
     ],
     "saida": null,
     "conferencias": [{"cf": "CF-12"}]},

    {"tipo": "E11", "executor": "sistema", "quando": [],
     "depende_de": [{"tipo": "E03"}, {"tipo": "E04"}, {"tipo": "E08"}],
     "entradas": [
       {"tipo": "guia", "tributo": "DAS",  "quando": [{"regime": "SN"}]},
       {"tipo": "guia", "tributo": "IRPJ", "quando": [{"regime": "LP"}]}
     ],
     "saida": null,
     "conferencias": [{"cf": "CF-05"}, {"cf": "CF-06"}],
     "pos_validacao": {
       "liberacao": "automatica",
       "disponibilizado_quando": {"tipo": "documento_disponibilizado"}
     },
     "acompanhamento": {
       "falha":      {"tipo": "entrega_falhou"},
       "confirmacao": {"tipo": "entrega_confirmada"},
       "pendencia":  {"tipo": "recebimento_pendente"}
     }},

    {"tipo": "E12", "executor": "sistema", "quando": [],
     "depende_de": [
       {"tipo": "E01"}, {"tipo": "E02"}, {"tipo": "E03"}, {"tipo": "E04"}, {"tipo": "E05"},
       {"tipo": "E06"}, {"tipo": "E07"}, {"tipo": "E08"}, {"tipo": "E10"},
       {"tipo": "E11", "estado_minimo": "disponibilizado"}
     ],
     "entradas": [],
     "saida": null,
     "conferencias": [],
     "encerra_caso": true}
  ]
}
$json$::jsonb))
INSERT INTO regras.regra_versao (regra_chave, versao, conteudo, status, autor, aprovador, hash)
SELECT 'fechamento.entregaveis', 2, c, 'provisoria', 'adriano', NULL,
       encode(sha256(convert_to(c::text, 'UTF8')), 'hex')
FROM conteudo;

-- ---------------------------------------------------------------------------
-- 3 · Catálogo
-- ---------------------------------------------------------------------------
UPDATE fechamento.entregavel_tipo
   SET nome = 'Protocolo de entrega ao cliente'
 WHERE chave = 'E11';

UPDATE conferencias.conferencia_tipo
   SET nome = 'Pagamento × vencimento (Regularidade Fiscal; fora do fechamento desde 07/10)'
 WHERE chave = 'CF-17';

-- ---------------------------------------------------------------------------
-- 4 · I6 · Protocolo e recebimento por competência e carteira
-- protocolados = E11 em 'disponibilizado'; recebidos = E11 com evento recebimento.confirmado;
-- falhas e pendências = exceções abertas do acompanhamento (semântica v3 §10.3).
-- ---------------------------------------------------------------------------
CREATE VIEW indicadores.i6_protocolo_e_recebimento AS
WITH e11 AS (
    SELECT e.id, c.competencia, c.carteira, e.estado
    FROM fechamento.entregavel e
    JOIN fechamento.caso_competencia c ON c.id = e.caso_id
    JOIN fechamento.entregavel_tipo tp ON tp.chave = e.tipo
    WHERE tp.area = 'entrega'
),
recebidos AS (
    SELECT DISTINCT entregavel_id FROM eventos.evento WHERE nome = 'recebimento.confirmado'
),
abertas AS (
    SELECT entregavel_id,
           count(*) FILTER (WHERE tipo = 'entrega_falhou')       AS falhas,
           count(*) FILTER (WHERE tipo = 'recebimento_pendente') AS pendentes
    FROM trabalho.excecao
    WHERE estado = 'aberta' AND tipo IN ('entrega_falhou', 'recebimento_pendente')
    GROUP BY entregavel_id
)
SELECT e11.competencia,
       e11.carteira,
       count(*)                                                     AS entregas,
       count(*) FILTER (WHERE e11.estado = 'disponibilizado')       AS protocolados,
       count(r.entregavel_id)                                       AS recebidos,
       round(100.0 * count(r.entregavel_id)
             / nullif(count(*) FILTER (WHERE e11.estado = 'disponibilizado'), 0), 1) AS pct_recebido,
       coalesce(sum(a.falhas), 0)                                   AS falhas_abertas,
       coalesce(sum(a.pendentes), 0)                                AS recebimentos_pendentes
FROM e11
LEFT JOIN recebidos r ON r.entregavel_id = e11.id
LEFT JOIN abertas a   ON a.entregavel_id = e11.id
GROUP BY e11.competencia, e11.carteira;
