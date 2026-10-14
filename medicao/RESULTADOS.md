# Validação final da main e medição comparável (14/10/2026)

Seguindo o "Protocolo recomendado para o comparativo" do README raiz. Código dos spikes e `METRICAS.md` não foram alterados.

## Ambiente (item 6 do protocolo)

- Commit medido: `def1a5ab609451a45a09b3884024cd8a7d484f1a` (origin/main), extraído com `git archive` para pasta nova.
- Docker Desktop 29.8.1 (linuxkit 7.0.14, aarch64), **10 CPUs, 7,748 GiB** de memória, sem limites por contêiner.
- Imagens construídas pelo Dockerfile de cada spike, código embutido, **sem bind mount** nas medições e nos verificadores:
  `vfm-dotnet:def1a5a` (e57ef023a6a4; base aspnet:10.0), `vfm-php:def1a5a` (e9e0d50b013c; base dunglas/frankenphp:1-php8.4),
  `vfm-python:def1a5a` (752350f288d7; base python:3.13-slim). Postgres `postgres:16` (65b16a8b326e), projeto compose próprio
  (`vfmedicao`, porta 55432), migrations V001-V007, `log_statement=all` ligado só no bloco (i).
- Série estrita: .NET, depois PHP, depois Python; só um spike no ar por vez; o anterior foi derrubado antes do seguinte.
- Carga média do host (macOS) lida com `uptime` antes de cada bloco: .NET 3,0 a 4,1; PHP 2,9 a 5,7; Python 3,6 a 6,9
  (os picos ocorrem logo após testes/builds que eu mesmo rodei). Registro completo no fim deste arquivo.
- **A máquina não estava totalmente quieta.** Havia 11 contêineres alheios (stack `supabase_*`, que eu não podia tocar) no ar
  durante todo o trabalho. `docker stats --no-stream` completo foi registrado só no início (soma de CPU dos alheios
  ~26 % de uma CPU, dominada por analytics 10 %, realtime 6,5 %, kong 6 %) e antes do bloco de SQLs do .NET (~22 %); no fim
  estavam em ~2,7 %. **Falha minha:** nos demais blocos registrei só `uptime` e a lista de contêineres (que não mudou), sem
  repetir o `docker stats`. A carga média do host nunca ficou abaixo de 2,9 com 10 CPUs. Isso pode contaminar tempos de
  parede; a contagem de SQLs não é afetada.
- O cliente (harness e verificador) roda no host macOS e acessa o contêiner pela porta publicada, igual nos 3 spikes.

## Parte 1: validação por spike (resultado cru)

| Verificação | .NET | PHP | Python |
|---|---|---|---|
| Testes unitários e de arquitetura | `dotnet test Motor.sln -c Release` (sdk:10.0): Arquitetura 10/10, Unidade 224/224, 0 falhas, 0 ignorados | Pest: **179 passed** (1358 asserções), **129 warnings**; sem falhas | pytest com `MOTOR_DB` real (Postgres 16): **331 passed**, 0 skipped, 1 warning (DeprecationWarning do anyio/starlette) |
| Análise estática | n/a (compilador) | PHPStan nível max: `[OK] No errors`; Pint `--test`: PASS, 135 files | `mypy --strict src`: Success, 24 arquivos; `ruff check`: All checks passed |
| Arquitetura | NetArchTest (dentro dos 10 testes) | testes `tests/Arquitetura` (dentro do Pest) | `lint-imports`: **3 contratos KEPT, 0 broken** (saída abaixo) |
| Verificador `--todos cenarios --limpar` | **10/10** | **10/10** | **10/10** |
| Vetores de hash | 54 vetores de `contrato/vetores-hash.json` (22 aceitos + 32 rejeitados) e 9 de entrada_hash nos testes de unidade (`VetoresHash.cs`, `VetoresPayloadInteirosTeste.cs`) | idem: as fixtures do spike são byte a byte iguais a `contrato/*.json` (`cmp`); o Pest afirma 22 aceitos + 32 rejeitados; 9 de entrada em `HashTest`/`VetoresHashTest` | idem: `test_vetores_hash.py` e `test_vetores_entrada_hash.py` dentro dos 331 |

Os 129 warnings do Pest foram contados com `pest -v`: **129 de 129** têm a mesma mensagem,
`file_get_contents(/app/.env): Failed to open stream: No such file or directory` (a leitura do `.env` pelo Laravel
num contêiner sem `.env`). Nenhum outro tipo de warning; as 3 classes marcadas WARN são `InfraBancoTest`, `RevisaoCodexTest`
e `VetoresFatosTest`. Testes unitários, Pest/pytest e análise estática rodaram em contêiner com bind mount de uma cópia
(permitido); as medições e os verificadores usaram só as imagens construídas.

Saída do `lint-imports` (Python):

```
Analyzed 40 files, 100 dependencies.
Camadas limpas (dominio < aplicacao < infraestrutura < api) KEPT
Dominio nao importa framework nem banco KEPT
Aplicacao nao importa api nem driver de banco KEPT
Contracts: 3 kept, 0 broken.
```

Observação sobre o .NET: não houve falha nem ressalva; a contagem de testes (234) vem da soma dos dois projetos.

## Parte 2: medição

### (i) SQLs por operação (log_statement=all, todas as instruções incl. BEGIN/COMMIT; N=3, mediana [mín–máx])

| Operação | .NET | PHP | Python |
|---|---|---|---|
| 1. abrir 1 caso SN com folha | **79** [79–79] | **94** [94–94] | **85** [85–85] |
| 2. fato que não afeta nada (tipo sem consumidor em nenhuma regra) | **19** [19–19] | **34** [34–34] | **12** [12–12] |
| P. preparação: faturamento_mes (1ª metade do insumo do E01) | **20** [20–20] | **34** [34–34] | **16** [16–16] |
| 3. fato que leva o E01 a validado (notas_oneflow = faturamento_mes) | **40** [40–40] | **70** [70–72] | **39** [39–39] |
| 4. fato repetido (mesmo conteúdo, sem_mudanca) | **6** [6–6] | **6** [6–6] | **6** [6–6] |
| 5. nova versão de fato existente (faturamento_mes 10000000 -> 10000100) | **50** [50–50] | **83** [83–85] | **49** [49–49] |
| 5b. (extra) nova versão do fato que não afeta nada | **19** [19–19] | **33** [33–34] | **13** [13–13] |

#### Composição das instruções (primeira repetição)

| Op | .NET | PHP | Python |
|---|---|---|---|
| 1 | INSERT 43, SELECT 22, UPDATE 11, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 47, INSERT 43, SET 1, BEGIN 1, UPDATE 1, COMMIT 1 | INSERT 43, SELECT 38, OUTRO 1, BEGIN 1, UPDATE 1, COMMIT 1 |
| 2 | SELECT 14, INSERT 2, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 29, INSERT 2, SET 1, BEGIN 1, COMMIT 1 | SELECT 7, INSERT 2, OUTRO 1, BEGIN 1, COMMIT 1 |
| P | SELECT 15, INSERT 2, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 29, INSERT 2, SET 1, BEGIN 1, COMMIT 1 | SELECT 11, INSERT 2, OUTRO 1, BEGIN 1, COMMIT 1 |
| 3 | SELECT 25, INSERT 9, UPDATE 3, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 57, INSERT 9, UPDATE 3, SET 1, BEGIN 1, COMMIT 1 | SELECT 24, INSERT 9, UPDATE 3, OUTRO 1, BEGIN 1, COMMIT 1 |
| 4 | SELECT 3, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 3, SET 1, BEGIN 1, COMMIT 1 | SELECT 3, OUTRO 1, BEGIN 1, COMMIT 1 |
| 5 | SELECT 31, INSERT 11, UPDATE 5, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 66, INSERT 11, UPDATE 5, SET 1, BEGIN 1, COMMIT 1 | SELECT 30, INSERT 11, UPDATE 5, OUTRO 1, BEGIN 1, COMMIT 1 |
| 5b | SELECT 14, INSERT 2, DISCARD 1, BEGIN 1, COMMIT 1 | SELECT 29, INSERT 2, SET 1, BEGIN 1, COMMIT 1 | SELECT 8, INSERT 2, OUTRO 1, BEGIN 1, COMMIT 1 |

#### Prova de que o fato da op 2 não afeta nada (mudanças no banco, repetição 1)

- .NET 10: op 2 -> `{"linhas_novas_por_tabela": {"fatos.fato": 1, "eventos.evento": 1}, "eventos_novos_por_tipo": {"fato.publicado": 1}}`; op 4 (repetido) -> `{}`
- PHP 8.4 / Laravel / FrankenPHP: op 2 -> `{"linhas_novas_por_tabela": {"fatos.fato": 1, "eventos.evento": 1}, "eventos_novos_por_tipo": {"fato.publicado": 1}}`; op 4 (repetido) -> `{}`
- Python 3.13 / FastAPI: op 2 -> `{"linhas_novas_por_tabela": {"fatos.fato": 1, "eventos.evento": 1}, "eventos_novos_por_tipo": {"fato.publicado": 1}}`; op 4 (repetido) -> `{}`

### (ii) C6 isolado (N=3, mediana [mín–máx])

| Spike | Abertura de 4.500 casos (s) | p95 das 300 publicações (s) | Erros | Passou |
|---|---|---|---|---|
| .NET 10 | **28.35** [28.20–28.60] | **0.030** [0.019–0.035] | 0 | 3/3 |
| PHP 8.4 / Laravel / FrankenPHP | **38.92** [38.45–40.09] | **0.075** [0.071–0.079] | 0 | 3/3 |
| Python 3.13 / FastAPI | **19.45** [19.15–19.76] | **0.050** [0.050–0.056] | 0 | 3/3 |

### (iii) POST /competencias/abrir com 10.000 empresas (N=3, mediana [mín–máx])

| Spike | Tempo total (s) | HTTP | Criados / existentes | Resposta (bytes) | CPU app % (média / pico) | Mem app pico (MiB) | CPU Postgres % (média / pico) | Mem Postgres pico (MiB) | Erros / OOM / reinícios |
|---|---|---|---|---|---|---|---|---|---|
| .NET 10 | **70.5** [70.1–71.1] | 200 | 10000/0 | 730029 | 110 / 121 | 126 | 46 / 60 | 162 | 0 linhas de erro / OOM False / 0 |
| PHP 8.4 / Laravel / FrankenPHP | **110.2** [103.9–116.9] | 200 | 10000/0 | 730029 | 31 / 100 | 231 | 45 / 51 | 196 | 0 linhas de erro / OOM False / 0 |
| Python 3.13 / FastAPI | **52.8** [51.9–53.7] | 200 | 10000/0 | 730029 | 40 / 44 | 75 | 41 / 49 | 235 | 0 linhas de erro / OOM False / 0 |

Tempos individuais (s): .NET 10: [70.5, 70.1, 71.1]; PHP 8.4 / Laravel / FrankenPHP: [103.9, 110.2, 116.9]; Python 3.13 / FastAPI: [51.9, 53.7, 52.8]

## Leitura honesta dos números

- **Nada falhou nem estourou limite.** Nos 3 spikes: 10/10 no verificador, C6 3/3, 10.000 empresas em uma requisição com
  HTTP 200, 10.000 criados, 0 existentes, 100.000 entregáveis, sem timeout, sem 500, sem OOM, sem reinício e sem linhas
  de erro/exceção no `docker logs` do app durante a requisição. Cliente com timeout de 1.800 s; a maior execução levou
  116,9 s, por isso N=3 (todas abaixo de 10 min). A resposta tem 730.029 bytes nos três (mesmo conteúdo).
- **A fila ficou sempre vazia** ao fim de cada operação e da abertura de 10.000 (efeitos síncronos na requisição).
- **SQLs por operação**: determinísticos em .NET e Python (min = max). No PHP as operações 3, 5 e 5b variaram em 1 ou 2
  instruções entre repetições (o caso observado: 2 `SELECT` extras de `entregavel` numa repetição de op 3); não investiguei a
  causa, só registrei. A coluna "composição" mostra o que o conjunto contém: cada operação inclui 1 instrução de
  preparação de conexão (.NET `DISCARD ALL`, PHP `set search_path`, Python um *ping* vazio do pool,
  `ConnectionPool.check_connection`), mais `BEGIN` e `COMMIT`. Foram contadas porque o protocolo manda contar todas.
  O fato repetido (op 4) custa 6 nos três: preparação, BEGIN, 2 `pg_advisory_xact_lock`, 1 SELECT e COMMIT.
- A operação 2 "não afeta nada" no sentido de estado do caso: só grava o fato e o evento `fato.publicado` (que todo fato
  grava); não muda estado de entregável, transição, conferência, tarefa, exceção nem fila (diff do banco acima).
- Os tempos de parede (ii) e (iii) desta rodada **não** são comparáveis a rodadas anteriores: o PHP levou 104 a 117 s
  nas 10.000 empresas aqui contra 245,1 s registrado no comentário do Dockerfile do PHP em outra sessão. Dentro desta rodada
  a ordem é estável entre repetições (dispersão de 1 a 12 %), mas há carga alheia de fundo e o cliente roda no host.
- Mix da abertura de 10.000: todas SN com folha e pró-labore (10 entregáveis por caso, 100.000 no total); não há LP no mix.

## O que NÃO foi rodado

- `diferencial_reavaliacao.py` do Python (fora do escopo pedido), `scripts/checar.sh` (equivalente rodado passo a passo).
- Medição de CPU/memória no C6 (pedido só para a abertura de 10.000).
- Latência por operação não é métrica principal; está em `dados/ops-*.json` (tempo só da requisição HTTP).
- Docker stats completo antes de cada bloco (ver "Ambiente").

## Dados brutos

`dados/ops-*.json` (cada repetição, por operação: SQLs, composição por classe, diff do banco, latência HTTP),
`dados/c6-*.json`, `dados/abrir-10k-*.json` (inclui amostras de `docker stats` a cada 5 s). Harness: `sql_por_operacao.py`;
como rodar e payloads: `LEIAME.md`. Compose usado: `compose-medicao.yml`.

## Registro de carga (`uptime`, antes de cada bloco)

| Bloco | Hora | Carga média (1/5/15 min) |
|---|---|---|
| .NET SQLs | 18:38 | 3,03 / 3,46 / 3,52 |
| .NET C6 | 18:40 | 3,91 / 3,57 / 3,55 |
| .NET 10k | 18:41 | 4,14 / 3,70 / 3,60 |
| PHP verificador | 18:48 | 5,74 / 4,44 / 3,91 |
| PHP SQLs | 18:49 | 4,55 / 4,33 / 3,89 |
| PHP C6 | 18:50 | 2,89 / 3,90 / 3,76 |
| PHP 10k | 18:52 | 3,69 / 4,00 / 3,82 |
| Python verificador | 18:58 | 6,43 / 4,85 / 4,17 |
| Python SQLs | 18:59 | 5,35 / 4,73 / 4,15 |
| Python C6 | 19:00 | 4,33 / 4,52 / 4,10 |
| Python 10k | 19:01 | 3,62 / 4,27 / 4,04 |
