# Métricas — spike Python

Fase 1 (cenários C1 e C7). Modelo: `docs/regras-do-jogo.md` §4.

## Correção
- Verificador (2026-10-07, commit `9645bc3`):
  - **C1** · Feliz: Simples Nacional com folha e pró-labore — **passou** (164 checagens, 0 falhas)
  - **C7** · Lucro Presumido, 3º mês do trimestre — **passou** (85 checagens, 0 falhas)
  - `RESULTADO: todos passaram (2/2)`
- Invariantes: todas ok (INV-1 a INV-9 cobertas pelo verificador nos dois cenários).
- Indicadores (segundos, < 2 s cada): I1≈0.001 · I2≈0.002 · I3≈0.0003 · I4≈0.0005 · I5≈0.002

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 7 | 244 |
| aplicacao | 5 | 847 |
| infraestrutura | 4 | 614 |
| api | 4 | 299 |
| motor/__init__ | 1 | 1 |
| **Total** | **21** | **2005** |
| Testes (à parte) | 2 | 195 |

(Contagem via `find src/motor/<camada> -name '*.py'`, excluindo `src/motor.egg-info/`.)

## Esforço do agente
- Tarefas concluídas: **10/10** (fase 1 completa; fase 2 não iniciada por falta de cenários).
- Vezes que uma tarefa foi refeita: 0 (nenhuma task reaberta; C1 e C7 passaram na primeira
  execução do verificador contra a imagem).
- Intervenções humanas: 0 durante a implementação da fase 1.
- Tempo de relógio do início ao verificador passando (2/2): ~1 sessão de trabalho contínua.

## Rede de proteção
- Erros barrados pelo mypy `--strict` antes de qualquer teste: **2 ocorrências registradas**
  (`unused-ignore` e `arg-type` de tipagem de conexão psycopg) — ver `REGISTRO-COMPILADOR.md`.
- `mypy --strict src` sem nenhum `# type: ignore` em `src/` (os testes têm alguns, para fakes);
  `ruff check` limpo; contrato de camadas verificado por `tests/unidade/test_arquitetura.py`.

## Imagem
- Tamanho da imagem (`teste-motor-spike-spike-python:latest`): **290 MB** (base `python:3.13-slim`).
- Tempo de `up` até `/health` 200 após restart: **~0,7 s**.

## Observações
- Ambiente de desenvolvimento sem `docker compose` v2 (só `docker-compose` v1) e sem Python
  3.13 local; a spike foi construída e testada **dentro de `python:3.13-slim`**, que é a imagem
  do avaliador — mais fiel que um venv local. Detalhes em `DUVIDAS.md` (D1).
- Processamento síncrono dentro da requisição: `/admin/fila` sempre `{"pendentes":0,"com_erro":0}`.
- Nenhuma alteração na base comum (`db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`,
  `docker-compose.yml`, `.kiro/steering/`).

---

# Fase 2 — invalidação, exceções, override e protocolo de entrega

Cenários C2–C5, C8, C9, C10 (fase 2), mais a regressão da fase 1 (C1, C7).

## Correção
- Verificador (2026-10-12, commit `22650d2`, imagem `python:3.13-slim`):
  - `--todos cenarios --fase 2 --limpar` → **`RESULTADO: todos passaram (9/9)`**
    (C1 170 · C2 154 · C3 152 · C4 116 · C5 146 · C7 92 · C8 122 · C9 83 · C10 151 checagens, 0 falhas)
  - `--todos cenarios --fase 1 --limpar` → **2/2** (regressão intacta)
  - Relatório salvo em `resultado-fase2-python.json`.
- Invariantes novas da fase 2 cobertas: INV-14 (`documento.disponibilizado` com `fato_id` da guia)
  e INV-15 (nenhuma transição depois do encerramento).

## Manutenção — mudança de domínio v3 (Task 11) e fase 2 (Tasks 12–16)
Medida pedida em `docs/regras-do-jogo.md` §3, critério 8.

- **Task 11 (mudança de domínio v3, fim no protocolo):** mudança pequena e localizada —
  3 arquivos, +27/−13 linhas (`estados.py`, `motor_fechamento.py`, um teste). A leitura da
  regra em uso já era por `status`, então a troca v1→v2 da V006 não exigiu código novo.
  C1 voltou a 2/2 na primeira execução após a mudança.
- **Fase 2 (Tasks 12–16):** +664/−30 linhas em 10 arquivos sobre a base da fase 1.
  O grosso em `aplicacao/motor_fechamento.py` (+271: invalidação/cascata, override,
  acompanhamento) e `infraestrutura/unidade_de_trabalho.py` (+134: métodos novos de
  exceção/invalidação/override). API cresceu só +21 (uma rota + um modelo).

## Tamanho ao fim da fase 2 (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 7 | 245 |
| aplicacao | 5 | 1152 |
| infraestrutura | 4 | 742 |
| api | 4 | 320 |
| motor/__init__ | 1 | 1 |
| **Total** | **21** | **2460** |
| Testes (à parte) | 3 | 374 |

## Esforço do agente (fase 2)
- Tarefas concluídas: **12–17** (fase 2 completa).
- Vezes que uma tarefa foi refeita: **1 ajuste** — C3 falhou na primeira execução da fase 2
  (8/9) porque a severidade do catálogo era usada no lugar da **computada**; uma divergência A
  de 1 centavo era tratada como bloqueante. Corrigido fazendo `conferir()` devolver
  `(resultado, severidade)` (§9.2). Depois 9/9.
- Intervenções humanas: 0 durante a implementação.

## Rede de proteção (fase 2)
- Erros barrados pelo mypy `--strict` antes de teste: **+3 ocorrências** (`arg-type` em
  `pl` de `gravar_evento`, §10.3) — ver `REGISTRO-COMPILADOR.md`.
- Testes verdes na época (a contagem cresceu nas rodadas de revisão do PR #5; em 2026-10-14: **pytest 289 passando**), `mypy --strict src`
  sem `# type: ignore` em `src/`, `ruff` limpo e contrato de camadas passando.

## Imagem (fase 2)
- Tamanho: **290 MB** (inalterado — nenhuma dependência nova).

---

# Fase 3 — escala

Cenário C6, **só esta spike no ar** (dotnet e php parados; todas compartilham o mesmo Postgres).

## Correção
- Verificador (2026-10-12, commit `73f749d`, imagem `python:3.13-slim`):
  - `--cenario cenarios/c6-escala-4500-casos.json --limpar --relatorio resultado-c6-python.json`
    → **C6 PASSOU** (336 checagens, 0 falhas)
  - `--todos cenarios --fase 3 --limpar` → **`RESULTADO: todos passaram (10/10)`**

## Escala — limites do Requisito 19 vs. medido
| Métrica | Limite | Medido | Folga |
|---|---|---|---|
| Abertura de 4.500 casos (lotes de 500) até `/admin/fila` = `pendentes 0` | < 300 s | **49,2 s** (medição histórica de 12/10, outra sessão/ambiente; o mesmo código mediu 17,6 s a 328 s em outras sessões, ver "Medição pós-merge" abaixo) | folga não comparável entre sessões |
| 300 publicações de guia, 20 requisições simultâneas, p95 | < 30 s | **0,139 s** | ~215× |
| Erros HTTP no lote de fatos | 0 | **0** | — |

Indicadores na escala (segundos): I1≈0,015 · I2≈0,029 · I3≈0,0007 · I4≈0,027 · I5≈0,31 · I6≈0,013.

## Decisão sobre o mecanismo
O processamento **síncrono dentro da requisição** cumpre os dois limites com folga larga
(abertura 6× abaixo do teto, p95 ~215× abaixo). **Não** foi necessário passar para
`eventos.fila` (§13) nem otimizar — `/admin/fila` segue sempre `{"pendentes":0,"com_erro":0}`,
que é a verdade do modelo síncrono. A "tabela de prontidão própria em Postgres" aguentou a
escala do spike sem assincronismo.

## Observação de robustez (registrada em DUVIDAS.md, D8)
Na primeira tentativa do C6 o Postgres reiniciou (pressão de memória do host com as três spikes
no ar) e o pool do spike ficou com conexões mortas — `/health` passou a devolver 500 até o
restart do contêiner. Fora do escopo da medição (o run válido foi com só esta spike no ar e o
banco íntegro), mas aponta um endurecimento barato: `ConnectionPool(check=...)` para o pool
descartar conexão morta sozinho.

---

# Medição pós-merge (2026-10-14)

Origin/main `998cad0`, depois do merge do PR #5. Os valores da fase 3 acima (inclusive 49,2 s) ficam como histórico.

## Ambiente
Docker Desktop, 10 CPUs, 7,75 GiB; Python 3.13.16 (`python:3.13-slim`). Máquina com carga média 2,75–4,1: **não estava totalmente quieta**.

## Correção
- Verificador **10/10**; pytest **289 passando** em `998cad0` (hoje 331, ver abaixo); `mypy --strict` e `ruff` limpos.


## Tempo de parede do C6: ordem de grandeza, não comparação
O tempo de parede do C6 só é comparável **dentro da mesma sessão e do mesmo ambiente** (mesma máquina, mesma carga, mesmo modo de montagem do Docker). Não é comparável entre agentes, sessões ou linguagens, e as medições abaixo **não permitem concluir que um spike é mais lento que outro**. Protocolo para a próxima rodada: seção "Protocolo recomendado para o comparativo" do README raiz.

## C6, medições conhecidas do Python (mesmo código, ambientes diferentes)
| Medição | Abertura de 4.500 casos | Contexto |
|---|---|---|
| 12/10, fase 3 (registro histórico acima) | 49,2 s | sessão/ambiente da fase 3 |
| 12/10, branch do PR | 328,5 s | nunca diagnosticada |
| outras sessões/ambientes | 39,6 s e 17,6 s | bind mount do Docker/Mac vs código embutido na imagem, carga da máquina e containers concorrentes variaram |
| 14/10, execução 1 | 204,29 s (p95 0,191 s) | Docker Desktop, carga média 2,75–4,1 |
| 14/10, execução 2 | 197,68 s (p95 0,186 s) | idem |
| 14/10, execução 3 | 199,33 s (p95 0,199 s) | idem |

0 erros HTTP; 336 checagens nas execuções de 14/10; todas passaram no limite de 300 s dessa sessão. **Causa da variação não determinada; as medições variaram de 17,6 s a 328 s com o mesmo código.**

Diagnósticos de 14/10 (o que se sabe e o que não):
- **PROVADO:** o lock do titular não explica a diferença (desligado em cópia temporária, só diagnóstico: 195,27 / 200,72 / 198,36 s).
- **PROVADO:** nessa sessão o tempo era de espera, não de CPU (spike 0,17%, Postgres 2,4%).
- **Não determinado:** a origem da espera. Hipóteses levantadas antes (fsync no volume do Docker Desktop, salto `host.docker.internal`, trabalho por caso das rodadas de revisão) **não foram provadas e não devem ser lidas como causa**. O código pré-revisão não foi rodado no mesmo ambiente.

## SQLs por operação (antes e depois da refatoração de manutenção)
Métrica confiável: contagem de SQLs por operação com `log_statement=all`, **incluindo `BEGIN`/`COMMIT`**. **Os cenários de medição diferem entre os spikes; por isso as tabelas dos três spikes NÃO são comparáveis linha a linha.**

Python, cenário: titular com 3 casos abertos.

| Operação | Antes | Depois |
|---|---|---|
| Abrir 1 caso | 96 | 83 |
| Fato relevante | 69 | 14 |
| Fato irrelevante | 69 | 10 |
| Fato que leva o E01 a validado | 124 | 37 |
| Fato novo relevante | n/d | 14 |
| Nova versão | n/d | 15 |
| Fato repetido (sem_mudança) | n/d | 4 |
| Fato de competência sem caso | n/d | 9 |

Linhas em `src`: 2.841 -> 2.948 (+107).

## Contagem de testes atual (main)
pytest **331** (o número de 289 acima era de 14/10, em `998cad0`).

---

# Comparação entre spikes: fonte única em `medicao/RESULTADOS.md`

As comparações entre spikes (conjunto FIXO de 5 operações idêntico nas 3 linguagens; C6 isolado N=3; abertura de 10.000 empresas com N=3 em SN pura e em mix SN/LP; diferencial do Python) estão em **`medicao/RESULTADOS.md`**, medidas na mesma sessão, com imagens sem bind mount. As tabelas por spike acima ficam como histórico: **antes, os cenários de medição diferiam entre os spikes e elas não são comparáveis linha a linha.** Números copiados de `medicao/RESULTADOS.md` (mediana [mín–máx], N=3), carga média do host (`uptime`, 1 min, antes de cada bloco, 10 CPUs): rodada 1 de 2,89 a 6,43; rodada 2 de 2,75 a 8,46 (valores em `medicao/RESULTADOS.md`):

| Medida | .NET | PHP | Python |
|---|---|---|---|
| SQLs: abrir 1 caso SN com folha | 79 [79–79] | 94 [94–94] | 85 [85–85] |
| SQLs: fato que não afeta nada | 19 | 34 | 12 |
| SQLs: fato que leva o E01 a validado | 40 | 70 [70–72] | 39 |
| SQLs: fato repetido | 6 | 6 | 6 |
| SQLs: nova versão de fato existente | 50 | 83 [83–85] | 49 |
| C6 isolado: abertura de 4.500 casos (s) | 28.35 [28.20–28.60] | 38.92 [38.45–40.09] | 19.45 [19.15–19.76] |
| C6 isolado: p95 das 300 publicações (s) | 0.030 | 0.075 | 0.050 |
| 10.000 SN puras, 1 requisição (s) | 70.5 [70.1–71.1] | 110.2 [103.9–116.9] | 52.8 [51.9–53.7] |
| 10.000 mix SN/LP, 1 requisição (s) | 108.3 [108.3–125.4] | 143.3 [140.8–146.4] | 74.9 [74.0–105.5] |
| SQLs por empresa, mix de 10.000 (1 passada) | 68.94 | 65.99 | 69.75 |

Diferencial do Python: 0 divergência e 0 não determinismo em 450 sequências (3 execuções de 150).

## Leitura honesta
- O ranking de SQLs muda entre operação isolada e lote: o PHP faz mais SQLs ao abrir 1 caso (94 contra 79 e 85), mas menos por empresa em lote (65,99 contra 68,94 e 69,75). A causa (memo por requisição amortizado em lote) é **hipótese não provada**.
- A anomalia do .NET (108–125 s no mix contra ~70 s em 10.000 SN puras) **não foi investigada**.
- Tempo de parede continua sendo ordem de grandeza: máquina não totalmente quieta, 11 contêineres alheios no ar, cliente no host.
