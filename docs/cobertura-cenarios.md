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
| C10 | 2 | SN sem folha | Encerramento no protocolo; depois dele: `entrega_falhou` com dono sem reabrir, `recebimento_pendente` vindo do agendador (antes do protocolo só grava), reenvio resolve a falha, confirmação gera `documento.recebido` e resolve a pendência, pendência depois da confirmação não faz nada | Acompanhamento da entrega (decisão de 07/10) |
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
| Fato ausente na conferência (classe S) | Nenhum cenário publica a guia sem o apurado | Barato de acrescentar |
| Reabertura de competência encerrada | Fora do spike (§14) | Motor de produção |
| Fila assíncrona | O C6 permite síncrono se cumprir os limites | Só se o C6 falhar |

## 5 · Registro

- **07/10/2026, decisão do Adriano:** pagamento de guia não é parte do fechamento. O fechamento termina no protocolo (App ou e-mail disparado); recebimento é indicador, e falha ou falta de leitura depois do encerramento vira exceção com dono, sem reabrir. Aplicado na semântica v3, V006 (`pago` aposentado, regra `fechamento.entregaveis` v2, I6), C1–C5 e C8 sem `guia_paga`, C3 renomeado para `c3-r1-nota-cancelada-das-protocolado.json`, C2 com o recibo do PGDAS-D no fim (para o R4 continuar em caso aberto) e C10 novo. `diferenca_paga` deixou de existir. Validado contra a implementação de referência: fases 1 a 3 passam; mutantes sem `fato_id` no protocolo, sem checagem de confirmação e com versão de regra fixa são reprovados.

- **07/10/2026, observação do Adriano:** o caminho de encerramento do LP (E10 com DCTFWeb, E11 com guia de IRPJ) ia além do que o C7 exercita, porque o C7 para na conclusão do E03. A spike .NET mostrou isso numa exploração manual (`MATRIZ-ENTREGAVEIS.md`, na branch `spike/dotnet`). **Resolvido com o C8**, que continua o C7 até o encerramento, e com o C5, que encerra um LP no 1º mês do trimestre. O C7 foi mantido como está, para não mudar a régua da fase 1.
