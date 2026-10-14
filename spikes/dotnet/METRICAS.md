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
- Testes automatizados (atualizado em 2026-10-14): **192 de unidade+integração + 3 de arquitetura** (NetArchTest) = 195, todos verdes. (A versão anterior deste arquivo listava 27 unitários + 3 de arquitetura.)

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

---

# Medição pós-merge (2026-10-14)

Origin/main `998cad0`. Os números acima ficam como histórico.

## Ambiente
SDK .NET 10.0; Docker Desktop, 10 CPUs, 7,75 GiB; Postgres próprio para esta spike. Máquina com carga média 2,75–4,1: **não totalmente quieta**.

## Correção
- `dotnet test`: **195/195** (192 unidade+integração, 3 arquitetura).
- Verificador **10/10** em 3 execuções completas (77, 80 e 72 s), sem `AdminShutdown`. O erro da rodada 2 não reapareceu em 6 execuções contra Postgres dedicado; **a causa continua desconhecida** (dica fraca: Postgres sem outros bancos concorrentes).
- Migração V007 aplicada; um INSERT real grava `semantica_calculo=2`.


## Tempo de parede do C6: ordem de grandeza, não comparação
O tempo de parede do C6 só é comparável **dentro da mesma sessão e do mesmo ambiente** (mesma máquina, mesma carga, mesmo modo de montagem do Docker). Não é comparável entre agentes, sessões ou linguagens, e as medições abaixo **não permitem concluir que um spike é mais lento que outro**. Protocolo para a próxima rodada: seção "Protocolo recomendado para o comparativo" do README raiz.

## C6, medições conhecidas do .NET
| Execução | Abertura de 4.500 casos | p95 das 300 publicações |
|---|---|---|
| 14/10, execução 1 | 67,07 s | 0,075 s |
| 14/10, execução 2 | 73,49 s | 0,044 s |
| 14/10, execução 3 | 67,90 s | 0,035 s |

0 erros; 336 checagens; I5 0,176-0,197 s.

- A seção de escala acima registra 76,4 s e p95 0,064 s (sessão anterior). A diferença para 14/10 **NÃO é distinguível de ruído**: ambiente e carga distintos, sem A/B controlado. Não se conclui nada sobre ser mais lento ou mais rápido que outro spike.
- O spike pina 1 core (101-113% CPU, 70-80 MiB). **NÃO PROVADO (hipótese):** 1 core limita a abertura.

## SQLs por operação (antes e depois da refatoração de manutenção)
Métrica confiável: contagem de SQLs por operação com `log_statement=all`, **incluindo `BEGIN`/`COMMIT`**. **Os cenários de medição diferem entre os spikes; por isso as tabelas dos três spikes NÃO são comparáveis linha a linha.**

.NET, cenário: 1 empresa SN com folha.

| Operação | Antes | Depois |
|---|---|---|
| Abrir 1 caso | 112 | 80 |
| Fato de E01 sem pronto | 35 | 20 |
| Fato que deixa E01 pronto e conferido | 91 | 40 |
| Fato de alíquota | 82 | 34 |
| Fato repetido | 6 | 6 |
| Nova versão de fato | 103 | 55 |

## Contagem de testes atual (main)
**234** testes (224 unidade + 10 arquitetura); antes, 195 (192 + 3) em `998cad0`. O texto "27 unitários" mais acima é histórico.

## Limitação do teste de drift da fixture
O teste só compara de fato se `contrato/` estiver visível a partir do diretório de saída dos testes (ele sobe a partir de `AppContext.BaseDirectory`). Se só `spikes/dotnet` for montado, ou dentro do build do Dockerfile (que copia só `src`), o teste **passa SEM comparar**.

## Não medido
Sem A/B controlado contra a medição anterior; sem teste de que 1 core é o limite; causa do `AdminShutdown` não identificada.
