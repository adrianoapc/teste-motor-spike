-- Versão da semântica do cálculo usada na conferência; entra no entrada_hash (§9.2) quando > 1.
-- Aditiva: linhas existentes e inserts dos outros spikes (que não informam a coluna) ficam em 1,
-- o que reproduz exatamente o hash canônico original {"cf","fatos_usados","regra_versao_id"}.
ALTER TABLE conferencias.conferencia
    ADD COLUMN semantica_calculo integer NOT NULL DEFAULT 1 CHECK (semantica_calculo >= 1);
