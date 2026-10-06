---
inclusion: always
---

# Produto: spike do motor do fechamento (Rissi Contabilidade)

## O que é

Protótipo **descartável** do motor que fecha a competência mensal de cada empresa cliente de um escritório de contabilidade. Cada empresa × competência é um **caso**. O caso tem **entregáveis** (apuração, folha, guias, entrega…) que dependem uns dos outros e de **fatos** publicados por fontes externas. Conferências comparam valores e bloqueiam o que diverge.

## Por que existe

Decidir, com medida, duas coisas:
1. se o mecanismo "tabela de prontidão própria em Postgres" aguenta a regra de negócio e a escala;
2. qual linguagem é melhor para o núcleo: **.NET 10** ou **Python**.

Por isso existem **duas spikes iguais em comportamento**, uma em cada linguagem, julgadas pelo **mesmo verificador** (`verificador/verificador.py`) com os **mesmos cenários** (`cenarios/*.json`).

## O que conta como sucesso

- O verificador passa nos cenários, sem nenhuma alteração na base comum.
- Código limpo, modular, com testes, e fácil de alterar.
- Medidas registradas em `spikes/<linguagem>/METRICAS.md`.

## Regras de conduta (valem para qualquer tarefa)

1. **A fonte da verdade é `docs/semantica-do-motor.md`.** Se algo não está lá, não invente: registre em `spikes/<linguagem>/DUVIDAS.md` e siga o que está escrito.
2. **Não altere a base comum:** `db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`, `docker-compose.yml`, `.kiro/steering/`. Só leitura.
3. **Trabalhe só na pasta da sua spike** (`spikes/dotnet/` ou `spikes/python/`). **Não leia a pasta da outra spike.** A comparação só vale se as duas forem independentes.
4. **Não crie tabelas, colunas, views ou migrações.** O esquema está pronto em `db/migrations/`. Se faltar algo, registre em `DUVIDAS.md`.
5. **Não use dado real.** Os cenários usam pseudônimos (`T001`, `T002`).
6. **Dinheiro é inteiro em centavos.** Nunca ponto flutuante.
7. Quando terminar uma tarefa, rode os testes e o verificador do cenário correspondente antes de marcar como feita.
