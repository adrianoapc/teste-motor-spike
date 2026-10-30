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

# Manutenção M1 (Fase 4)

> Teste comum de manutenção (`docs/regras-do-jogo.md`, critério 8): a mesma mudança
> (semântica **v4**, migração **V008**, contrato **1.3.0**, cenários **C11–C13**) pedida
> às três spikes. Mede-se tempo de relógio e retrabalho até o verificador passar `--fase 4`
> (13/13). Branch `m1/spike-dotnet`, cortada da `main` (que já traz a base v4: V008 aplicada,
> C11–C13 e o verificador com `--fase 4`).

- **Início da tarefa 19:** 2026-10-15 00:47 -03.
- **Fim (13/13):** 2026-10-15 01:13 -03. **Tempo de relógio: ~26 min.**

## Correção — Fase 4
`python verificador/verificador.py --api http://localhost:8081 --db postgresql://spike:spike@localhost:5432/spike_dotnet --todos cenarios --fase 4 --limpar --relatorio spikes/dotnet/resultado-fase4-spike-dotnet.json`
→ **`RESULTADO: todos passaram (13/13)`** (C1–C13, regressão das fases 1–3 incluída; INV-1 a INV-15 ok).
Relatório em `spikes/dotnet/resultado-fase4-spike-dotnet.json`.

## Tarefa 19 · regressão com as regras novas (código v3, DB v4)
Rodado `--todos cenarios --fase 3 --limpar` com o código **sem alteração** (estado da
fase 3, v3) contra o banco já migrado para **V008** (regras v4). Resultado:
**`RESULTADO: todos passaram (10/10)`** — nenhum cenário da fase 3 quebrou.

Por quê: as mudanças de regra da M1 (tolerância v2, CF-08 só para SN sujeito ao Fator R,
CF-07 do LP tributo a tributo, par IRRF da CF-09) só se manifestam nos cenários **novos**
C11–C13 (fase 4) e no `sujeito_fator_r`, que a fase 3 não exercita. As versões de regra
(`fechamento.entregaveis` v3, `fiscal.tolerancia` v2) são lidas pelo `status` ("em uso"),
e o código já lê por `status` desde a v3 — então o bump da V008 é absorvido de forma
transparente nos cenários antigos (as tolerâncias 1–10 = alerta A não bloqueiam onde antes
já passavam; nenhum caso da fase 3 tem divergência de 1–10 centavos que mudasse de classe).

Impacto da mudança de regra sobre o código existente: **zero regressão**. O trabalho da
fase 4 é todo nos cenários novos (tarefas 20–22). Rodando `--fase 4` sem mudar código: **9/13**
(C11, C12, C13 falham); todo o restante já passava.

## Tarefas 20–22 · o que mudou no código
| Tarefa | Mudança | Arquivos |
|---|---|---|
| 20 (C11) | `semantica_calculo` 2 → 3 (M1.3/M1.4: a semântica do cálculo mudou). O `sujeito_fator_r` e a tolerância v2 já são absorvidos pela regra v3 lida por `status`; a condição do CF-08 (Fator R) vem da regra, não do código | `EntradaHash.cs` |
| 21 (C12) | CF-07 passa a ramificar por regime: **SN** continua agregado (`apurado[DAS]` × soma de todas as parcelas); **LP** vira tributo a tributo — tributos = apurados do caso ∪ tributos das parcelas, par de maior diferença (empate: menor por code point), parcela sem tributo = operando inválido, tributo sem apurado = dado ausente (classe S) | `CalculadoraConferencias.cs` |
| 22 (C13) | CF-09 ganha o 3º par IRRF: INSS/FGTS continuam da folha do **próprio mês** (fatos do caso), IRRF vem da folha do **mês anterior** (entrada do E08 com `competencia_relativa -1`, resolvida em `Conferir`), guia IRRF do mês; par de maior diferença (empate: INSS, FGTS, IRRF); folha M-1 sem `irrf_centavos` = dado ausente (classe S). A invalidação da folha M-1 já atingia o E08 do mês (§11.1) pelo mecanismo de sementes por fato ligado — nada a mudar lá | `CalculadoraConferencias.cs`, `Conferir.cs` |

## Esforço da Fase 4
- **Retrabalhos (reversões de rumo):** 1. A primeira versão da CF-09 lia os **três** pares
  da folha do mês anterior; C2 regrediu (12/13) porque INSS/FGTS vêm da folha do próprio mês
  (a folha M-1 só fornece o IRRF). Corrigido lendo INSS/FGTS de `Buscar(fatos,"folha")` e só o
  IRRF de `FolhaConferencia`. As demais tarefas passaram no primeiro verificador.
- **Intervenções humanas:** 0 (nenhuma dúvida bloqueante; a base comum não foi tocada).
- **Arquivos e linhas (`git diff --stat main -- spikes/dotnet/src spikes/dotnet/tests`):**
  5 arquivos, **+325 / −45**. Só código/teste da spike `dotnet`; produção: `CalculadoraConferencias.cs`
  (+169/−~), `Conferir.cs` (+17), `EntradaHash.cs` (+5/−3). Testes: `DominioVetoresTeste.cs` (+153),
  `RevisaoPosMergeTeste.cs` (±26, ajuste dos vetores de CF-09 e do hash de semântica).
- **Erros barrados pelo compilador antes de teste:** 1 (CS1501, chamada de `Calcular` com 5
  argumentos nos testes de CF-09 — a assinatura recebe uma lista de fatos; corrigido para `new[]{...}`).
  Registrado em `REGISTRO-COMPILADOR.md`.
- **Testes:** 243 (233 unidade + 10 arquitetura), todos verdes; `TreatWarningsAsErrors` sem avisos.

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

---

# Comparação entre spikes: fonte única em `medicao/RESULTADOS.md`

As comparações entre spikes (conjunto FIXO de 5 operações idêntico nas 3 linguagens; C6 isolado N=3; abertura de 10.000 empresas com N=3 em SN pura e em mix SN/LP; diferencial do Python) estão em **`medicao/RESULTADOS.md`**, medidas na mesma sessão. As medições de desempenho (SQLs por operação, C6, abertura de 10.000) usaram imagens com o código embutido, sem bind mount. **Exceção:** o diferencial do Python rodou em `python:3.13-slim` com o código do repositório montado por bind mount (é um teste, não uma medição); as execuções 2 e 3 usaram uma cópia temporária modificada (só a linha `range(n)` trocada por `range(OFFSET, OFFSET + n)`). As tabelas por spike acima ficam como histórico: **antes, os cenários de medição diferiam entre os spikes e elas não são comparáveis linha a linha.** Números copiados de `medicao/RESULTADOS.md` (mediana [mín–máx], N=3), carga média do host (`uptime`, 1 min, antes de cada bloco, 10 CPUs): rodada 1 de 2,89 a 6,43; rodada 2 de 2,75 a 8,46 (valores em `medicao/RESULTADOS.md`):

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

# Manutenção M2 (repetição)

Medição repetida da fase 4.5, branch `m2r/spike-dotnet` (só para medir tempo, não será mergeada). O relógio são os commits `M2R tNN`; a base é o commit `M2R inicio`.

- Início: 2026-10-30 18:47 -03
- Fim: _(preencher na tarefa 30)_

## Retrabalhos (verificador falhou e exigiu mudar código)
- Nenhum até agora.

## Intervenções humanas
- Nenhuma até agora.

## Erros barrados pelo compilador (antes de qualquer teste)
- Nenhum até agora (ver também `REGISTRO-COMPILADOR.md`).

## Medida de impacto da M2 (tarefa 25, código M1 sem mudança)
- `--fase 4 --limpar` → **13/13** (guarda satisfeita: a fase 4 não regride).
- `--fase 4.5 --limpar` → **0/18**. Todos os 18 cenários quebram pela mesma causa: o código M1 nunca grava `fatos.recebimento` nem `tipo_versao`, e a fase 4.5 liga INV-16/17/18 (toda publicação válida precisa deixar um `recebimento` e um `tipo_versao` válido). Exemplo em C1: 28 falhas, todas `[INV-16] fato ... sem recebimento novo/nova_versao`.

## Método desta repetição
Esta medição repetida parte da base limpa `72b7d4e` e **reaplica a implementação v5 já validada** na primeira M2 (branch `m2/spike-dotnet`, commit `c361e93` — código próprio, não de outra spike). Por isso os contadores de retrabalho e de erro barrado pelo compilador ficam em zero: o desenho já estava provado. O que esta repetição mede é o tempo de ponta a ponta de aplicar a mudança v5 com as barreiras do verificador, não a redescoberta do desenho.

## Tarefas (horário do commit = relógio)
| Tarefa | Verificador | Commit | Observação |
|---|---|---|---|
| 25 | fase 4 13/13, fase 4.5 0/18 (impacto) | `M2R t25` | regressão read-only; nenhuma mudança de código |
| 26 | C20 171, C9 86 | `M2R t26` | motor v5 aplicado (contrato §6.2 + violacoes + tipo_versao); dotnet test 258+10, 0 warnings |
| 27 | C19 168, C23 77, C4 119 | `M2R t27` | hash v2 (25 vetores + pares) verdes; `campos_hash` não versiona (C23) |
| 28 | C22 74, C10 154, C3 155 | `M2R t28` | recebimento, obsoleto/conflito_fonte, idempotência (trava por fonte+chave) |
| 29 | C21 318 ×3, C6 isolado | `M2R t29` | trava §13 (`hashtextextended('titular\|'\|\|id,0)`); teste de integração 2/2 contra DB vivo; C6 ver abaixo |
| 30 | fase 4.5 18/18 | _pendente_ | |

### C6 isolado (tarefa 29, só a spike .NET no ar)
| Indicador | fase 4 (11 contêineres no ar) | fase 4.5 isolado |
|---|---|---|
| abertura de 4.500 casos | 32,4 s | **27,3 s** |
| lote de 300 guias (p95) | 0,061 s | **0,032 s** |

Com só a spike .NET no ar (python/php parados), tanto a abertura em massa quanto o p95 do lote caem — a máquina está mais quieta. 0 erros no lote. Tempo de parede continua ordem de grandeza, não medida fina.
