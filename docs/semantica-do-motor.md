# Semântica do motor do fechamento (normativa para as duas spikes)

**v3 · 07/10/2026** (v1 de 06/10; v2 com as regras da fase 2: §8 item 4, §8.1, §9.1 CF-09, §9.4, §11 e §12; **v3: o fechamento termina no protocolo de entrega**, o pagamento sai do fechamento, e entra o acompanhamento depois do protocolo: §4, §7.4, §10, §10.3, §11.2, §12, §15). Este documento define **o que** o motor faz. As duas spikes implementam exatamente isto. Onde houver dúvida, **não invente comportamento**: registre a dúvida em `spikes/<linguagem>/DUVIDAS.md` e siga o que está escrito aqui.

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
| `fechamento.entregaveis` | Quais entregáveis existem, dependências, entradas, saída, conferências, pós-validação, acompanhamento. **Em uso: v2** (a v1 está `aposentada` desde a V006) |
| `fiscal.tolerancia` | Tolerância das conferências numéricas |
| `fiscal.parametros` | Percentual do pró-labore (CF-08) |

- **Nunca fixe a versão no código.** Leia a versão em uso pelo `status`. A V006 trocou a v1 pela v2 sem mudar a chave; código que procura `versao = 1` quebra.
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
aguardando_insumo(1) → pronto(2) → processado(3) → validado(4) → liberado(5) → disponibilizado(6) → encerrado(8)
```

Fora do caminho (ordem nula): `divergente`, `invalidado`.

**Aposentado (v3):** `pago`. A linha continua em `fechamento.estado_entregavel` com `aposentado = true` e `ordem` nula, e nenhuma transição leva a ele. O pagamento da guia não é parte do fechamento: é sinal para a Regularidade Fiscal (§15). Nenhum entregável pode entrar em estado aposentado.

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
4. Se o `tipo` é um dos fatos do §10.3 (`documento_disponibilizado` em reenvio, `entrega_falhou`, `entrega_confirmada`, `recebimento_pendente`), aplica o **acompanhamento** (§10.3) ao caso daquele titular e competência, **aberto ou encerrado**. Ordem: depois da invalidação e da reavaliação.

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

**Como a reavaliação anda (normativo desde a v2):** em voltas. Em cada volta, os entregáveis do caso são percorridos em ordem de `tipo` (E01, E02, …) e **cada um dá no máximo um passo**:

| Estado no início do passo | Passo |
|---|---|
| `aguardando_insumo` ou `invalidado` | → `pronto` (§7.2); ou, se `invalidado` e não cumprir §7.2, → `aguardando_insumo` |
| `pronto`, executor `sistema` | → `processado` **e** desfecho das conferências (§9.3), no mesmo passo |
| `validado` | → `liberado` (§10.1) ou → `encerrado` (§10.2), conforme o item |
| `liberado` | → `disponibilizado`, se o fato existir |

O estado das dependências é lido **no momento da checagem**, já com as mudanças feitas antes na mesma volta. As voltas se repetem até nenhuma mudar nada.

Consequência que os cenários cobram: um entregável `invalidado` cuja dependência acabou de voltar só a `pronto` vai para `aguardando_insumo` e, numa volta seguinte, para `pronto` (cenário C9, E03).

## 8 · `ConcluirTarefa`

Rota: `POST /casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir`.

1. Caso ou entregável inexistente → `404`.
2. Entregável fora de `pronto`, ou executor `sistema` → `409`.
3. `saidas` vazio, ou entregável sem `saida` na regra → `422`.
4. Para cada item de `saidas`: publica fato (§6) com `tipo` = `saida.tipo` da regra, `titular_id` e `competencia` do caso, `tributo`, `valor_centavos` e `payload` do item, `fonte = 'tarefa'`, `observado_em` = agora. Liga em `entregavel_fato` com papel `saida` a versão vigente devolvida, **inclusive quando o efeito for `sem_mudanca`**. Se o efeito for `nova_versao`, a invalidação (§11) roda normalmente, **exceto para o próprio entregável que está sendo concluído**.
5. Conclui a tarefa aberta do entregável (`estado = 'concluida'`, `concluida_em`).
6. Entregável `pronto → processado` (motivo `tarefa_concluida`, `causado_por_tipo = 'tarefa'`, `causado_por_id` = id da tarefa, `ator` = `corpo.ator`).
7. Roda as conferências (§9).
8. Reavalia os casos abertos do titular (§7.4).

### 8.1 · `RegistrarOverride`

Rota: `POST /casos/{titular_id}/{competencia}/entregaveis/{tipo}/override`, corpo `{"ator", "cf", "motivo"}`.

A **conferência vigente** de uma CF num entregável é a mais recente daquela CF naquele entregável (maior `em`).

Validação, nesta ordem:
1. Corpo inválido (falta campo, `cf` fora do padrão `CF-nn`, `motivo` com menos de 5 caracteres) → `422`.
2. Caso ou entregável inexistente → `404`.
3. Entregável fora de `divergente`, ou a conferência vigente da `cf` não existe, ou não é `divergente` com severidade `B`, ou já tem override → `409`.

Efeito, numa transação:
1. Grava `override_autor` (= `ator`), `override_motivo` e `override_em` na conferência vigente da `cf`.
2. Resolve as exceções abertas daquele entregável com `tipo` = `cf` (`estado = 'resolvida'`, `resolucao = 'override'`, `resolvida_em`).
3. Evento `conferencia.override` (`payload`: `{"conferencia_id"}`).
4. Se nenhuma outra CF do entregável tiver conferência vigente `divergente`, severidade `B` e sem override: `divergente → validado` (motivo `override`, `causado_por_tipo = 'conferencia'`, id da conferência, `ator` = `corpo.ator`) e o evento do tipo do entregável (§9.3).
5. Reavalia os casos abertos do titular (§7.4).

Resposta `200`: `{"entregavel_id", "tipo", "estado"}` com o estado depois do override.

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
| CF-09 | E08 | para INSS e FGTS: `folha.payload.inss_centavos` e `folha.payload.fgts_centavos` | `guia[INSS].valor` e `guia[FGTS].valor`; a diferença da conferência é a **maior** das duas. `esperado_centavos` e `obtido_centavos` gravam o par do tributo de maior diferença (empate: INSS) |
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
- Divergência com severidade `A` **não bloqueia**: é gravada como `divergente`, não abre exceção nem evento, e o entregável segue para `validado` se nenhuma B divergir.

### 9.4 · Exceções: abertura única e resolução

- **Abertura única:** só se abre exceção de um `tipo` para um entregável se **não houver** outra exceção **aberta** do mesmo `tipo` para o mesmo entregável. Para exceção sem entregável (`competencia_encerrada`, §11.3), a chave é (`caso_id`, `tipo`).
- **Resolução por fato novo:** quando um entregável sai de `divergente` para `invalidado` (§11), todas as suas exceções abertas cujo `tipo` comece com `CF-` passam a `resolvida`, `resolucao = 'fato_alterado'`, `resolvida_em` = agora.
- **Resolução por override:** §8.1.
- **Resolução pelo acompanhamento da entrega:** §10.3 (`reenviado`, `recebido`).
- **Exceções que o spike não resolve:** `substituicao` e `competencia_encerrada` ficam `aberta`. Resolver exige ação humana fora do motor (reenvio do documento substituto, serviço retroativo). Elas **não** impedem o encerramento do caso (o E12 só olha estados dos entregáveis).
- **Exceção aberta em caso encerrado** é normal (§10.3, §11.3): o caso não reabre, e a exceção tem dono.

## 10 · Depois de `validado`

### 10.1 · Pós-validação (só itens com `pos_validacao`, no spike o E11)

| Passo | Condição | Transição | Evento |
|---|---|---|---|
| Liberação | `liberacao = "automatica"` | `validado → liberado` imediatamente (motivo `liberacao_automatica`) | — |
| Disponibilização (**protocolo**) | existe fato `disponibilizado_quando.tipo` com o **mesmo tributo** da guia de entrada do E11, no mesmo titular e competência | `liberado → disponibilizado` | `documento.disponibilizado`, com `fato_id` = versão vigente da **guia** de entrada do E11 |

`documento_disponibilizado` significa: o pacote foi publicado no App/portal **ou** disparado por e-mail. É o protocolo. O fechamento não espera confirmação de leitura nem pagamento: com o E11 em `disponibilizado`, o E12 pode encerrar o caso.

O `fato_id` da guia no evento `documento.disponibilizado` é o que a Regularidade Fiscal usa para agendar a checagem de pagamento depois do vencimento (§15). O evento nunca leva valor nem vencimento: quem precisa, lê o fato pelo id.

Fato que chega antes de o entregável alcançar o estado anterior é aplicado assim que o estado for alcançado.

### 10.2 · Encerramento (item com `encerra_caso: true`, o E12)

Na v2 da regra, o E12 depende do E11 com `estado_minimo = disponibilizado` e dos demais com `validado`.

Quando o E12 chega a `validado`: transição imediata `validado → encerrado` (motivo `encerramento`); caso `estado = 'encerrado'`, `encerrado_em`, `version + 1`; evento `fechamento.concluido`.

Depois do encerramento, **nenhuma transição** acontece em entregável do caso (o verificador cobra: INV-15).

### 10.3 · Acompanhamento depois do protocolo (bloco `acompanhamento` do E11)

O que acontece com o documento depois do protocolo (falha de envio, leitura, falta de leitura) **não muda estado de nenhum entregável e não reabre o caso**. Vira exceção com dono ou evento. Vale para caso **aberto ou encerrado**. Em produção, esses fatos vêm do fluxo de Entrega ao cliente (canal, disparo, leitura) e do agendador; no spike, chegam por `POST /fatos`.

Os fatos usam a chave normal (§6): titular, competência, `tipo` e `tributo` = o tributo da guia de entrada do E11 (DAS no SN, IRPJ no LP).

**Quando aplicar:** só quando o fato é **gravado** (`novo` ou `nova_versao`) **e** o E11 do caso daquele titular e competência **já estava** em `disponibilizado` quando o fato chegou (estado lido antes da reavaliação desta publicação). Assim, o fato `documento_disponibilizado` que leva o E11 de `liberado` a `disponibilizado` é protocolo (§10.1), nunca reenvio. Se o E11 ainda não chegou lá, ou o efeito é `sem_mudanca`, o fato só é gravado. Fato que chegou antes do protocolo **não** é reaplicado depois (diferente do §10.1).

| Fato gravado | Efeito |
|---|---|
| `documento_disponibilizado`, **nova versão**, com o E11 já em `disponibilizado` | **Reenvio** (outro canal ou de novo). Evento `documento.disponibilizado` (novo protocolo, mesmo payload do §10.1). Exceções abertas `entrega_falhou` do E11 passam a `resolvida`, `resolucao = 'reenviado'` |
| `acompanhamento.falha.tipo` (`entrega_falhou`) | Exceção `tipo = 'entrega_falhou'`, classe `O`, no E11, `dono` = carteira do caso (§9.4, abertura única); evento `excecao.aberta` |
| `acompanhamento.confirmacao.tipo` (`entrega_confirmada`) | Evento `recebimento.confirmado` (`payload`: `{"entregavel_id"}`). Exceções abertas `entrega_falhou` e `recebimento_pendente` do E11 passam a `resolvida`, `resolucao = 'recebido'` |
| `acompanhamento.pendencia.tipo` (`recebimento_pendente`) | Se **não existe** fato vigente `entrega_confirmada` com a mesma chave: exceção `tipo = 'recebimento_pendente'`, classe `O`, no E11 (abertura única); evento `excecao.aberta`. Se existe, nada |

O "N dias sem leitura" **não** é calculado pelo motor: o spike não tem relógio. Quem publica `recebimento_pendente` é o agendador (em produção, o fluxo de Entrega ao cliente), com `payload` livre (ex.: `{"dias_sem_leitura": 5}`). Cada nova versão do fato é uma nova checagem.

Em caso encerrado, o evento `excecao.aberta` e o `recebimento.confirmado` levam o `caso_id` do caso encerrado.

## 11 · Invalidação (nova versão de fato já usado)

Roda dentro de `PublicarFato` (§6, passo 3) e de `ConcluirTarefa` (§8, passo 4), **na mesma transação**, quando o efeito é `nova_versao`. Depois dela vem a reavaliação dos casos abertos do titular (§7.4).

### 11.1 · Quem é atingido

**Sementes:** os entregáveis que têm a **versão anterior** do fato em `entregavel_fato` (qualquer papel), menos o entregável que está sendo concluído (§8, passo 4). Agrupe as sementes por caso.

### 11.2 · Caso aberto: regra por estado

Para cada semente, conforme o estado atual:

| Estado do entregável | Ação |
|---|---|
| `pronto`, `processado`, `divergente`, `validado`, `liberado` | `→ invalidado` (motivo `fato_alterado`, `causado_por_tipo = 'fato'`, `causado_por_id` = id do fato novo, `ator = 'sistema'`); evento `entregavel.invalidado`; tarefa aberta do entregável passa a `cancelada`; se saiu de `divergente`, resolve as exceções `CF-` (§9.4) |
| `disponibilizado` | Estado não muda. Exceção `tipo = 'substituicao'`, classe `O` (§9.4, abertura única); evento `excecao.aberta`. O documento já protocolado precisa ser substituído; se o cliente já pagou a guia antiga, quem trata a diferença é a Regularidade Fiscal (§15) |
| `aguardando_insumo`, `invalidado`, `encerrado` | Nada |

**Cascata:** para cada entregável que **foi para `invalidado`** neste passo, aplique a mesma tabela a todo entregável do mesmo caso que **depende dele** (`entregavel_dependencia.depende_de_id`), recursivamente. A cascata só continua a partir de quem foi para `invalidado`. **Cada entregável é tratado no máximo uma vez por publicação.**

(Na v2, `diferenca_paga` deixou de existir: o fechamento não sabe se a guia foi paga.)

Interpretação no spike: o E11 depende do E08, então representa a entrega do **pacote** da competência. Folha ou guia do DP alterada depois da entrega gera `substituicao` no E11 (cenário C2).

### 11.3 · Caso encerrado

Se a semente pertence a um caso com `estado = 'encerrado'`: **nenhuma transição** em nenhum entregável desse caso. Abre **uma** exceção `tipo = 'competencia_encerrada'`, classe `O`, `entregavel_id` nulo, `dono` = carteira do caso (§9.4, abertura única por caso), e o evento `excecao.aberta` com o `caso_id` do caso encerrado. Reabrir a competência fica fora do spike (§14). Em produção, esse é o gatilho do serviço retroativo (S10).

### 11.4 · Ordem dentro da transação

1. Grava o fato novo e o evento `fato.publicado`.
2. Invalidação (§11.1 a §11.3), sementes em ordem de `tipo` do entregável.
3. Reavaliação dos casos abertos do titular (§7.4).

## 12 · Eventos (nomes exatos)

| Evento | Quando | Payload |
|---|---|---|
| `competencia.aberta` | caso criado | `{"caso_id"}` |
| `fato.publicado` | fato gravado (`novo` ou `nova_versao`) | `{"fato_id"}` |
| `insumos.completos` | E01 → validado | `{"entregavel_id"}` |
| `apuracao.concluida` | E03 → validado | `{"entregavel_id"}` |
| `folha.fechada` | E06 ou E07 → validado | `{"entregavel_id"}` |
| `guias.validadas` | E08 ou E11 → validado | `{"entregavel_id"}` |
| `documento.disponibilizado` | E11 → disponibilizado (protocolo); e reenvio (§10.3) | `{"entregavel_id", "fato_id"}`, `fato_id` = guia de entrada do E11 |
| `recebimento.confirmado` | `entrega_confirmada` gravada com E11 em `disponibilizado` (§10.3) | `{"entregavel_id"}` |
| `conferencia.divergente` | conferência B divergente | `{"conferencia_id"}` |
| `excecao.aberta` | exceção criada | `{"excecao_id"}` |
| `entregavel.invalidado` | → invalidado | `{"entregavel_id"}` |
| `fechamento.concluido` | caso encerrado | `{"caso_id"}` |
| `conferencia.override` | override registrado (§8.1) | `{"conferencia_id"}` |

`recebimento.confirmado` segue o nome do fluxo de Entrega ao cliente. Não confundir com `documento.recebido` do ADR-011, que é o caminho inverso (o cliente enviou um documento à Rissi) e não é emitido pelo motor.

**Aposentado na v3:** `guia.paga`. O motor não emite mais esse evento. Na arquitetura alvo, quem o produz é a Regularidade Fiscal.

`caso_id` e `entregavel_id` da linha de `eventos.evento` são preenchidos sempre que se aplicam (`fato.publicado` leva o `caso_id` só se o fato for da competência de um caso existente; senão fica nulo). **Payload nunca leva CPF, CNPJ, nome ou valor.**

## 13 · Concorrência e fila

- A API pode processar de forma síncrona ou enfileirar em `eventos.fila`. Em ambos os casos, `GET /admin/fila` deve devolver `pendentes = 0` só quando **todo** o efeito de todas as requisições já estiver gravado.
- Consumo da fila: `SELECT ... FOR UPDATE SKIP LOCKED`.
- Mudança de estado de entregável sob trava da linha (`SELECT ... FOR UPDATE`) ou checagem de `version`. Duas reavaliações simultâneas do mesmo entregável não podem gravar duas transições iguais.
- Nada de estado em memória entre requisições: o banco é a única fonte (ADR-014).

## 14 · Fora do escopo do spike

Autenticação; RLS; cofre de segredos; conectores reais (OneFlow, Integra Contador, prefeituras, Nibo); porta documental; tela; reabertura de competência encerrada (o spike só registra a exceção `competencia_encerrada`, §11.3); resolução das exceções `substituicao` e `competencia_encerrada`; canal, disparo, leitura e lembrete (fluxo de Entrega ao cliente; o spike só recebe os fatos do §10.3); checagem de pagamento (Regularidade Fiscal); CF não marcadas `no_spike`.

## 15 · Fronteiras com outros fluxos (v3)

| Fluxo | Recebe do fechamento | Devolve ao fechamento |
|---|---|---|
| **Entrega ao cliente** (fluxo próprio, transversal) | Pedido de entrega do pacote liberado (E11 `liberado`) | `documento_disponibilizado` (protocolo, inclusive reenvio), `entrega_falhou`, `entrega_confirmada`, `recebimento_pendente` (§10.1, §10.3) |
| **Regularidade Fiscal** | `documento.disponibilizado` com o `fato_id` da guia: agenda a checagem de pagamento depois do vencimento | Nada. `guia.paga`, guia vencida, diferença paga e CF-17 são dela |

O fechamento **termina no protocolo**. O que acontece depois é acompanhado sem reabrir a competência; reabrir é só para mudança de conteúdo (§11.3).
