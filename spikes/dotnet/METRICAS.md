# Métricas — spike .NET

> Medido em 2026-10-12, branch `spike/dotnet` (commit `2b45e29`), semântica **v3**
> (base comum com a `main` incorporada: V006, cenários C1–C10, verificador por fases).

## Correção
- **Fase 1** (`--fase 1`): C1 **passou**, C7 **passou** — `todos passaram (2/2)`.
- **Fase 2** (`--fase 2`, inclui regressão da fase 1): C1, C2, C3, C4, C5, C7, C8, C9, C10 — `todos passaram (9/9)`.
- **Fase 3** (`--fase 3`, inclui regressão das fases 1 e 2): os 10 cenários C1–C10 — `todos passaram (10/10)`.
- Comando: `python verificador/verificador.py --api http://localhost:8081 --db postgresql://spike:spike@localhost:5432/spike_dotnet --todos cenarios --fase <N> --limpar`.
- Invariantes: todas ok (INV-1 a INV-15, verificadas em cada cenário, 0 falhas).

### Indicadores I1–I6 (segundos) — limite < 2 s
Leitura normal (cenários fora da escala), pior caso observado:

| | I1 | I2 | I3 | I4 | I5 | I6 |
|---|---|---|---|---|---|---|
| típico | ~0,001 | ~0,001 | ~0,0005 | ~0,0006 | ~0,0016 | ~0,001 |

Sob escala (C6, 4.500 casos no banco), pior leitura: I1 0,015 · I2 0,039 · I3 0,001 · I4 0,030 · **I5 0,183** · I6 0,012 — todos bem abaixo de 2 s.

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 9 | 516 |
| aplicacao | 6 | 1259 |
| infraestrutura | 2 | 710 |
| api | 2 | 329 |
| **Total** | **19** | **2814** |
| Testes (à parte) | 2 | 222 |

> Contagem por `find src/Motor.<camada> -name '*.cs'` excluindo `bin/`/`obj/`.
> Arquivos de projeto (`.csproj`, `.sln`, `Directory.Build.props`, `Dockerfile`)
> não entram na contagem de linhas de código. O crescimento vs. a fase 1 (2219 →
> 2814 linhas, +595) é todo da v3: §11 invalidação/cascata, §8.1 override, §10.3
> acompanhamento e os métodos de repositório que eles exigem.

## Esforço do agente
- Tarefas concluídas: **10/10** (fase 1) + fases 2 e 3 (v3) completas.
- Vezes que uma tarefa foi refeita: 0 tarefas inteiras refeitas.
  - Fase 1: 5 erros de compilação (using faltando) + 2 de lógica de runtime (conversão `DateTimeOffset`/`timestamptz`), corrigidos no mesmo fluxo.
  - Review Codex (PR #3): 3 rodadas endereçadas (1 P1 + 12 P2 no total), todas corrigidas e respondidas.
  - v3 (fases 2 e 3): implementação em um fluxo, build limpo de primeira (0 bloqueio novo de compilador).
- Intervenções humanas: 1 — autorização inicial para construir a spec nesta sessão. Nenhuma decisão de domínio precisou de intervenção; todas as regras de borda vieram da config do motor e da semântica.
- Tempo de relógio até o verificador passar: fase 1 em ~1 sessão; a v3 (fases 2 e 3) em uma sessão de implementação contínua, build + testes + verificador em contêiner.

## Rede de proteção
- Erros barrados pelo compilador/`TreatWarningsAsErrors` antes de teste: 6 no total (ver `REGISTRO-COMPILADOR.md`). Build final de cada entrega: **0 avisos, 0 erros**.
- Testes automatizados: **27 unitários** (vetores de arredondamento, hash canônico, `entrada_hash`, competência/virada de ano, mês do trimestre, ordem de estados, condição `quando`, escape de CF-06) + **3 de arquitetura** (NetArchTest). Todos verdes.

## Imagem
- Tamanho da imagem: **371 MB** (`mcr.microsoft.com/dotnet/aspnet:10.0` + app publicado).
- Tempo de `up` até `/health` 200 (restart com Postgres já no ar): **~0,43 s**.

## Escala (C6, fase 3, uma spike de cada vez)
- Abertura em massa: **4.500 casos em 76,4 s** (abertura por empresa, uma transação cada).
- Lote de fatos: **300 fatos, p95 0,064 s por fato, 0 erro(s)**.
- Indicadores sob essa carga seguem < 0,2 s (ver acima).

## Observações
- Processamento síncrono; `/admin/fila` devolve `{"pendentes":0,"com_erro":0}`.
- Dinheiro sempre em centavos (`long`); arredondamento meio-para-o-par só com inteiros, nunca ponto flutuante.
- Hash canônico montado à mão sobre `System.Text.Json` (chaves ordinais, sem espaços, UTF-8 sem escapar acentos).
- v3 implementada: INV-14 (`documento.disponibilizado` com `fato_id` da guia), estado `pago` aposentado (fim no protocolo), §11 invalidação/cascata + `competencia_encerrada`, §8.1 `POST /override`, §10.3 acompanhamento. Ver `DUVIDAS.md` §8–§13.
- Fora do escopo do spike (§14 da semântica): autenticação, RLS, conectores reais, fila assíncrona, reabertura de competência encerrada, resolução das exceções `substituicao`/`competencia_encerrada`, checagem de pagamento (Regularidade Fiscal).
