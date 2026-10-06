-- V005 · Catálogos e regras provisórias do spike
-- ATENÇÃO: as regras abaixo têm status 'provisoria'. Não foram validadas por Fiscal e DP
-- (R-01 a R-07 da spec do spike). Servem para as duas spikes rodarem os mesmos cenários.
-- Semântica completa: docs/semantica-do-motor.md

-- ---------------------------------------------------------------------------
-- Estados e transições permitidas
-- ---------------------------------------------------------------------------
INSERT INTO fechamento.estado_entregavel (estado, ordem, final) VALUES
    ('aguardando_insumo', 1, false),
    ('pronto',            2, false),
    ('processado',        3, false),
    ('validado',          4, true),
    ('liberado',          5, true),
    ('disponibilizado',   6, true),
    ('pago',              7, true),
    ('encerrado',         8, true),
    ('divergente',     NULL, false),
    ('invalidado',     NULL, false);

INSERT INTO fechamento.transicao_permitida (de_estado, para_estado, descricao) VALUES
    (NULL,                'aguardando_insumo', 'criação na abertura do caso'),
    ('aguardando_insumo', 'pronto',            'dependências e entradas satisfeitas'),
    ('pronto',            'processado',        'sistema processou ou tarefa humana concluída'),
    ('processado',        'validado',          'conferências bloqueantes ok'),
    ('processado',        'divergente',        'conferência bloqueante falhou'),
    ('divergente',        'processado',        'nova versão de fato de saída; reconferir'),
    ('divergente',        'validado',          'override com motivo'),
    ('divergente',        'invalidado',        'fato de entrada mudou'),
    ('validado',          'liberado',          'política de liberação cumprida'),
    ('validado',          'encerrado',         'E12: encerramento da competência'),
    ('liberado',          'disponibilizado',   'documento disponibilizado ao cliente'),
    ('disponibilizado',   'pago',              'pagamento confirmado'),
    ('pronto',            'invalidado',        'fato de entrada mudou'),
    ('processado',        'invalidado',        'fato de entrada mudou'),
    ('validado',          'invalidado',        'fato de entrada mudou'),
    ('liberado',          'invalidado',        'fato de entrada mudou antes da entrega'),
    ('invalidado',        'aguardando_insumo', 'reavaliação: falta entrada'),
    ('invalidado',        'pronto',            'reavaliação: entradas presentes');

-- ---------------------------------------------------------------------------
-- Entregáveis (catálogo de capacidades §2.2; E09 e E13 são casos próprios, fora do spike)
-- ---------------------------------------------------------------------------
INSERT INTO fechamento.entregavel_tipo (chave, nome, area, evento_publicado) VALUES
    ('E01', 'Insumos: faturamento e notas',   'fiscal',   'insumos.completos'),
    ('E02', 'Alíquota do mês',                'fiscal',   NULL),
    ('E03', 'Apuração fiscal',                'fiscal',   'apuracao.concluida'),
    ('E04', 'Divisão por sócio',              'fiscal',   NULL),
    ('E05', 'Taxas municipais',               'fiscal',   NULL),
    ('E06', 'Folha CLT',                      'dp',       'folha.fechada'),
    ('E07', 'Pró-labore',                     'dp',       'folha.fechada'),
    ('E08', 'Guias do DP',                    'dp',       'guias.validadas'),
    ('E10', 'Obrigações acessórias',          'fiscal',   NULL),
    ('E11', 'Entrega e acompanhamento',       'entrega',  'guias.validadas'),
    ('E12', 'Encerramento da competência',    'sistema',  NULL);

-- ---------------------------------------------------------------------------
-- Conferências (spec da saga §3). no_spike = implementada nas spikes.
-- ---------------------------------------------------------------------------
INSERT INTO conferencias.conferencia_tipo (chave, nome, entregavel_tipo, severidade, classe_padrao, no_spike, formula_spike) VALUES
    ('CF-01', 'Notas do mês: valor total',                 'E01', 'B', 'O', true,  'esperado = faturamento_mes.valor; obtido = notas_oneflow.valor'),
    ('CF-02', 'Faturamento por sócio',                      'E04', 'B', 'O', false, NULL),
    ('CF-03', 'Notas na fonte oficial × registradas',       'E01', 'A', 'O', false, NULL),
    ('CF-04', 'Apurado por tributo (Simples)',              'E03', 'B', 'S', true,  'esperado = round_half_even(faturamento_mes.valor * aliquota_mes.payload.aliquota_bp / 10000); obtido = apurado[DAS].valor'),
    ('CF-05', 'Guia × apurado',                             'E11', 'B', 'O', true,  'esperado = apurado[tributo da guia].valor; obtido = guia[tributo].valor'),
    ('CF-06', 'Competência impressa da guia',               'E11', 'B', 'O', true,  'ok se guia.payload.competencia_impressa = competência do caso; esperado e obtido nulos; diferenca = {"esperada": competência, "impressa": valor da guia}'),
    ('CF-07', 'Divisão por sócio fecha com o total',        'E04', 'B', 'S', true,  'esperado = apurado[DAS ou IRPJ].valor; obtido = soma(divisao_socios.payload.parcelas[].valor_centavos)'),
    ('CF-08', 'Pró-labore calculado × processado',          'E07', 'B', 'O', true,  'esperado = round_half_even(faturamento_mes.valor * parametros.prolabore_percentual_bp / 10000); obtido = prolabore.valor'),
    ('CF-09', 'Guias do DP × resumo da folha',              'E08', 'B', 'O', true,  'para INSS e FGTS: esperado = folha.payload.<tributo>_centavos; obtido = guia[tributo].valor; diferença = maior das duas'),
    ('CF-10', 'DCTFWeb apurado × a pagar',                  'E08', 'A', 'O', false, NULL),
    ('CF-11', 'Retenções lançadas antes da DCTFWeb',        'E08', 'B', 'O', false, NULL),
    ('CF-12', 'Obrigações entregues × esperadas',           'E10', 'B', 'O', true,  'ok se existe recibo_obrigacao com o tributo exigido; diferença = 0'),
    ('CF-13', 'Dado de alíquota confiável',                 'E02', 'B', 'O', false, NULL),
    ('CF-14', 'Fator R × anexo usado',                      'E03', 'B', 'S', false, NULL),
    ('CF-15', 'Taxa municipal / ISS fixo',                  'E05', 'A', 'O', false, NULL),
    ('CF-16', 'Entrega: esperados × disponibilizados',      'E11', 'A', 'O', false, NULL),
    ('CF-17', 'Pagamento × vencimento',                     'E11', 'A', 'O', false, NULL),
    ('CF-18', 'DAS-MEI',                                    'E11', 'A', 'O', false, NULL);

-- ---------------------------------------------------------------------------
-- Regras provisórias
-- ---------------------------------------------------------------------------
INSERT INTO regras.regra (chave, descricao) VALUES
    ('fechamento.entregaveis', 'C10: entregáveis por empresa, dependências, entradas, conferências e pós-validação'),
    ('fiscal.tolerancia',      'C09: tolerância das conferências em centavos'),
    ('fiscal.parametros',      'Parâmetros de cálculo usados nas conferências do spike');

WITH conteudo(chave, c) AS (VALUES
('fechamento.entregaveis', $json$
{
  "observacao": "PROVISÓRIA. R-01 não validada com Fiscal e DP. Semântica em docs/semantica-do-motor.md.",
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
       "disponibilizado_quando": {"tipo": "documento_disponibilizado"},
       "pago_quando": {"tipo": "guia_paga"}
     }},

    {"tipo": "E12", "executor": "sistema", "quando": [],
     "depende_de": [
       {"tipo": "E01"}, {"tipo": "E02"}, {"tipo": "E03"}, {"tipo": "E04"}, {"tipo": "E05"},
       {"tipo": "E06"}, {"tipo": "E07"}, {"tipo": "E08"}, {"tipo": "E10"},
       {"tipo": "E11", "estado_minimo": "pago"}
     ],
     "entradas": [],
     "saida": null,
     "conferencias": [],
     "encerra_caso": true}
  ]
}
$json$::jsonb),
('fiscal.tolerancia', $json$
{
  "observacao": "PROVISÓRIA. R-03 não validada. Valores em centavos.",
  "padrao": {"ok_ate_centavos": 0, "alerta_ate_centavos": 100}
}
$json$::jsonb),
('fiscal.parametros', $json$
{
  "observacao": "PROVISÓRIA. R-07 em aberto: 28% sobre o faturamento do mês × Fator R legal.",
  "prolabore_percentual_bp": 2800
}
$json$::jsonb)
)
INSERT INTO regras.regra_versao (regra_chave, versao, conteudo, status, autor, aprovador, hash)
SELECT chave, 1, c, 'provisoria', 'adriano', NULL,
       encode(sha256(convert_to(c::text, 'UTF8')), 'hex')
FROM conteudo;
