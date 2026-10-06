-- V001 · Esquemas por módulo (ADR-014 §4.2)
-- Comum às duas spikes. Nenhuma spike altera estes arquivos.
-- Regra: um módulo não lê tabela de outro módulo; exceção explícita é o esquema
-- `indicadores` (só leitura). Por isso não há FOREIGN KEY entre esquemas.

CREATE SCHEMA IF NOT EXISTS regras;
CREATE SCHEMA IF NOT EXISTS fechamento;
CREATE SCHEMA IF NOT EXISTS fatos;
CREATE SCHEMA IF NOT EXISTS conferencias;
CREATE SCHEMA IF NOT EXISTS trabalho;
CREATE SCHEMA IF NOT EXISTS eventos;
CREATE SCHEMA IF NOT EXISTS indicadores;

-- A tabela public.migracao_aplicada é criada por db/scripts/migrar.sh.
