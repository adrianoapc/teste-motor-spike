-- V004 · Indicadores I1–I5 (spec do spike v0.3 §4.5 e §5.4)
-- Esquema `indicadores`: só leitura, única exceção autorizada à regra de não ler
-- tabela de outro módulo. Nenhuma view expõe CPF, CNPJ ou nome.
-- O verificador mede o tempo de cada consulta com o volume do cenário 6.

-- I1 · Entregáveis por estado e por carteira, agora
CREATE VIEW indicadores.i1_entregaveis_por_estado AS
SELECT c.competencia,
       c.carteira,
       e.tipo,
       e.estado,
       count(*) AS quantidade
FROM fechamento.entregavel e
JOIN fechamento.caso_competencia c ON c.id = e.caso_id
GROUP BY c.competencia, c.carteira, e.tipo, e.estado;

-- I2 · Tempo em 'divergente' por tipo de entregável (só passagens já encerradas)
CREATE VIEW indicadores.i2_tempo_em_divergente AS
WITH passagens AS (
    SELECT t.entregavel_id,
           t.para_estado,
           t.em AS entrou_em,
           lead(t.em) OVER (PARTITION BY t.entregavel_id ORDER BY t.id) AS saiu_em
    FROM fechamento.entregavel_transicao t
)
SELECT e.tipo,
       count(*)                                                         AS passagens,
       avg(p.saiu_em - p.entrou_em)                                     AS tempo_medio,
       percentile_cont(0.9) WITHIN GROUP (ORDER BY extract(epoch FROM p.saiu_em - p.entrou_em))
                                                                        AS p90_segundos
FROM passagens p
JOIN fechamento.entregavel e ON e.id = p.entregavel_id
WHERE p.para_estado = 'divergente'
  AND p.saiu_em IS NOT NULL
GROUP BY e.tipo;

-- I3 · Exceções por tipo (CF-xx ou outro) e classe S/O
CREATE VIEW indicadores.i3_excecoes_por_tipo AS
SELECT x.tipo,
       x.classe,
       count(*) FILTER (WHERE x.estado = 'aberta')    AS abertas,
       count(*) FILTER (WHERE x.estado = 'resolvida') AS resolvidas,
       avg(x.resolvida_em - x.aberta_em) FILTER (WHERE x.estado = 'resolvida') AS tempo_medio_resolucao
FROM trabalho.excecao x
GROUP BY x.tipo, x.classe;

-- I4 · Acerto de primeira: chegou a 'validado' sem passar por 'divergente' nem 'invalidado'
CREATE VIEW indicadores.i4_acerto_de_primeira AS
WITH por_entregavel AS (
    SELECT t.entregavel_id,
           bool_or(t.para_estado = 'validado')                       AS chegou_a_validado,
           bool_or(t.para_estado IN ('divergente','invalidado'))     AS teve_retrabalho
    FROM fechamento.entregavel_transicao t
    GROUP BY t.entregavel_id
)
SELECT e.tipo,
       count(*) FILTER (WHERE p.chegou_a_validado)                              AS validados,
       count(*) FILTER (WHERE p.chegou_a_validado AND NOT p.teve_retrabalho)    AS de_primeira,
       round(100.0 * count(*) FILTER (WHERE p.chegou_a_validado AND NOT p.teve_retrabalho)
             / nullif(count(*) FILTER (WHERE p.chegou_a_validado), 0), 1)       AS pct_de_primeira
FROM por_entregavel p
JOIN fechamento.entregavel e ON e.id = p.entregavel_id
GROUP BY e.tipo;

-- I5 · Entregáveis parados (fora de estado final) e o que os trava.
-- Filtrar por dias_parado na consulta: WHERE dias_parado >= N.
-- Junção agregada (não subconsulta por linha) para escalar com dezenas de milhares de entregáveis.
CREATE VIEW indicadores.i5_entregaveis_travados AS
WITH pendentes AS (
    SELECT dep.entregavel_id,
           array_agg(d2.tipo || ':' || d2.estado ORDER BY d2.tipo) AS dependencias
    FROM fechamento.entregavel_dependencia dep
    JOIN fechamento.entregavel d2          ON d2.id = dep.depende_de_id
    JOIN fechamento.estado_entregavel s_at ON s_at.estado = d2.estado
    JOIN fechamento.estado_entregavel s_mn ON s_mn.estado = dep.estado_minimo
    WHERE s_at.ordem IS NULL OR s_at.ordem < s_mn.ordem
    GROUP BY dep.entregavel_id
)
SELECT c.competencia,
       c.carteira,
       c.titular_id,
       e.tipo,
       e.estado,
       round(extract(epoch FROM now() - e.estado_desde) / 86400.0, 2) AS dias_parado,
       coalesce(p.dependencias, ARRAY[]::text[])                         AS dependencias_pendentes
FROM fechamento.entregavel e
JOIN fechamento.caso_competencia c  ON c.id = e.caso_id
JOIN fechamento.estado_entregavel se ON se.estado = e.estado
LEFT JOIN pendentes p               ON p.entregavel_id = e.id
WHERE NOT se.final;
