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
- `mypy --strict` sem nenhum `# type: ignore`; `ruff check` limpo; `import-linter` sem violações.

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
