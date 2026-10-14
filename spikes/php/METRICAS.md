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
