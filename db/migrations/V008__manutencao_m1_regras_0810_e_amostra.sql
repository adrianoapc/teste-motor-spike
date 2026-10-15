-- V008 · Manutenção M1 (15/10/2026): regras da reunião de 08/10 e da amostra real de 14/10
--
-- Esta migração é o "teste comum de manutenção" das três spikes (docs/regras-do-jogo.md, critério 8).
-- Nada de esquema muda: só regras novas, e o código das spikes tem de acompanhar (semântica v4).
--
--   1. fiscal.tolerancia v2: diferença de 1 a 10 centavos é alerta (A); acima de 10 centavos bloqueia
--      (severidade do catálogo). Reunião de 08/10 com Rafael Groto, Guilherme e Noan.
--   2. fechamento.entregaveis v3:
--      - CF-08 (pró-labore 28%) só roda para SN sujeito ao Fator R (campo novo do snapshot,
--        sujeito_fator_r). Reunião de 08/10.
--      - E08 ganha duas entradas: a folha do mês anterior e a guia de IRRF do mês. A CF-09 passa a
--        conferir o IRRF retido na folha de M-1 com o IRRF declarado na DCTFWeb de M. Amostra real
--        de 14/10 (5 de 5 pares iguais ao centavo).
--   3. A CF-07 do Presumido passa a conferir tributo a tributo (semântica v4 §9.1); isso é código,
--      não regra, e sobe semantica_calculo para 3.
-- Nada é apagado: as versões anteriores ficam registradas como aposentadas.

UPDATE regras.regra_versao
   SET status = 'aposentada'
 WHERE regra_chave IN ('fechamento.entregaveis', 'fiscal.tolerancia')
   AND status IN ('provisoria', 'ativa');

WITH conteudo(chave, versao, c) AS (VALUES
('fechamento.entregaveis', 3, $json$
{
  "observacao": "PROVISÓRIA. v3 (15/10/2026, manutenção M1): regras da reunião de 08/10 e da amostra real de 14/10. CF-08 só para SN sujeito ao Fator R; E08 confere o IRRF da folha do mês anterior com a guia de IRRF do mês. Semântica em docs/semantica-do-motor.md (v4).",
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
     "conferencias": [{"cf": "CF-08", "quando": [{"regime": "SN", "sujeito_fator_r": true}]}]},

    {"tipo": "E08", "executor": "sistema", "quando": [{"tem_folha": true}, {"tem_prolabore": true}],
     "depende_de": [{"tipo": "E06"}, {"tipo": "E07"}],
     "entradas": [
       {"tipo": "guia", "tributo": "INSS"},
       {"tipo": "guia", "tributo": "FGTS", "quando": [{"tem_folha": true}]},
       {"tipo": "folha", "competencia_relativa": -1, "quando": [{"tem_folha": true}]},
       {"tipo": "guia", "tributo": "IRRF", "quando": [{"tem_folha": true}]}
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
$json$::jsonb),
('fiscal.tolerancia', 2, $json$
{
  "observacao": "PROVISÓRIA. v2 (15/10/2026): reunião de 08/10. 1 a 10 centavos = alerta; acima de 10 bloqueia. Liberação acima disso só pela coordenação (override). Valores em centavos.",
  "padrao": {"ok_ate_centavos": 0, "alerta_ate_centavos": 10}
}
$json$::jsonb)
)
INSERT INTO regras.regra_versao (regra_chave, versao, conteudo, status, autor, aprovador, hash)
SELECT chave, versao, c, 'provisoria', 'adriano', NULL,
       encode(sha256(convert_to(c::text, 'UTF8')), 'hex')
FROM conteudo;
