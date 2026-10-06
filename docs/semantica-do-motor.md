# Semântica do motor do fechamento (normativa para as duas spikes)

**v1 · 06/10/2026.** Este documento define **o que** o motor faz. As duas spikes implementam exatamente isto. Onde houver dúvida, **não invente comportamento**: registre a dúvida em `spikes/<linguagem>/DUVIDAS.md` e siga o que está escrito aqui.

Fontes do domínio: spec do spike v0.3, spec da saga do fechamento (CF-01..18, R1–R10), catálogo de capacidades (E01–E12). Tudo o que é regra fiscal aqui é **provisório** (status `provisoria` no banco) e não foi validado pelas áreas.

---

## 1 · Objetos

| Objeto | Tabela | O que é |
|---|---|---|
| Caso | `fechamento.caso_competencia` | Uma empresa (titular) numa competência `AAAAMM` |
| Entregável | `fechamento.entregavel` | Uma parte do fechamento do caso (E01..E12), com estado |
| Transição | `fechamento.entregavel_transicao` | Cada mudança de estado de um entregável. Só inserção |
| Dependência | `fechamento.entregavel_dependencia` | "Este entregável só fica pronto quando aquele chegar ao estado mínimo" |
| Fato | `fatos.fato` | Um valor publicado por uma fonte, imutável e versionado |
| Conferência | `conferencias.conferencia` | Um confronto CF-xx aplicado a fatos, com resultado |
| Exceção | `trabalho.excecao` | Algo que uma regra não resolveu; tem dono e prazo |
| Tarefa | `trabalho.tarefa_humana` | Trabalho de pessoa (executar entregável humano) |
| Evento | `eventos.evento` | Outbox: registro do que aconteceu, gravado na mesma transação |
| Fila | `eventos.fila` | Trabalho assíncrono no próprio Postgres |

## 2 · Regras em uso

O motor lê, na abertura do caso, a versão **em uso** de cada regra: a única linha de `regras.regra_versao` com `status IN ('provisoria','ativa')` para a chave.

| Chave | Usada para |
|---|---|
| `fechamento.entregaveis` | Quais entregáveis existem, dependências, entradas, saída, conferências, pós-validação |
| `fiscal.tolerancia` | Tolerância das conferências numéricas |
| `fiscal.parametros` | Percentual do pró-labore (CF-08) |

- O caso guarda o `id` da versão de `fechamento.entregaveis` usada (`regra_entregaveis_versao_id`) e **sempre** usa essa versão, mesmo que outra seja publicada depois.
- Cada conferência guarda o `id` da versão de `fiscal.tolerancia` usada (`regra_versao_id`).

## 3 · Contexto do caso e condições `quando`

**Contexto** = o snapshot da empresa recebido na abertura, mais um campo derivado:

| Campo | Origem | Tipo |
|---|---|---|
| `regime` | snapshot | `"SN"` ou `"LP"` |
| `tem_folha` | snapshot | booleano |
| `tem_prolabore` | snapshot | booleano |
| `tem_taxa_municipal` | snapshot | booleano |
| `filial` | snapshot | booleano |
| `carteira` | snapshot | texto |
| `municipio` | snapshot | texto (código IBGE) |
| `mes_do_trimestre` | **derivado** da competência: `((mes - 1) % 3) + 1` | 1, 2 ou 3 |

**Condição `quando`** (em entregáveis, dependências, entradas e conferências):
- É uma **lista de objetos**. A condição é verdadeira se **algum** objeto da lista casa (OU).
- Um objeto casa se **todos** os seus pares `campo: valor` forem iguais no contexto (E).
- Lista vazia ou campo ausente = **sempre verdadeira**.

Exemplo: `"quando": [{"tem_folha": true}, {"tem_prolabore": true}]` = tem folha **ou** tem pró-labore.

## 4 · Estados do entregável

Caminho principal, com `ordem` em `fechamento.estado_entregavel`:

```
aguardando_insumo(1) → pronto(2) → processado(3) → validado(4) → liberado(5) → disponibilizado(6) → pago(7) → encerrado(8)
```

Fora do caminho (ordem nula): `divergente`, `invalidado`.

- **"Estado ≥ X"** compara `ordem`. Estado com ordem nula **nunca** é ≥ a nada.
- Transições permitidas estão em `fechamento.transicao_permitida`. **Qualquer outra é erro.** O verificador recusa transições fora da lista.

### 4.1 · Regra de ouro das transições

Toda mudança de `fechamento.entregavel.estado`, **na mesma transação**:
1. atualiza `estado`, `estado_desde` (= instante da transição) e incrementa `version`;
2. insere uma linha em `fechamento.entregavel_transicao` com `de_estado`, `para_estado`, `motivo`, `causado_por_tipo`, `causado_por_id`, `ator`, `regra_versao_id` (a versão de `fechamento.entregaveis` do caso);
3. grava os eventos daquela mudança em `eventos.evento`.

A criação do entregável também gera transição (`de_estado` = `NULL`, `para_estado` = `aguardando_insumo`).

**Valores de `motivo`** (use exatamente estes):

| motivo | Quando |
|---|---|
| `abrir_competencia` | Criação do entregável |
| `prontidao` | aguardando_insumo/invalidado → pronto; invalidado → aguardando_insumo |
| `processamento_sistema` | pronto → processado, executor `sistema` |
| `tarefa_concluida` | pronto → processado, executor `humano` |
| `conferencias_ok` | processado → validado |
| `conferencia_divergente` | processado → divergente |
| `liberacao_automatica` | validado → liberado |
| `documento_disponibilizado` | liberado → disponibilizado |
| `guia_paga` | disponibilizado → pago |
| `encerramento` | validado → encerrado (E12) |
| `fato_alterado` | qualquer → invalidado |
| `override` | divergente → validado |

## 5 · Abertura: `AbrirCompetencias`

Para cada empresa do corpo da requisição, em **uma transação por empresa**:

1. Se já existe caso (`titular_id`, `competencia`): **não faz nada** e reporta em `existentes`. (Idempotente.)
2. Cria o caso: `estado = 'aberto'`, `snapshot` = empresa recebida, `carteira` = `snapshot.carteira`, `regra_entregaveis_versao_id` = versão em uso.
3. Para cada item de `entregaveis` da regra cujo `quando` casa: cria o entregável em `aguardando_insumo` com `executor` da regra e `area` de `fechamento.entregavel_tipo`; grava a transição de criação.
4. Para cada `depende_de` de cada entregável criado: se o `quando` da dependência casa **e** o entregável alvo **existe no caso**, grava em `entregavel_dependencia` com `estado_minimo` = o do item ou `estado_minimo_padrao` (`validado`). **Dependência para entregável inexistente no caso é ignorada.**
5. Evento `competencia.aberta` (`payload`: `{"caso_id": ...}`).
6. Avalia a prontidão (§7) de **todos** os entregáveis do caso.

## 6 · Fatos: `PublicarFato`

**Chave** do fato: (`titular_id`, `competencia`, `tipo`, `tributo`). `tributo` ausente = `''`.

**Hash** = SHA-256, em hexadecimal minúsculo, do JSON canônico `{"payload": <payload>, "valor_centavos": <valor ou null>}`:
- chaves de objeto ordenadas, em todos os níveis;
- sem espaços (`separators=(",", ":")`);
- UTF-8, sem escapar caracteres não ASCII.

Comportamento:

| Situação | Efeito | Grava | Retorna `efeito` |
|---|---|---|---|
| Não existe fato com a chave | versão 1 | sim | `novo` |
| Existe, e o hash da versão vigente é igual | nada | não | `sem_mudanca` |
| Existe, hash diferente | versão = vigente + 1; `substitui_id` = vigente | sim | `nova_versao` |

Quando grava:
1. Evento `fato.publicado` (`payload`: `{"fato_id": ...}`).
2. **Reavaliação:** todo entregável de qualquer caso **aberto** do mesmo `titular_id` que tenha esta chave como entrada resolvida (§7.1, inclusive com `competencia_relativa`) ou como gatilho de pós-validação (§10) é reavaliado.
3. Se `efeito = nova_versao` e o fato anterior estava ligado a entregáveis (`entregavel_fato`), aplica a **invalidação** (§11).

Fato pode chegar para competência sem caso aberto. É gravado normalmente.

## 7 · Prontidão

### 7.1 · Entradas resolvidas

Para cada item de `entradas` do entregável cujo `quando` casa no contexto do caso:
- chave = (`titular_id` do caso, competência do caso deslocada por `competencia_relativa` meses (padrão 0), `tipo`, `tributo` ou `''`).
- `competencia_relativa = -1` sobre `202601` dá `202512` (vira o ano).

A entrada está **presente** se existe fato com a chave (`fatos.fato_vigente`).

### 7.2 · Quando um entregável fica pronto

Um entregável em `aguardando_insumo` ou `invalidado` passa a `pronto` se **e somente se**:
1. toda dependência em `entregavel_dependencia` está com estado ≥ `estado_minimo`; **e**
2. toda entrada resolvida está presente.

Ao ficar pronto, grava em `entregavel_fato` (papel `entrada`) a versão vigente de cada entrada resolvida.

Um entregável em `invalidado` que **não** cumpre a regra acima volta a `aguardando_insumo` (motivo `prontidao`).

### 7.3 · O que acontece em `pronto`

| Executor | O que acontece |
|---|---|
| `sistema` | Passa **imediatamente** a `processado` (motivo `processamento_sistema`) e roda as conferências (§9) |
| `humano` | Cria `tarefa_humana` (`tipo = 'executar_entregavel'`, `responsavel` = carteira do caso, `estado = 'aberta'`) e espera `ConcluirTarefa` (§8) |

### 7.4 · Propagação

Sempre que um entregável muda de estado, os entregáveis do **mesmo caso** que dependem dele são reavaliados (prontidão e encerramento). Repetir até não haver mudança.

## 8 · `ConcluirTarefa`

Rota: `POST /casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir`.

1. Caso ou entregável inexistente → `404`.
2. Entregável fora de `pronto`, ou executor `sistema` → `409`.
3. `saidas` vazio, ou entregável sem `saida` na regra → `422`.
4. Para cada item de `saidas`: publica fato (§6) com `tipo` = `saida.tipo` da regra, `titular_id` e `competencia` do caso, `tributo`, `valor_centavos` e `payload` do item, `fonte = 'tarefa'`, `observado_em` = agora. Liga em `entregavel_fato` com papel `saida`.
5. Conclui a tarefa aberta do entregável (`estado = 'concluida'`, `concluida_em`).
6. Entregável `pronto → processado` (motivo `tarefa_concluida`, `causado_por_tipo = 'tarefa'`, `causado_por_id` = id da tarefa, `ator` = `corpo.ator`).
7. Roda as conferências (§9).

## 9 · Conferências

Rodam quando o entregável chega a `processado`. São as conferências do item da regra cujo `quando` casa.

### 9.1 · Fórmulas do spike

Os valores vêm dos **fatos vigentes do próprio caso** (mesmo titular e competência), não só das entradas declaradas. "`apurado[X]`" = fato `tipo = 'apurado'`, `tributo = 'X'`.

| CF | Entregável | Esperado | Obtido |
|---|---|---|---|
| CF-01 | E01 | `faturamento_mes.valor_centavos` | `notas_oneflow.valor_centavos` |
| CF-04 | E03 (SN) | `arred(faturamento_mes.valor × aliquota_mes.payload.aliquota_bp ÷ 10000)` | `apurado[DAS].valor` |
| CF-05 | E11 | `apurado[T].valor`, T = tributo da guia de entrada do E11 | `guia[T].valor` |
| CF-06 | E11 | competência do caso | `guia[T].payload.competencia_impressa` (comparação de texto; esperado/obtido em centavos ficam nulos) |
| CF-07 | E04 | `apurado[DAS].valor` (SN) ou `apurado[IRPJ].valor` (LP) | soma de `divisao_socios.payload.parcelas[].valor_centavos` |
| CF-08 | E07 (SN) | `arred(faturamento_mes.valor × fiscal.parametros.prolabore_percentual_bp ÷ 10000)` | `prolabore.valor` |
| CF-09 | E08 | para INSS e FGTS: `folha.payload.inss_centavos` e `folha.payload.fgts_centavos` | `guia[INSS].valor` e `guia[FGTS].valor`; a diferença da conferência é a **maior** das duas |
| CF-12 | E10 | existe `recibo_obrigacao` com o tributo exigido pela entrada | — (ok se existe) |

`arred` = divisão inteira com arredondamento **meio para o par** (banker's rounding), sobre inteiros. Nunca use ponto flutuante.

### 9.2 · Resultado, severidade e classe

Para conferência numérica, `diferenca = |esperado − obtido|` em centavos. Com `fiscal.tolerancia.padrao`:

| Condição | `resultado` | `severidade` |
|---|---|---|
| `diferenca ≤ ok_ate_centavos` | `ok` | a do catálogo |
| `ok_ate < diferenca ≤ alerta_ate_centavos` | `divergente` | `A` |
| `diferenca > alerta_ate_centavos` | `divergente` | a do catálogo |

Para CF-06 e CF-12: `ok` ou `divergente` com a severidade do catálogo.

Fato necessário à fórmula ausente → `resultado = 'divergente'`, `classe = 'S'`, `diferenca = {"erro": "fato ausente: <tipo>[<tributo>]"}`.

Demais campos: `classe` = `classe_padrao` do catálogo; `fatos_usados` = lista `[{"fato_id", "versao"}]` ordenada por `fato_id`; `diferenca` (jsonb) = `{"centavos": n}` ou o objeto da CF-06; `entrada_hash` = SHA-256 do JSON canônico `{"cf", "fatos_usados", "regra_versao_id"}`.

**Idempotência:** se já existe conferência com o mesmo (`cf`, `entregavel_id`, `entrada_hash`), **não grava outra**; reaproveita o resultado.

### 9.3 · Desfecho

- Alguma conferência `divergente` com severidade `B` → entregável `processado → divergente` (motivo `conferencia_divergente`, `causado_por_tipo = 'conferencia'`); abre `trabalho.excecao` (`tipo` = a CF, `classe`, `dono` = carteira, `estado = 'aberta'`); eventos `conferencia.divergente` e `excecao.aberta`.
- Senão → `processado → validado` (motivo `conferencias_ok`); evento `evento_publicado` do tipo do entregável, se houver.
- Entregável sem conferências → `validado` direto.

## 10 · Depois de `validado`

### 10.1 · Pós-validação (só itens com `pos_validacao`, no spike o E11)

| Passo | Condição | Transição | Evento |
|---|---|---|---|
| Liberação | `liberacao = "automatica"` | `validado → liberado` imediatamente (motivo `liberacao_automatica`) | — |
| Disponibilização | existe fato `disponibilizado_quando.tipo` com o **mesmo tributo** da guia de entrada do E11, no mesmo titular e competência | `liberado → disponibilizado` | `documento.disponibilizado` |
| Pagamento | existe fato `pago_quando.tipo` com o mesmo tributo | `disponibilizado → pago` | `guia.paga` |

Fato que chega antes de o entregável alcançar o estado anterior é aplicado assim que o estado for alcançado.

### 10.2 · Encerramento (item com `encerra_caso: true`, o E12)

Quando o E12 chega a `validado`: transição imediata `validado → encerrado` (motivo `encerramento`); caso `estado = 'encerrado'`, `encerrado_em`, `version + 1`; evento `fechamento.concluido`.

## 11 · Invalidação (nova versão de fato já usado)

Usada pelos cenários 2 a 5 (ainda não escritos). Normativa desde já.

Quando um fato ganha `nova_versao` e a versão anterior está em `entregavel_fato` de um entregável E:

| Estado de E | Ação |
|---|---|
| `pronto`, `processado`, `divergente`, `validado`, `liberado` | `→ invalidado` (motivo `fato_alterado`, `causado_por_tipo = 'fato'`, id do fato novo); evento `entregavel.invalidado`; tarefa aberta de E, se houver, é cancelada; depois reavalia a prontidão (§7.2) |
| `disponibilizado`, `pago` | Estado não muda. Abre exceção `tipo = 'substituicao'`, classe `O`. Evento `excecao.aberta` |
| `aguardando_insumo` | Nada |

**Cascata:** todo entregável que depende de E e está em estado ≥ `pronto` (pelo caminho principal) ou `divergente` recebe a mesma regra, recursivamente.

## 12 · Eventos (nomes exatos)

| Evento | Quando | Payload |
|---|---|---|
| `competencia.aberta` | caso criado | `{"caso_id"}` |
| `fato.publicado` | fato gravado (`novo` ou `nova_versao`) | `{"fato_id"}` |
| `insumos.completos` | E01 → validado | `{"entregavel_id"}` |
| `apuracao.concluida` | E03 → validado | `{"entregavel_id"}` |
| `folha.fechada` | E06 ou E07 → validado | `{"entregavel_id"}` |
| `guias.validadas` | E08 ou E11 → validado | `{"entregavel_id"}` |
| `documento.disponibilizado` | E11 → disponibilizado | `{"entregavel_id"}` |
| `guia.paga` | E11 → pago | `{"entregavel_id"}` |
| `conferencia.divergente` | conferência B divergente | `{"conferencia_id"}` |
| `excecao.aberta` | exceção criada | `{"excecao_id"}` |
| `entregavel.invalidado` | → invalidado | `{"entregavel_id"}` |
| `fechamento.concluido` | caso encerrado | `{"caso_id"}` |

`caso_id` e `entregavel_id` da linha de `eventos.evento` são preenchidos sempre que se aplicam (`fato.publicado` leva o `caso_id` só se o fato for da competência de um caso existente; senão fica nulo). **Payload nunca leva CPF, CNPJ, nome ou valor.**

## 13 · Concorrência e fila

- A API pode processar de forma síncrona ou enfileirar em `eventos.fila`. Em ambos os casos, `GET /admin/fila` deve devolver `pendentes = 0` só quando **todo** o efeito de todas as requisições já estiver gravado.
- Consumo da fila: `SELECT ... FOR UPDATE SKIP LOCKED`.
- Mudança de estado de entregável sob trava da linha (`SELECT ... FOR UPDATE`) ou checagem de `version`. Duas reavaliações simultâneas do mesmo entregável não podem gravar duas transições iguais.
- Nada de estado em memória entre requisições: o banco é a única fonte (ADR-014).

## 14 · Fora do escopo do spike

Autenticação; RLS; cofre de segredos; conectores reais (OneFlow, Integra Contador, prefeituras, Nibo); porta documental; tela; reabertura de competência encerrada (R1–R10 sobre caso encerrado); CF não marcadas `no_spike`.
