# Regras do jogo: como as duas spikes são comparadas

**v1 · 06/10/2026.** Base: spec do spike v0.3 §4.3 e §4.4 (projeto Rissi).

## 1 · O que é comum e ninguém altera

`db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`, `massa/`, `anonimizacao/`, `docker-compose.yml`, `.kiro/steering/`.

Se uma spike achar erro na base comum, ela **para** e registra em `DUVIDAS.md`. A correção é feita na base, pelo Adriano, e vale para as duas.

## 2 · Condições iguais

- Mesmo agente (Kiro), com o mesmo modelo, para as duas.
- Mesma spec (`.kiro/specs/spike-dotnet` e `.kiro/specs/spike-python` diferem só na pilha).
- Mesmos cenários, mesmo verificador, mesma máquina para medir tempo.
- Medição de escala com uma spike de cada vez (as duas usam o mesmo servidor Postgres).
- Uma spike não lê a pasta da outra.

## 3 · O que é medido

| # | Critério | Como medir | Onde fica |
|---|---|---|---|
| 1 | Correção | Verificador com `--fase N` (regressão das fases anteriores incluída) | saída do verificador (`--relatorio`) |
| 2 | Invariantes | INV-1 a INV-13 do verificador | idem |
| 3 | Indicadores | I1–I5 rodam em < 2 s | idem |
| 4 | Tamanho | Linhas de código e arquivos por camada, sem testes e sem arquivos gerados | `METRICAS.md` |
| 5 | Rede de proteção | Erros barrados pelo compilador ou mypy antes de teste | `REGISTRO-COMPILADOR.md` |
| 6 | Esforço do agente | Tarefas concluídas, retrabalhos, intervenções humanas, tempo de relógio | `METRICAS.md` |
| 7 | Dúvidas | Quantas e de que tipo; quantas viraram invenção sem registro (avaliado na revisão) | `DUVIDAS.md` e revisão |
| 8 | Manutenção | A fase 2 já é um teste de manutenção: a semântica v2 muda regras sobre código pronto (§7.4, §8 item 4, §9.1 CF-09). Medir retrabalho e tempo da fase 2 em separado | `METRICAS.md` (seção Fase 2) |
| 9 | Imagem | Tamanho da imagem e tempo de subida até `/health` responder | `METRICAS.md` |
| 10 | Escala | Cenário C6 (fase 3), uma spike de cada vez | relatório do verificador |

## 4 · Modelo de `METRICAS.md`

```markdown
# Métricas — spike <linguagem>

## Correção
- Verificador (data, commit): C1 [passou|falhou] (n checagens, n falhas); C7 [passou|falhou]
- Invariantes: [todas ok | quais falharam]
- Indicadores (segundos): I1 · I2 · I3 · I4 · I5

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | | |
| aplicacao | | |
| infraestrutura | | |
| api | | |
| Total | | |
| Testes (à parte) | | |

## Esforço do agente
- Tarefas concluídas: n/10
- Vezes que uma tarefa foi refeita: n
- Intervenções humanas (e por quê):
- Tempo de relógio do início ao verificador passando:

## Rede de proteção
- Erros barrados pelo compilador/mypy antes de teste: n (ver REGISTRO-COMPILADOR.md)

## Imagem
- Tamanho da imagem: MB
- Tempo de `up` até `/health` 200: s

## Observações
```

## 5 · Como se decide

- Correção e invariantes são eliminatórios: a spike que não passar não ganha por nenhum outro critério.
- Entre spikes que passam: pesa mais manutenção (8), rede de proteção (5) e tamanho (4), nessa ordem, porque o código vai ser escrito em boa parte por agentes e mantido por pouca gente.
- O resultado vai para o relatório do spike e para o ADR-013 do projeto.
