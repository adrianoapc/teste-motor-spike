# Cobertura dos cenários

**v2 · 07/10/2026.** O que cada cenário exercita e o que nenhum exercita ainda. Fonte dos cenários: `cenarios/fonte/gerar_cenarios.py`. Regras: semântica v3 (fim no protocolo de entrega) e `fechamento.entregaveis` v2.

## 1 · Cenários

| Cenário | Fase | Empresa | O que exercita | Saga |
|---|---|---|---|---|
| C1 | 1 | SN com folha e pró-labore | Abertura por regra e idempotente; versão em uso da regra (v2); prontidão; tarefas humanas; CF-01/04/05/06/07/08/09/12; pós-validação até o protocolo; encerramento no protocolo | Cenário feliz |
| C7 | 1 | LP sem folha | 3º mês do trimestre: entrada por competência relativa; fato de outra competência reavalia o caso. Para na apuração | Trimestral (achado de 23/09) |
| C8 | 2 | LP sem folha | C7 até o encerramento: CF-07 sobre IRPJ, CF-12 com DCTFWeb, CF-05/06 com guia de IRPJ, protocolo, E12 | Trimestral até o fim |
| C2 | 2 | SN com folha | Folha reaberta depois do pacote protocolado (caso ainda aberto, sem o recibo do PGDAS-D): invalidação de saída de tarefa, cascata E06 → E08, `substituicao` no E11, CF-09 divergente e resolvida, abertura única | R4 |
| C3 | 2 | SN sem folha | Nota cancelada com DAS protocolado: cascata E01 → E03 → E04, `substituicao` no E11, CF-01 divergente até o OneFlow sincronizar, saída nova não invalida o próprio entregável, divergência A não bloqueia | R1 |
| C4 | 2 | SN sem folha | Guia retificada 2 vezes: CF-05 e CF-06 divergentes e resolvidas, só a última versão vale, reenvio idêntico sem efeito | R6 (volta H) |
| C5 | 2 | LP sem folha | Mudança retroativa: caso encerrado não muda e ganha `competencia_encerrada`; caso aberto que usou o fato como entrada relativa é invalidado | R7 |
| C9 | 2 | SN sem folha | Override: 422, 404, 409 (3 situações), override válido, override repetido; depois, invalidação de entregável validado por override e de entregável `pronto` com tarefa | — |
| C10 | 2 | SN sem folha | Encerramento no protocolo; depois dele: `entrega_falhou` com dono sem reabrir, `recebimento_pendente` vindo do agendador (antes do protocolo só grava), reenvio resolve a falha, confirmação gera `recebimento.confirmado` e resolve a pendência, pendência depois da confirmação não faz nada | Acompanhamento da entrega (decisão de 07/10) |
| C6 | 3 | Massa sintética | 4.500 casos em lotes, 300 guias com 20 requisições simultâneas, indicadores com volume | Cenário de escala |

## 2 · Por regra da semântica

| Seção | C1 | C7 | C8 | C2 | C3 | C4 | C5 | C9 | C10 | C6 |
|---|---|---|---|---|---|---|---|---|---|---|
| §5 abertura (inclusive idempotente) | ● | ● | ● | ● | ● | ● | ● | ● | ● | ● |
| §6 fato: novo / sem_mudanca / nova_versao | ●● | ● | ● | ●●● | ●● | ●●● | ●● | ●● | ●●● | ● |
| §7.1 competência relativa | | ● | ● | | | | ● | | | |
| §7.4 passos por volta (invalidado → aguardando → pronto) | | | | ● | ● | | | ● | | |
| §8 saída `sem_mudanca` ligada | | | | ● | | | ● | | | |
| §8 saída nova não invalida o próprio | | | | | ● | | | | | |
| §8.1 override | | | | | | | | ● | | |
| §9.2 divergência A | | | | | ● | | | | | |
| §9.4 abertura única | | | | ● | ● | | | | ● | |
| §9.4 resolução `fato_alterado` | | | | ● | ● | ● | | | | |
| §10.1 / §10.2 entrega e encerramento | ● | | ● | ● | ● | ● | ● | | ● | |
| §11.2 → invalidado | | | | ● | ● | ● | ● | ● | | |
| §11.2 `substituicao` | | | | ● | ● | | | | | |
| §11.2 tarefa cancelada | | | | | | | | ● | | |
| §11.3 caso encerrado | | | | | | | ● | | | |
| §13 concorrência (20 simultâneas) | | | | | | | | | | ● |
| §10.3 acompanhamento (falha, reenvio, confirmação, pendência) | | | | | | | | | ● | |
| §2 versão em uso da regra (v2) | ● | | | | | | | | ● | |
| §10.2 caso encerrado não transiciona (INV-15) | ● | | ● | ● | ● | ● | ● | | ● | |

## 3 · Conferências

| CF | Ok | Divergente B | Divergente A | Override | Cenários |
|---|---|---|---|---|---|
| CF-01 | ● | ● | | ● | C1–C10 / C3, C9 |
| CF-04 | ● | | | | C1, C2, C3, C4 |
| CF-05 | ● | ● | | | C1, C2, C3, C4, C5, C8 |
| CF-06 | ● | ● | | | C1, C2, C3, C4, C5, C8 |
| CF-07 | ● | ● | ● | | C1–C5, C8 |
| CF-08 | ● | | | | C1 |
| CF-09 | ● | ● | | | C1, C2 |
| CF-12 | ● | | | | C1–C5, C8 (PGDAS-D e DCTFWeb) |

## 4 · O que nenhum cenário cobre ainda

| Lacuna | Por quê | Quando cobrir |
|---|---|---|
| R2 (nota externa atrasada), R3 (apuração reaberta no OneFlow), R5 (pró-labore alterado), R8 (regra versionada corrigida), R9 (regime alterado), R10 (retenções depois da DCTFWeb) | Pedem regras e fatos que o spike não modela (versão nova de regra em uso, `empresa.alterada`, Reinf) | Motor de produção, depois do ADR-013 |
| E05 (taxas municipais) e empresa filial | Nenhuma empresa dos cenários tem `tem_taxa_municipal` ou `filial`; a massa do C6 tem taxa municipal, mas o C6 não chega ao E05 | Próximo cenário de fase 2, se a comparação pedir |
| CF-02, 03, 10, 11, 13 a 18 | Não implementadas no spike (`no_spike = false`) | Motor de produção |
| CF-08 divergente | Só o caminho ok | Barato de acrescentar se necessário |
| Fato ausente na conferência (classe S) | Coberto na fase 4: C12 (tributo sem apurado, parcela sem tributo) e C13 (folha sem `irrf_centavos`) | — |
| Precedência entre fontes (tela × conector), `conflito_fonte` em caso encerrado, fila de mortos | Fora da v5 (§17) | Fase 5 e produção |
| Reabertura de competência encerrada | Fora do spike (§14) | Motor de produção |
| Fila assíncrona | O C6 permite síncrono se cumprir os limites | Só se o C6 falhar |

## 5 · Registro

- **07/10/2026, decisão do Adriano:** pagamento de guia não é parte do fechamento. O fechamento termina no protocolo (App ou e-mail disparado); recebimento é indicador, e falha ou falta de leitura depois do encerramento vira exceção com dono, sem reabrir. Aplicado na semântica v3, V006 (`pago` aposentado, regra `fechamento.entregaveis` v2, I6), C1–C5 e C8 sem `guia_paga`, C3 renomeado para `c3-r1-nota-cancelada-das-protocolado.json`, C2 com o recibo do PGDAS-D no fim (para o R4 continuar em caso aberto) e C10 novo. `diferenca_paga` deixou de existir. Validado contra a implementação de referência: fases 1 a 3 passam; mutantes sem `fato_id` no protocolo, sem checagem de confirmação e com versão de regra fixa são reprovados.

- **07/10/2026, observação do Adriano:** o caminho de encerramento do LP (E10 com DCTFWeb, E11 com guia de IRPJ) ia além do que o C7 exercita, porque o C7 para na conclusão do E03. A spike .NET mostrou isso numa exploração manual (`MATRIZ-ENTREGAVEIS.md`, na branch `spike/dotnet`). **Resolvido com o C8**, que continua o C7 até o encerramento, e com o C5, que encerra um LP no 1º mês do trimestre. O C7 foi mantido como está, para não mudar a régua da fase 1.

- **15/10/2026, manutenção M1 (semântica v4, V008):** fase 4 com C11 (tolerância de 10 centavos nos dois lados do limite; CF-08 só com `sujeito_fator_r`), C12 (CF-07 do LP tributo a tributo, com o truncamento do PIS da amostra real, par de maior diferença e dois casos de dado ausente) e C13 (CF-09 com o IRRF da folha do mês anterior, entrada de competência sem caso, folha anterior reaberta invalidando o E08 do mês, dado ausente). Os três publicam fatos sem consumidor com `espera.sem_efeito_nos_casos` (idempotência da §6 passo 2). C1, C2, C5 e C8 ajustados às regras novas (folha do mês anterior e guia de IRRF; parcelas do LP com tributo; `sujeito_fator_r` no C1). Validado contra uma implementação de referência descartável (cópia do spike Python com a M1, fora do repositório): 13/13. O spike Python da main, sem a M1, passa em C2, C5 e C8 e reprova C1, C11, C12 e C13 nos pontos esperados (campo novo recusado, CF-07 somando tudo, CF-09 sem IRRF, `semantica_calculo` 2).

- **27/10/2026, manutenção M2 (semântica v5, V009):** fase 4.5 com C19 (hash normalizado: ordem de parcelas, rastro fora do hash, espaços, NFC, nulo = ausente; controle no vencimento invalida o E11 liberado), C20 (contrato na entrada: 10 rejeições sem gravar nada, inclusive saída de tarefa), C21 (5 titulares, 90 publicações embaralhadas com 20 em paralelo, três versões fora de ordem por chave, chave de idempotência repetida), C22 (`obsoleto`, `conflito_fonte` com exceção única, outra fonte no mesmo instante via contrato de teste) e C23 (troca de `campos_hash` sem versão nem cascata). C1 a C13 só mudaram o `observado_em` para o instante do envio (`"$agora"`): eram 14 passos em 8 cenários com o mesmo `observado_em` e a mesma fonte, que na v5 seriam `conflito_fonte`. Validado contra uma implementação de referência descartável (cópia do spike Python com a M2, fora do repositório): 18/18, e o C21 estável em três execuções seguidas. O spike Python da main, sem a M2, segue em 13/13 com `--fase 4` e reprova C19 a C23 com `--fase 4.5` (sem recebimento, sem validação de contrato, `chave_idempotencia` recusada, vigente errada quando a ordem de chegada muda).

- **30/10/2026, v5.1 (revisão da M2):** os três PRs da fase 4.5 (#32 a #34) tiveram achados de revisão depois do 18/18. Dois apareceram nas três linguagens, o que é lacuna da base, não da spike: `required` múltiplo (o C20 só tinha um campo ausente por vez) e a corrida da chave de idempotência entre titulares (o C21 só repetia a chave dentro do mesmo titular). O C20 ganhou um passo com dois campos obrigatórios ausentes. O C21 ganhou oito rodadas em que dez titulares mandam a mesma chave ao mesmo tempo, com a checagem `recebimentos_por_chave`. Prova negativa no spike Python de antes da revisão (`704db98`): o C20 falha, e o C21 falha 5 de 5 com HTTP 500. O Python mergeado passa 18/18, e o C21 passa 5 de 5. O verificador agora restaura os contratos também ao fim de cada cenário: antes, o C23 deixava o banco diferente do repositório.

