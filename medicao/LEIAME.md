# Medição comparável dos 3 spikes

`sql_por_operacao.py` é um harness único e independente de linguagem: só fala HTTP com a API do spike, lê o log do Postgres
(`log_statement=all`) e o banco do spike. Resultados e ambiente: `RESULTADOS.md`. Dados brutos: `dados/`.

## Como rodar

1. Extrair a main para uma pasta nova (sem checkout): `git archive origin/main | tar -x -C ./main`.
2. Copiar `compose-medicao.yml` para a pasta pai de `main/` (projeto `vfmedicao`, Postgres 16 próprio na porta 55432,
   apps em 58081 .NET, 58082 Python, 58083 PHP). As imagens são construídas pelo Dockerfile de cada spike, com o código
   embutido, sem bind mount:
   `docker compose -f compose-medicao.yml --profile dotnet build`, depois `up -d postgres migrar spike-dotnet`.
3. Um spike por vez (derrubar o anterior). Dependências do harness: `httpx`, `psycopg[binary]`, CLI `docker`.

```sh
# (i) SQLs por operação, N repetições, banco limpo entre elas
python medicao/sql_por_operacao.py ops --api http://localhost:58081 \
  --db postgresql://spike:spike@localhost:55432/spike_dotnet --pg-container vfmedicao-postgres-1 \
  --app-container vfmedicao-spike-dotnet-1 --label dotnet --reps 3 --out ops-dotnet.json [--dump-sql]
# (ii) C6 isolado (usa o verificador comum)
python medicao/sql_por_operacao.py c6 --api ... --db ... --label dotnet --reps 3 --out c6-dotnet.json \
  --verificador verificador/verificador.py --cenario cenarios/c6-escala-4500-casos.json
# (iii) uma requisição com 10.000 empresas + docker stats a cada 5 s
python medicao/sql_por_operacao.py abrir --api ... --db ... --app-container vfmedicao-spike-dotnet-1 \
  --label dotnet --reps 3 --out abrir-dotnet.json
```

O harness liga `log_statement=all` (ALTER SYSTEM + reload) só durante o `ops` e desliga ao final. O `ops` faz antes uma
sondagem de aquecimento (health, abertura e dois fatos do titular `AQUECIMENTO`) que é descartada da contagem, e depois
repete as operações N vezes truncando as tabelas de execução entre as repetições. Contam-se todas as instruções do banco
do spike, usuário `spike`, de qualquer conexão que não seja a do harness (`application_name=harness`). Depois de cada
operação o harness espera `eventos.fila` esvaziar (consulta direta ao banco, sem passar pela API) e o log ficar estável
por 1,5 s, para que efeitos assíncronos entrem na conta.

## Operações e payloads (idênticos nas 3 linguagens)

Todas sobre o titular `T001`, competência `202609`. Empresa: `{"titular_id":"T001","regime":"SN","tem_folha":true,
"tem_prolabore":true,"tem_taxa_municipal":false,"filial":false,"carteira":"C-01","municipio":"3549805"}` (a mesma do C1).
`observado_em` = `2026-10-01T09:00:00-03:00` em todos os fatos.

| # | Operação | Requisição |
|---|---|---|
| 1 | abrir 1 caso SN com folha | `POST /competencias/abrir` `{"competencia":"202609","ator":"agenda","empresas":[<empresa>]}` |
| 2 | fato que não afeta nada | `POST /fatos` tipo `anotacao_interna` (não aparece em nenhuma regra), fonte `intranet`, `valor_centavos` 1, payload `{"texto":"nota um"}` |
| P | preparação (medida, fora do conjunto de 5) | `POST /fatos` tipo `faturamento_mes`, fonte `intranet`, `valor_centavos` 10000000 |
| 3 | fato que leva o E01 a validado | `POST /fatos` tipo `notas_oneflow`, fonte `oneflow`, `valor_centavos` 10000000 (CF-01 bate com o faturamento) |
| 4 | fato repetido | exatamente o corpo da operação 3 (esperado `sem_mudanca`) |
| 5 | nova versão de fato existente | `POST /fatos` tipo `faturamento_mes`, fonte `intranet`, `valor_centavos` 10000100 (esperado `nova_versao`; invalida o E01) |
| 5b | extra | `anotacao_interna` com payload `{"texto":"nota dois"}` (nova versão de fato sem consumidor) |

Para cada operação o harness confere status 200, o `efeito` esperado e, na operação 3, o E01 em `validado` no banco. A
prova de que a operação 2 não altera nada é um diff do banco antes/depois (linhas por tabela, estados dos entregáveis,
eventos): em todos os spikes só entram 1 linha em `fatos.fato` e 1 evento `fato.publicado`; nenhum estado, transição,
conferência, tarefa, exceção ou fila muda.

Abertura de 10.000 empresas: titulares `M00000`..`M09999`, competência `202610`, todas SN com folha e pró-labore, carteira
`C-01`..`C-20` em rodízio, município `3549805`.
