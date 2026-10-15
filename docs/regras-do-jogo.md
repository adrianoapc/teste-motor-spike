# Regras do jogo: como as três spikes são comparadas

**v3 · 15/10/2026.** Base: spec do spike v0.7 §4.3 e §4.4 (projeto Rissi). v2: entra a spike PHP. v3: o teste comum de manutenção vira a fase 4 (manutenção M1).

## 1 · O que é comum e ninguém altera

`db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`, `massa/`, `anonimizacao/`, `docker-compose.yml`, `.kiro/steering/`.

Se uma spike achar erro na base comum, ela **para** e registra em `DUVIDAS.md`. A correção é feita na base, pelo Adriano, e vale para todas.

## 2 · Condições iguais

- Mesmo agente (Kiro), com o mesmo modelo, para as três.
- Mesma spec (`.kiro/specs/spike-dotnet`, `spike-python` e `spike-php` diferem só na pilha e, na PHP, no ponto de partida; ver abaixo).
- Mesmos cenários, mesmo verificador, mesma máquina para medir tempo.
- Medição de escala com uma spike de cada vez (todas usam o mesmo servidor Postgres).
- Uma spike não lê a pasta de outra.

**A spike PHP entrou depois (12/10), já com a semântica v3 e a V006.** Ela faz na fase 1 o que as outras fizeram na tarefa 11 (fim no protocolo), então não passa pela mudança de domínio. Consequências:
- No critério 8, a fase 2 dela mede só a v2 e o acompanhamento depois do protocolo; a parte v3 fica "não se aplica".
- Para comparar as três em manutenção, vale o **teste comum de manutenção** (§3, critério 8): a mesma mudança de regra pedida às três depois da fase 2.
- Tempo de relógio e esforço do agente comparam fase com fase, não data com data.

## 3 · O que é medido

| # | Critério | Como medir | Onde fica |
|---|---|---|---|
| 1 | Correção | Verificador com `--fase N` (regressão das fases anteriores incluída) | saída do verificador (`--relatorio`) |
| 2 | Invariantes | INV-1 a INV-15 do verificador | idem |
| 3 | Indicadores | I1–I6 rodam em < 2 s | idem |
| 4 | Tamanho | Linhas de código e arquivos por camada, sem testes e sem arquivos gerados | `METRICAS.md` |
| 5 | Rede de proteção | Erros barrados pelo compilador (.NET), mypy (Python) ou PHPStan (PHP) antes de teste | `REGISTRO-COMPILADOR.md` |
| 6 | Esforço do agente | Tarefas concluídas, retrabalhos, intervenções humanas, tempo de relógio | `METRICAS.md` |
| 7 | Dúvidas | Quantas e de que tipo; quantas viraram invenção sem registro (avaliado na revisão) | `DUVIDAS.md` e revisão |
| 8 | Manutenção | A fase 2 é um teste de manutenção em duas camadas: a semântica v2 muda regras sobre código pronto (§7.4, §8 item 4, §9.1 CF-09) e a **v3 muda o domínio** (fim no protocolo, estado `pago` aposentado, regra trocada de versão pela V006). Medir retrabalho e tempo da fase 2 em separado, e a tarefa 11 (v3) à parte. **Teste comum (as três): fase 4, manutenção M1** (semântica v4 §16, V008, cenários C11 a C13): regras de 08/10 e da amostra real de 14/10, como nova versão de `fiscal.tolerancia` e `fechamento.entregaveis`, mais duas mudanças de cálculo (CF-07 do LP e CF-09 com IRRF). Mede tempo de relógio do início da tarefa 19 até 13/13 no verificador, retrabalhos, intervenções humanas e `git diff --stat`. É a única medida de manutenção que compara as três nas mesmas condições | `METRICAS.md` (seções Fase 2 e Manutenção) |
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
- Erros barrados pelo compilador/mypy/PHPStan antes de teste: n (ver REGISTRO-COMPILADOR.md)

## Imagem
- Tamanho da imagem: MB
- Tempo de `up` até `/health` 200: s

## Observações
```

## 5 · Como se decide

- Correção e invariantes são eliminatórios: a spike que não passar não ganha por nenhum outro critério.
- Entre spikes que passam: pesa mais manutenção (8), rede de proteção (5) e tamanho (4), nessa ordem, porque o código vai ser escrito em boa parte por agentes e mantido por pouca gente.
- O resultado vai para o relatório do spike e para o ADR-013 do projeto.
