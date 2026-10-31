# Métricas — spike PHP

Modelo em `docs/regras-do-jogo.md` §4. Fase 1 (cenários C1 e C7).

## Correção
- Verificador (2026-10-12, branch spike/php): **C1 passou (170 checagens, 0 falhas); C7 passou (92 checagens, 0 falhas)** — `RESULTADO: todos passaram (2/2)`.
- Invariantes: todas ok (INV-1 a INV-15, embutidas na saída do verificador).
- Indicadores (segundos): I1≈0.001 · I2≈0.001 · I3≈0.0005 · I4≈0.001 · I5≈0.0015 · I6≈0.001 (todos muito abaixo do limite de 2 s).

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 20 | 817 |
| aplicacao | 26 | 1685 |
| infraestrutura | 12 | 887 |
| api | 7 | 285 |
| Providers + rotas | 2 | 79 |
| **Total** | **67** | **3753** |
| Testes (à parte) | 8 | 330 |

## Esforço do agente
- Tarefas concluídas: 10/10 (fase 1).
- Vezes que uma tarefa foi refeita: 0 (o verificador passou 2/2 na primeira execução, sem correção de lógica).
- Intervenções humanas: 1 — o usuário autorizou a execução autônoma até o portão do verificador (sem decidir nada de domínio).
- Tempo de relógio do início ao verificador passando: ~1 h (sessão única, dividida em ciclos de auto-condução).

## Rede de proteção
- Erros barrados pelo PHPStan (nível `max`) antes de teste: ~50, em três levas (ver `REGISTRO-COMPILADOR.md`). Nenhum `@phpstan-ignore`, nenhum baseline. Todos corrigidos antes de rodar testes.

## Imagem
- Tamanho da imagem: ~908 MB (FrankenPHP `1-php8.4` + extensões + vendor).
- Tempo de `/health` responder (contêiner já no ar, modo clássico): ~0.014 s por requisição.

## Observações
- Stack: Laravel 12 / PHP 8.4 / FrankenPHP modo clássico, SQL explícito pela fachada `DB`, sem Eloquent e sem migrações do Laravel.
- O motor passou C1 (fluxo feliz SN completo até o encerramento no protocolo) e C7 (LP, 3º mês do trimestre, com entrada por `competencia_relativa`) sem nenhuma correção de lógica no portão — a semântica v3 foi implementada direto da fonte da verdade.
- Esta spike já nasceu na v3 (fim no protocolo, estado `pago` aposentado, regra v2 em uso pela V006): a fase 1 já cobre os itens 1-4 do Requisito 20.
- Fases 2 (invalidação/exceções/override/acompanhamento) e 3 (escala C6) ficam fora desta entrega.

---

## Fase 2 (cenários C2, C3, C4, C5, C8, C9, C10 + regressão C1, C7)

### Correção
- Verificador (2026-10-12): `--todos cenarios --fase 2 --limpar` → **RESULTADO: todos passaram (9/9)**.
  - C1 (170), C2 (154), C3 (152), C4 (116), C5 (146), C7 (92), C8 (122), C9 (83), C10 (151) checagens, 0 falhas.
- Invariantes INV-1 a INV-15: todas ok. Indicadores I1-I6 < 2 s.
- Passou 9/9 na primeira execução completa, sem correção de lógica.

### Tamanho ao fim da fase 2 (sem testes e sem gerados)
| Camada | Arquivos | Linhas | Δ fase 1 |
|---|---|---|---|
| dominio | 20 | 817 | 0 |
| aplicacao | 29 | 2039 | +3 arq, +354 |
| infraestrutura | 12 | 887 | 0 |
| api | 8 | 317 | +1 arq, +32 |

- A fase 2 acrescentou 3 serviços de aplicação (`Invalidador` §11, `Acompanhar` §10.3, `RegistrarOverride` §8.1) e 1 controller (`OverrideController`). O domínio e a infraestrutura **não mudaram** — os ganchos já existiam na fase 1.

### Esforço — tarefa 11 (v3)
- **Tarefa 11 não se aplica a esta spike.** A mudança de domínio v3 (fim no protocolo, estado `pago` aposentado) já estava feita na fase 1, porque a spike nasceu na v3 com a V006 em uso. A medida de "manutenção da v3" (retrabalho/tempo de absorver a mudança de domínio) só existe nas spikes .NET e Python, que começaram na v1/v2. Para o comparativo de manutenção vale o teste comum do §3 critério 8.

### Esforço — fase 2
- Tarefas concluídas: 11-17 (7/7).
- Vezes que uma tarefa foi refeita: 0 (verificador 9/9 na primeira execução completa).
- Intervenções humanas: 0 de domínio (o usuário só autorizou prosseguir).

### Rede de proteção (fase 2)
- Erros barrados pelo PHPStan antes de teste nesta fase: 2 (nullsafe redundante no `??`, propriedade injetada não lida). Ver `REGISTRO-COMPILADOR.md`.

---

## Fase 3 — escala (cenário C6)

### Correção
- Verificador (2026-10-12, só a spike-php no ar): `--cenario c6 --limpar` → **C6 PASSOU (336 checagens, 0 falhas)**.
- Regressão completa: `--todos cenarios --fase 3 --limpar` → **RESULTADO: todos passaram (10/10)**.
- Totais conferidos: 4.500 casos, **40.306 entregáveis**, 300 fatos `guia[INSS]`.

### Escala (medido, processamento SÍNCRONO, sem fila assíncrona)
| Métrica | Limite | Medido | Margem |
|---|---|---|---|
| Abrir 4.500 casos (lotes de 500) | < 300 s | **82.9 s** | 3,6× |
| 300 guias INSS, 20 requisições simultâneas (p95) | < 30 s | **0.099 s** | ~300× |
| 300 guias — erros HTTP | 0 | **0** | — |
| I1 (entregáveis por estado) | < 2 s | 0.026 s | — |
| I2 (tempo em divergente) | < 2 s | 0.079 s | — |
| I3 (exceções por tipo) | < 2 s | 0.001 s | — |
| I4 (acerto de primeira) | < 2 s | 0.034 s | — |
| I5 (entregáveis travados) | < 2 s | 0.196 s | — |
| I6 (protocolo e recebimento) | < 2 s | 0.013 s | — |

### Observações da fase 3
- **Nenhuma otimização foi necessária.** O processamento síncrono dentro da requisição
  (uma transação por empresa na abertura; `AvaliarCaso` com `SELECT … FOR UPDATE` nos
  entregáveis do caso) cumpriu todos os limites com folga larga, inclusive sob 20
  publicações de guia simultâneas, sem deadlock e sem transição duplicada.
- A fila assíncrona (`eventos.fila`) e o modo worker do FrankenPHP (Octane) ficaram
  **fora de uso** — a medida não mostrou necessidade (Requisito 19.4). `/admin/fila`
  continua devolvendo `pendentes = 0` porque todo o efeito é gravado dentro da
  requisição.
- O ponto mais lento é a abertura (82.9 s / 4.500 = ~18 ms por caso), dominada pela
  transação por empresa com criação de ~9 entregáveis e a prontidão inicial. Está bem
  abaixo do limite, então não houve o que otimizar.

---

## Manutenção M1 (fase 4 — cenários C11, C12, C13 + regressão C1–C10, C6)

- **Hora de início (tarefa 19):** 2026-10-15 03:49 UTC (00:49 -03) — relógio do banco (`select now()` em `spike_php`).
- **Hora de fim (13/13):** 2026-10-15 04:22 UTC (01:22 -03) — relógio do banco. **Tempo de relógio: ~33 min.**
- Branch: `m1/spike-php`, a partir de `origin/main` `3ef6f45` (PR #27: V008, contrato 1.3.0, semântica v4, cenários C11–C13).

### Resumo da manutenção M1

| Medida | Valor |
|---|---|
| Tempo de relógio (tarefa 19 → 13/13) | ~33 min |
| Retrabalhos (verificador rodado e falhou, exigindo correção) | 1 — C11 falhou com `semantica_calculo [2]` esperado `3` depois do primeiro corte da tarefa 20; corrigido no mesmo passo bumpando a constante |
| Intervenções humanas | 0 |
| Erros barrados pelo PHPStan antes de teste | 0 (todas as análises limpas de primeira; nenhuma linha nova em `REGISTRO-COMPILADOR.md`) |
| Arquivos de código/teste alterados | 12 (`git diff --stat origin/main -- spikes/php/app spikes/php/tests spikes/php/.dockerignore`) |
| Linhas | +394 −27 |
| Testes ao fim | 191 passando (835 asserções); Pint sem alteração; PHPStan `max` sem erro |
| `--todos cenarios --fase 4 --limpar` | **13/13** (relatório em `resultado-fase4-php.json`) |

`git diff --stat` da fase (código + testes):

```
 spikes/php/.dockerignore                           |   1 +
 app/Api/Controllers/CompetenciasController.php     |   6 +-
 app/Aplicacao/Conferencias/Conferidor.php          |  15 +-
 app/Aplicacao/Conferencias/FatosDoCaso.php         |  27 +++-
 app/Aplicacao/Conferencias/Formulas.php            | 154 ++++++++++++++++--
 app/Aplicacao/Fechamento/AvaliarCaso.php           |   5 +-
 app/Dominio/Conferencias/HashConferencia.php       |   5 +-
 app/Dominio/Fechamento/Empresa.php                 |  21 +++
 tests/Unidade/EmpresaTest.php                      |  51 +++++++
 tests/Unidade/EstadoECondicaoTest.php              |  13 ++
 tests/Unidade/FormulasTest.php                     | 119 ++++++++++++--
 tests/Unidade/HashTest.php                         |   4 +-
 12 files changed, 394 insertions(+), 27 deletions(-)
```

**Ambiente da execução:** o relatório 13/13 foi medido com os contêineres `spike_dotnet` e `spike_python` de outras sessões também no ar (bancos separados `spike_dotnet`/`spike_python`; não afetam a correção do `spike_php`, só disputam o Postgres compartilhado). Como esta fase mede **tempo e retrabalho até passar**, não desempenho, isso não compromete o resultado; a medida de escala (fase 3) e a comparação entre spikes continuam em `medicao/RESULTADOS.md`.

### Tarefa 19 — regressão com as regras novas (sem mudar código)

Rodado `--todos cenarios --fase 3 --limpar` com o código v3 inalterado contra o banco já na V008 (regras `fechamento.entregaveis` v3 e `fiscal.tolerancia` v2 `provisoria`/em uso).

**Resultado: 9/10. Único cenário quebrado: C1.**

| Cenário | Resultado | Causa |
|---|---|---|
| C1 (fase 1) | **FALHOU** (122 checagens, 55 falhas) | `POST /competencias/abrir` passo 1 → `422 corpo_invalido: "propriedade não declarada em Empresa: sujeito_fator_r"`. O cenário C1 foi atualizado na M1 para enviar o campo novo `sujeito_fator_r`; o DTO `Empresa` v3 do código ainda recusa qualquer campo não declarado, então o caso T001/202609 nunca é criado e as 54 checagens seguintes caem em cascata ("caso/entregável não existe", conferências e eventos a zero). |
| C2–C5, C7–C10, C6 | PASSOU | Não enviam `sujeito_fator_r`. O código v3 lê as regras pelo `status`, então as versões novas (tolerância v2, entregáveis v3) já são as "em uso"; a lógica de cálculo v3 (CF-07 só SN, CF-09 dois pares, tolerância sem faixa de alerta, Fator R) ainda não é exercida por esses cenários. |

**Medida de impacto:** a única regressão da mudança de contrato (M1.2/M1.5) é a rejeição do campo `sujeito_fator_r`. As mudanças de **cálculo** (CF-07 LP tributo a tributo, CF-09 com IRRF M-1, tolerância com faixa de alerta, condição Fator R no CF-08) não aparecem como regressão aqui porque só os cenários da fase 4 (C11, C12, C13) e o C1 (que ganhou o campo) as exercitam — C11/12/13 não entram no `--fase 3`. É o esperado: a fase 4 corrige C1 (tarefa 20) e faz C11–C13 passarem (tarefas 20–22).

### Tarefas 20–23 — as mudanças

| Tarefa | Mudança | Verificador | Resultado |
|---|---|---|---|
| 20 | `Empresa` aceita `sujeito_fator_r` opcional (padrão `false`), propaga ao `Contexto`; a condição do CF-08 vem da regra v3 (`casa()`, nada no código). Bump de `HashConferencia::SEMANTICA_CALCULO` 2→3 (req 21.5, exigido já pelo CF-08 do C11). | C11 | 1/1 |
| 21 | CF-07 do LP tributo a tributo: tributos = apurados ∪ parcela-tributos; par de maior diferença (empate: menor por code point); parcela sem `tributo` = operando inválido (classe S); tributo sem apurado = dado ausente (classe S). SN inalterado. | C12, C8, C5 | 1/1 cada |
| 22 | CF-09 ganha o par IRRF: `folha[competência−1].payload.irrf_centavos` × `guia[IRRF].valor`; maior das três diferenças (empate INSS>FGTS>IRRF). A folha de M-1 é lida por `RepositorioFato::vigente(competência−1)` e injetada no `FatosDoCaso`; a invalidação cross-competência já atinge o E08 do mês (§11.1). | C13, C1, C2 | 1/1 cada |
| 23 | Fechamento: `--todos cenarios --fase 4 --limpar --relatorio resultado-fase4-php.json`. Idempotência (§6 passo 2 v4) coberta pelas checagens `espera.sem_efeito_nos_casos` dos cenários da fase 4, que passam sem nenhuma mudança no motor (o PHP já faz percurso completo idempotente). | `--fase 4` | **13/13** |

A idempotência (req 21.6) não exigiu código novo: o `PublicarFato` já só grava transição/evento/conferência/exceção/tarefa/`version` quando algo muda (hash igual = `sem_mudanca` sem gravação; prontidão/conferência idempotentes por chave), então um fato sem consumidor não produz efeito — exatamente o que `espera.sem_efeito_nos_casos` cobra.

---

## Manutenção M2 (fase 4.5 — cenários C19–C23 + regressão C1–C13, C6)

- **Hora de início (tarefa 25):** 2026-10-28 23:56 UTC (20:56 -03) — relógio do banco (`select now()` em `spike_php`).
- Branch: `m2/spike-php`, a partir de `origin/main` `72b7d4e` (V009, contrato 1.4.0, semântica v5, contratos de tipo de fato, cenários C19–C23).

- **Hora de fim (18/18):** 2026-10-29 01:03 UTC (28/10 22:03 -03) — relógio do banco. **Tempo de relógio: ~1h07 (23:56 → 01:03 UTC).**

### Resumo da manutenção M2

| Medida | Valor |
|---|---|
| Tempo de relógio (tarefa 25 → 18/18) | ~1h07 |
| Retrabalhos (verificador rodado e falhou, exigindo correção no motor) | 1 — C20 falhou com HTTP 500 `properties must be an object`: o esquema do contrato era decodificado com `json_decode(..., true)`, que troca `{}` por `[]` e o opis recusa. Corrigido decodificando o esquema como objeto (`stdClass`) em `RepositorioContratoTipoFatoDb` |
| Intervenções humanas (durante a execução) | 0 — o plano foi dado uma vez no início; nenhuma decisão humana bloqueou a execução das tarefas 25–30 |
| Erros barrados pelo PHPStan antes de teste | 15 (tarefa 26: 11; tarefa 27: 2; tarefa 29: 2) — todos em `REGISTRO-COMPILADOR.md`, nenhum `@phpstan-ignore` nem baseline |
| Testes ao fim | **301 passando** (1398 asserções), suíte inteira (Unidade + Arquitetura + Integração) com `memory_limit=512M`; PHPStan `max` sem erro; Pint limpo |
| `--todos cenarios --fase 4.5 --limpar` | **18/18** (relatório em `resultado-fase45-php.json`), só a spike PHP no ar |

`git diff --stat origin/main` da fase (código + testes; os 15 arquivos novos abaixo são contados à parte por serem *untracked* até o commit):

```
 spikes/php/Dockerfile                              |   5 +-   (intl)
 spikes/php/composer.json                           |   4 +-   (ext-intl, opis/json-schema ^2.4)
 spikes/php/composer.lock                           | 195 ++++  (opis/json-schema 2.6.0 como dep direta)
 app/Api/Controllers/FatosController.php            |   7 +-   (chave_idempotencia, repetido)
 app/Api/ErrosHttp.php                              |   8 +    (violacoes no corpo de erro)
 app/Aplicacao/Fatos/PublicarFato.php               |  58 +-   (núcleo PublicacaoDeFato + idempotência)
 app/Aplicacao/Fatos/PublicarFatoComando.php        |   1 +
 app/Aplicacao/Fatos/ResultadoFato.php              |   3 +-   (repetido)
 app/Aplicacao/Fechamento/ConcluirTarefa.php        |  44 +-   (§8 passo 3a + núcleo)
 app/Aplicacao/Portas/RepositorioFato.php           |   4 +    (porId, tipoVersao)
 app/Aplicacao/Suporte/ErroDeNegocio.php            |  28 +-   (tipo_desconhecido/fonte/payload_invalido)
 app/Dominio/Fatos/Fato.php                         |   5 +    (fonte, observadoEm, tipoVersao)
 app/Infraestrutura/Persistencia/RepositorioCasoDb.php  | 5 +-  (chave do lock §13: 'titular|'||id)
 app/Infraestrutura/Persistencia/RepositorioFatoDb.php  | 35 +  (tipo_versao, porId)
 app/Providers/AppServiceProvider.php               |   6 +    (bind dos novos repositórios)
 tests/Integracao/InfraBancoTest.php                |   6 +-   (base M2: entregaveis v3, tolerância 10)
 tests/Integracao/RevisaoCodexTest.php              | 119 +-   (fonte por tipo, valor obrigatório, §13)
 tests/Integracao/VetoresFatosTest.php              |  48 +-   (recorte v5: forma do corpo)
 tests/Unidade/AcompanharTest.php                   |   4 +-
 tests/Unidade/ConcluirTarefaTest.php               |  12 +-
 tests/Unidade/Fakes/FatosMemoria.php               |  17 +-
 tests/Unidade/Fakes/Mundo.php                      |  22 +-
 22 files changed, 483 insertions(+), 153 deletions(-)
```

Arquivos novos (não aparecem no `--stat` acima até o commit), 1 435 linhas no total:

```
 app/Dominio/Fatos/ContratoTipoFato.php             |  51   app/Dominio/Fatos/Violacao.php                     |  23
 app/Dominio/Fatos/ValidadorContrato.php            | 151   app/Dominio/Fatos/HashFatoV2.php                   | 165
 app/Aplicacao/Portas/RepositorioContratoTipoFato.php | 17  app/Aplicacao/Portas/RepositorioRecebimento.php    |  39
 app/Aplicacao/Fatos/PublicacaoDeFato.php           | 247   app/Aplicacao/Fatos/Publicacao.php                 |  23
 app/Infraestrutura/Persistencia/RepositorioContratoTipoFatoDb.php | 99
 app/Infraestrutura/Persistencia/RepositorioRecebimentoDb.php      |  70
 tests/Unidade/HashV2Test.php                       |  74   tests/Unidade/Fakes/ContratosMemoria.php           |  54
 tests/Unidade/Fakes/RecebimentosMemoria.php        |  52   tests/Integracao/ConcorrenciaTitularTest.php       |  85
 tests/fixtures/vetores-hash-v2.json                | 362  (cópia do contrato, fixture dos testes)
```

### Desempenho — C6 antes e depois da trava por titular (só a spike PHP no ar)

| Medida | Fase 4 (v4, sem trava) | Fase 4.5 (v5, com trava §13 + contrato + recebimento) |
|---|---|---|
| C6 abertura de 4.500 casos | 53,3 s | 48,46 s |
| C6 p95 das 300 publicações em lote | 0,030 s | 0,173 s |
| Abertura de 10.000 empresas numa requisição (SN puras) | 245,1 s (pior caso, host ocupado) | 73,3 s |

Relatórios: `resultado-fase4-php.json` (fase 4) e `resultado-c6-m2-php.json` (C6 isolado da M2).

**Leitura honesta:** o p95 por publicação **sobe** de 0,030 s para 0,173 s na M2 — é o custo esperado do que a M2 acrescenta a cada `/fatos`: a trava consultiva do titular (§13), a leitura do contrato ativo do banco a cada publicação (§6.1, nada em memória), a validação de esquema com `opis/json-schema`, o cálculo do hash v2 e a gravação do recebimento. A abertura em massa não piora (até melhora, dentro do ruído do host). O número de 10.000 (73,3 s contra 245,1 s) **não é comparável**: o 245,1 s da fase 4 foi medido com o host ocupado e é o "pior caso" único; a medida da M2 saiu com carga ~6 (10 CPUs). Tempo de parede continua sendo ordem de grandeza, não comparação fina.

### Concorrência — C21 três execuções seguidas

`--cenario cenarios/c21-m2-concorrencia-por-titular.json --limpar`, três vezes:

| Execução | Resultado |
|---|---|
| 1 | PASSOU (318 checagens, 0 falha) |
| 2 | PASSOU (318 checagens, 0 falha) |
| 3 | PASSOU (318 checagens, 0 falha) |

Teste de integração dedicado (`ConcorrenciaTitularTest`): duas conexões, o mesmo titular serializa (a segunda não obtém `pg_try_advisory_xact_lock` enquanto a primeira segura), titulares diferentes não se bloqueiam, e a chave do lock do spike é idêntica a `hashtextextended('titular|' || titular_id, 0)`.

### Tarefa 25 — regressão sem mudar código

Rodado contra o código v4 inalterado, banco na V009.

- **`--todos cenarios --fase 4 --limpar`: 13/13** (guardrail da tarefa 25 cumprido — a semântica v4 segue intacta; os cenários C1–C13 só mudaram o `observado_em` e a v4 o ignora).
- **`--todos cenarios --fase 4.5 --limpar`: 0/18** — todos os 18 cenários quebram pela **mesma raiz**: a `--fase 4.5` liga as invariantes INV-16/17/18 e as checagens de contrato (C19–C23) que a v4 ainda não atende.

**Medida de impacto da M2** (o que o código v4 não faz e a fase 4.5 cobra):

| Lacuna v4 | Onde (semântica v5) | Como aparece na falha |
|---|---|---|
| Não grava `fatos.recebimento` em nenhuma publicação | §6.4 | INV-16 (todo fato sem recebimento `novo`/`nova_versao`); `recebimentos ...: [], esperado ['novo']` |
| Não grava `tipo_versao` no fato nem no recebimento | §6.1, §6.4 | `vigente ...: tipo_versao None, esperado 1`; INV-17 |
| Não valida o payload contra o contrato de tipo de fato | §6.1, §6.2 | `erro None, esperado 'payload_invalido' / 'tipo_desconhecido' / 'fonte_nao_autorizada'`; saída de `/concluir` grava o fato em vez de recusar (`estado 'validado', esperado 'pronto'`) |
| Hash v1 (sem projeção por `campos_hash`, sem normalização de texto, sem ordenação de arrays) | §6.3 | C19/C23: `efeito 'nova_versao', esperado 'novo'` / versões a mais numa chave |
| Sem efeitos `obsoleto`/`conflito_fonte` nem exceção `conflito_fonte` | §6.4, §9.4 | C22 |
| Sem `chave_idempotencia` nem trava consultiva por titular | §6.5, §13 | C21 |

As tarefas 26–29 fecham essas lacunas; a tarefa 30 fecha com 18/18.

---

# Medição pós-merge (2026-10-14)

Origin/main `998cad0`. Os números acima ficam como histórico.

## Ambiente
PHP 8.4.26, FrankenPHP v1.13.1, Caddy v2.11.7; Docker Desktop, 10 CPUs, 7,75 GiB; Postgres próprio para esta spike. Máquina com carga média 2,75–4,1: **não totalmente quieta**.

## Correção
- Pest **65 passando** (778 asserções), com 123 warnings: **todos** `file_get_contents(/app/.env)` (a imagem não tem `.env`; ruído inofensivo, conferidos 123/123).
- PHPStan sem erros.
- Laravel Pint **falhava** em `bootstrap/app.php`. **Pendência em andamento** (não resolvida neste PR): será corrigida em outro PR, `fix/php-cf12-pint`.
- Verificador **10/10**.


## Tempo de parede do C6: ordem de grandeza, não comparação
O tempo de parede do C6 só é comparável **dentro da mesma sessão e do mesmo ambiente** (mesma máquina, mesma carga, mesmo modo de montagem do Docker). Não é comparável entre agentes, sessões ou linguagens, e as medições abaixo **não permitem concluir que um spike é mais lento que outro**. Protocolo para a próxima rodada: seção "Protocolo recomendado para o comparativo" do README raiz.

## C6, medições conhecidas do PHP
| Medição | Abertura de 4.500 casos | p95 das 300 publicações | Contexto |
|---|---|---|---|
| 12/10, fase 3 (histórico acima) | 82,9 s | 0,099 s | só o spike PHP no ar |
| 14/10 | 86,03 s | 0,102 s | Docker Desktop, carga média 2,75–4,1; 0 erros |

Sem conclusão de que um spike seja mais lento que outro.

## SQLs por operação (antes e depois da refatoração de manutenção)
Métrica confiável: contagem de SQLs por operação com `log_statement=all`, **incluindo `BEGIN`/`COMMIT`**. **Os cenários de medição diferem entre os spikes; por isso as tabelas dos três spikes NÃO são comparáveis linha a linha.**

PHP, cenário: 20 casos abertos numa requisição.

| Medida | Antes | Depois |
|---|---|---|
| SQLs por caso | 104,0 | 80,7 |
| Leituras de `regra_versao` | 100 | 3 |
| Leituras de `entregavel_tipo` | 200 | 10 |
| Leituras de `entregavel_dependencia` | 380 | 200 |
| Fluxo SN completo por HTTP (9 requisições), SQLs | 536 | 490 |

Linhas em `app/`: 4.894 -> 5.429 (+535, +11%). Testes unitários: 477 -> 2.564 linhas; casos de teste: 193 -> 308.

## Contagem de testes atual (main)
Pest **308**; os 65 acima eram de 14/10 em `998cad0`.

## Pior caso contratual
`POST /competencias/abrir` com 10.000 empresas válidas em **uma** requisição: HTTP 200 em **245,1 s**, 10.000 criados, 100.007 entregáveis, ~24,5 ms/empresa. Sem estourar `max_execution_time=900` nem `memory_limit=1024M`. Pico do contêiner 202,7 MiB e 104% CPU; pico do Postgres 151,7 MiB.

## Timeouts do Caddy
- **PROVADO:** o Caddyfile não define nenhum timeout (nem read/write/idle na configuração efetiva, confirmado com `frankenphp adapt`), e uma requisição viveu 245 s sem corte.
- **NÃO PROVADO:** que não haja corte acima de 245 s. Isso se apoia apenas nos padrões documentados do Caddy.

## JSON
`JSON_UNESCAPED_LINE_TERMINATORS` é necessário e suficiente para os vetores U+2028/U+2029 (`JsonCru.php:123`).

## Não medido
Requisições acima de 245 s; ausência de corte do Caddy além desse tempo; Pint corrigido (pendente).

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

## Manutenção M3 (fase 4.6 — cenários C27–C29 + C9 com `motivo_codigo` + regressão C1–C23, C6)

Terceiro teste comum de manutenção (§18): prazos como atributo do entregável, risco de
multa e motivo de catálogo. Migração V010, contrato 1.5.0, semântica v6. O motor continua
sem relógio: tudo que depende de "hoje" recebe `data_ref` de quem chama.

**Início da medição:** 2026-10-31 16:03 -03 (commit vazio `M3 inicio`, `98d9551`). O tempo
ativo da M3 é o dos commits `M3 t32`..`M3 t37` (a tarefa 31, o truncamento do `observado_em`,
fica **fora** da medição, como manda a spec).

### Tarefa 31 — fora da medição (truncamento do `observado_em`, §6.4 v5.1)
- Troca do arredondamento meio-para-o-par por **truncamento** para 6 dígitos de fração, antes
  de comparar e antes de gravar, gravando o valor já truncado (`Instante::truncar`, chamado na
  entrada de `PublicacaoDeFato::publicar`). Assim o Postgres recebe sempre 6 dígitos e nunca
  arredonda (o `.9999996` subiria para o segundo seguinte). Fração com até 9 dígitos é aceita;
  mais de 9 já dava 422 pela regex do controller.
- `InstanteTest` reescrito para os vetores de truncamento; novo teste de `truncar()`.
  `RevisaoCodexTest`: docblock corrigido e novo teste provando que não há carry (`.9999996`
  gravado como `.999999`, não `.000000` do segundo seguinte).
- Portões: PHPStan `max` limpo, Pint limpo, Pest 314/314. Regressão fase 4.5 = **17/18**
  (só o C9 falha, porque agora manda `motivo_codigo`, que é da M3).
- 1 retrabalho interno (bug na reinserção da fração em `truncar()`, que caía no hífen da data
  e gerava `timestamptz` inválido/500; corrigido ao devolver `[base, fração, fuso]`). 4 erros
  do PHPStan barrados antes de teste, em `REGISTRO-COMPILADOR.md`.

### Tarefa 32 — regressão da M3 sem mudar código
`--todos cenarios --fase 4.6 --limpar` logo após o `M3 inicio`, sem tocar no código: **17/21**.
Os quatro que quebram são a medida do impacto da mudança de regra v6:

| Cenário | Efeito | Por que quebra (sem código M3) |
|---|---|---|
| C9 · Override de conferência | FALHOU | O corpo do override agora manda `motivo_codigo`; o `OverrideRequest` recusa a chave não declarada (422 `corpo_invalido`). Falta `motivo_codigo` obrigatório + validação `motivo_invalido` (§8.1/§18.5, tarefa 36). |
| C27 · Prazos na abertura | FALHOU | A abertura não grava `vencimento`/`meta`/`ação` dos entregáveis nem linha em `entregavel_prazo`: as três datas voltam `None`. Falta o cálculo de datas (§18.2) e a gravação na abertura (tarefa 33). |
| C28 · Prorrogação como versão do calendário | FALHOU | Mesma ausência de datas na abertura; e não há `POST /admin/calendario/aplicar` para recalcular na nova versão do calendário (§18.3, tarefa 34). |
| C29 · Risco de multa, impedimento e motivo | FALHOU | `POST /admin/prazos/verificar` não existe (404), e faltam `motivo_codigo`/impedimento (§18.4/§18.5, tarefas 35–36). |

Os 17 que passam (C1–C8, C10–C13, C19–C23, C6) confirmam que a base v5.1 segue íntegra.
