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
- Testes verdes na época (a contagem cresceu nas rodadas de revisão do PR #5), `mypy --strict src`
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
| Abertura de 4.500 casos (lotes de 500) até `/admin/fila` = `pendentes 0` | < 300 s | **49,2 s** | 6× |
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
