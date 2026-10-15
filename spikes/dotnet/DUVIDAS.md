# Dúvidas — spike .NET

Tudo o que a semântica (`docs/semantica-do-motor.md`) não respondeu diretamente e
a decisão tomada. Nada aqui alterou a base comum.

## 1. Formato da `MOTOR_DB` para o verificador vs. para a imagem

O `docker-compose.yml` passa `MOTOR_DB` no formato chave-valor do Npgsql
(`Host=...;Port=...`), enquanto o verificador recebe o DSN no formato URL
(`postgresql://...`). **Decisão:** a aplicação lê `MOTOR_DB` como string de
conexão do Npgsql (o valor que o compose injeta). O DSN em formato URL é só o
argumento `--db` do verificador, que fala com o Postgres diretamente — não passa
pela aplicação. Nenhuma conversão é necessária.

## 2. `now()` e `DateTimeOffset` no Npgsql 9

O Npgsql 9 recusa gravar `DateTimeOffset` com offset diferente de UTC em coluna
`timestamptz`, e mapeia `timestamptz` de volta para `DateTime` (Kind=Utc).
**Decisão:** (a) `observado_em` recebido na requisição é convertido para UTC
(`ToUniversalTime()`) antes de gravar — o instante absoluto é preservado, só a
representação muda; (b) `AgoraAsync()` lê `now()` como `DateTime` e o envolve em
`DateTimeOffset` UTC. A semântica manda usar a hora do banco para ordenar
transições (feito via `clock_timestamp()` na coluna `em`), então o `agora`
devolvido só alimenta assinaturas internas, não a ordenação.

## 3. Reavaliação após publicar fato (§6.2 vs. design)

§6.2 diz para reavaliar os entregáveis "afetados". O design fixou o superconjunto
seguro: **todos os casos abertos do titular**. **Decisão:** seguimos o design.
É o que faz o C7 funcionar — um `apurado[IRPJ]` de 202607/202608 (competências
sem caso) reavalia o caso de 202609, cuja entrada do E03 usa `competencia_relativa`.

## 4. CF-09: qual tributo vira `esperado_centavos`/`obtido_centavos`

A semântica diz que a conferência confere INSS **e** FGTS e que "a diferença da
conferência é a maior das duas", mas a tabela de conferência guarda um único par
`esperado`/`obtido`. **Decisão:** gravamos o par (`esperado`,`obtido`) do tributo
de **maior diferença** e usamos todos os três fatos (`folha`, `guia[INSS]`,
`guia[FGTS]`) em `fatos_usados`. No C1 as duas diferenças são 0, então o resultado
é `ok` de qualquer modo; a escolha só importaria num caso divergente (fase 2).

## 5. `causado_por_tipo` nas transições de sistema

A semântica enumera os `motivo` mas não amarra um `causado_por_tipo` a cada um
(a coluna aceita `fato`/`conferencia`/`tarefa`/`comando`/`dependencia` ou nulo).
**Decisão:** `comando` para transições disparadas pela avaliação do sistema
(prontidão, processamento, conferências_ok, liberação, encerramento),
`conferencia` para `conferencia_divergente`, `tarefa` para `tarefa_concluida`
(com o id da tarefa), `fato` para disponibilização/pagamento. O verificador não
checa `causado_por_tipo` diretamente, mas INV-2 exige que a transição seja
permitida — e todas as nossas estão em `transicao_permitida`.

## 6. Validação de corpo (422)

O contrato tem `additionalProperties: false` e vários `required`. **Decisão:**
validamos o que os cenários exercem (presença e tipo de `competencia`, `ator`,
`empresas`, campos da empresa, `titular_id`/`tipo`/`fonte`/`observado_em` do fato,
`ator`/`saidas` do concluir) e devolvemos `422 {"erro","mensagem"}`. Não
rejeitamos propriedades extras desconhecidas (o contrato pede, mas nenhum cenário
desta fase envia corpo com `additionalProperties`); registrado aqui como lacuna
consciente para a fase 2.

## 7. Idempotência de conferência sob corrida

A chave única `(cf, entregavel_id, entrada_hash)` garante no banco. **Decisão:**
`INSERT ... ON CONFLICT DO NOTHING RETURNING id`; se não retornar (corrida),
relemos a linha vigente e reaproveitamos o resultado. Combinado com `FOR UPDATE`
na linha do entregável, duas avaliações do mesmo entregável não gravam duas
conferências nem duas transições.

## Nenhum erro encontrado na base comum

Nenhuma divergência entre a base comum e a semântica exigiu parar o trabalho. O
esquema, o contrato e as regras provisórias foram suficientes para C1 e C7 sem
nenhuma alteração em `db/`, `contrato/`, `cenarios/`, `verificador/`.

---

## Semântica v3 (07/10/2026) — decisões ao implementar as fases 2 e 3

Com o merge da `main`, a base comum passou à semântica **v3** (V006: o fechamento
termina no protocolo de entrega, o pagamento sai do fechamento) e aos cenários
C2–C10. Decisões tomadas para passar os 10 cenários, todas dentro de
`spikes/dotnet/`:

### 8. Estado `pago` aposentado (§4, V006)

A V006 marca `pago` como `aposentado` e remove a transição `disponibilizado →
pago`. **Decisão:** removido do motor todo o caminho de pagamento — o laço de
avaliação não tenta mais `disponibilizado → pago`, e o evento `guia.paga` não é
mais emitido. O enum `EstadoEntregavel.Pago` permanece só para desserializar
transições históricas, mas nenhuma transição nova o alcança. O que acontece com a
guia depois do protocolo é da Regularidade Fiscal (§15).

### 9. `documento.disponibilizado` carrega o `fato_id` da guia (INV-14, §10.1)

O evento de protocolo precisa levar `entregavel_id` **e** `fato_id` = versão
vigente da **guia de entrada do E11** (DAS no SN, IRPJ no LP). **Decisão:** no
momento da transição `liberado → disponibilizado`, lemos a guia vigente do caso e
gravamos os dois ids no payload. O mesmo vale para o reenvio (§10.3). Sem isso o
INV-14 reprova (era a única falha do C1/C8 pós-merge).

### 10. Invalidação por nova versão de fato (§11)

**Decisão:** sementes = entregáveis com a **versão anterior** do fato ligada em
`entregavel_fato` (qualquer papel), menos o entregável sendo concluído (§8.4),
agrupadas por caso. Caso aberto: tabela por estado de §11.2 com cascata (fila,
cada entregável tratado no máximo uma vez por publicação) a partir de quem foi
para `invalidado`; `disponibilizado` não transiciona e abre `substituicao`. Caso
encerrado (§11.3): nenhuma transição, uma exceção `competencia_encerrada` por caso
(abertura única). Exceções `CF-` do entregável que saiu de `divergente` são
resolvidas com `fato_alterado`.

### 11. `/override` (§8.1) — fecha a lacuna da fase 2 anotada em §6

**Decisão:** rota `POST /casos/{t}/{c}/entregaveis/{tipo}/override`, validação na
ordem corpo(422) → inexistente(404) → estado(409). A conferência vigente de uma
CF é a de maior `em`; o override exige entregável `divergente` e conf vigente
`divergente`+`B` sem override anterior. Após gravar o override e resolver as
exceções da CF, o entregável só vai a `validado` se **nenhuma outra** CF vigente
estiver `divergente`+`B` sem override.

### 12. Acompanhamento depois do protocolo (§10.3)

**Decisão:** aplicado em `PublicarFato`, **depois** da invalidação e da
reavaliação, e só quando o E11 do caso **já estava** em `disponibilizado` *antes*
desta publicação (estado lido antes de reavaliar). Assim o `documento_disponibilizado`
que leva `liberado → disponibilizado` é protocolo (§10.1), e só uma **nova versão**
com o E11 já disponibilizado é reenvio. `entrega_falhou`/`recebimento_pendente`
abrem exceção classe `O` (abertura única); `entrega_confirmada` emite
`recebimento.confirmado` e resolve as pendências; `recebimento_pendente` só abre
se não existir `entrega_confirmada` vigente. Vale para caso aberto **ou**
encerrado.

### 13. Lock por titular cobre a invalidação cross-competência

O lock de abertura/publicação já era por **titular** (não por titular+competência,
decisão da fase 1 para o C7). Isso continua correto na v3: a invalidação do C5
(alteração retroativa) toca um caso de competência diferente da do fato publicado,
e o lock por titular garante que a transação enxergue o estado comitado de todos
os casos do titular.

### 14. Versão da semântica do cálculo na chave de idempotência (§9.2)

A idempotência é por (`cf`, `entregavel_id`, `entrada_hash`) e o hash só cobre CF, versões dos fatos
e a regra de tolerância. Quando a fórmula/classificação muda (rodadas de review: operando inválido,
parcelas não-array, fatos retidos), conferências antigas seriam reaproveitadas sem a nova classificação.
Decisão: constante `EntradaHash.SemanticaCalculo` (hoje 2), **persistida** na coluna aditiva
`conferencia.semantica_calculo` (migração V007, `integer NOT NULL DEFAULT 1`) e incluída no JSON canônico
como campo próprio `"semantica_calculo"`, após `regra_versao_id`, somente quando > 1. Assim o hash é
reproduzível a partir da linha: (`cf`, `fatos_usados`, `regra_versao_id` real, `semantica_calculo`).
Com semântica 1 (linhas legadas, inserts dos spikes PHP/Python que não informam a coluna) o JSON é o
canônico original de §9.2 e os vetores ficam intactos. Ao subir a semântica, as conferências são
recalculadas na próxima execução (novas linhas; as antigas permanecem como histórico). Alternativas
descartadas: GUID sintético no lugar da regra (não reproduzível do persistido; foi a versão anterior
deste item), alterar o canônico para todos (quebra spec e vetores) e comparar semântica antes de reaproveitar
(a UNIQUE impediria nova linha sem apagar histórico).
Também: `parcelas` ausente/null/objeto/escalar em CF-07 é operando inválido (a spec só define
"ausente" para fatos, §9.2).

## Payload do fato: números int64, sem duplicata, profundidade 64 (semântica §6 e §8 passo 0, PR #7)

Decisão do usuário. Em `POST /fatos` e em cada saída de `/concluir`, o `payload` é rejeitado com 422
`corpo_invalido` se tiver, em qualquer profundidade: número fracionário, com expoente, `-0` ou fora de int64
(inclui >4300 dígitos); chave duplicada (comparada após decodificar escapes); substituto UTF-16 isolado (valor
ou chave); ou mais de 64 níveis (o próprio `payload` é o nível 1). `valor_centavos` é int64 ou null com o mesmo
critério (`1.0`, `1e2`, `-0`, `"600"`, `true`, 2^63 => 422).

Implementação (`Validacao.cs`):
- `Int64Estrito` é o critério único (`TryGetInt64` + checagem de `-0`, que só aloca string quando o valor é 0);
  usado por `ValorCentavosValido` e pelo percurso do payload.
- `PayloadValido(raiz, out payload, out motivo)` percorre o `JsonElement` uma vez e devolve motivo com caminho
  (`payload.a[1].b: número deve ser inteiro int64...`; "payload deve ser um objeto JSON" quando não é objeto).
- Duplicata e substituto isolado são detectados ao enumerar: `JsonDocument` mantém duplicatas e só falha em
  `GetString`/`Name` (InvalidOperationException) para `\ud800`.
- `JsonDocumentOptions.MaxDepth` sobe para 200 (`Validacao.OpcoesParse`, usada pelas 4 rotas): o padrão (64)
  contaria o corpo como nível 1 e rejeitaria um payload de 64 níveis (nível 65 do corpo) como "JSON inválido".
  O limite de 64 é do payload e é checado no percurso; acima de 200 o parser ainda responde 422.
- `HashCanonico` ordena chaves por code point (`OrdemCodePoint`; `StringComparer.Ordinal` é UTF-16 e errava
  U+FF5E vs U+1F600). O escape de strings já seguia §6 e passou nos vetores sem mudança.
- §8 passo 0: o handler de `/concluir` já validava corpo e todas as saídas antes de chamar o serviço (nenhuma
  consulta/gravação antes); `saidas` vazio dá 422 antes de 404/409. Agora coberto por teste.

Testes: `Fixtures/vetores-hash.json` (cópia; fonte é `contrato/vetores-hash.json` do PR #7) com todos os 49
vetores (22 aceitos: validação + canônico + sha256 exatos; 27 rejeitados, incluindo `valor_centavos_bruto`),
contagem derivada do arquivo, leitura única; teste de drift contra `contrato/vetores-hash.json` quando existir
(só passa a valer após o merge do PR #7); integração em memória (`WebApplicationFactory`) provando 422 nas duas
rotas para todos os rejeitados. Content Link não usado porque o arquivo só existe na branch do PR #7.

Impacto em dados legados: fato já gravado com fração/expoente continua no banco, mas reenviar o mesmo payload
agora dá 422 (não vira `sem_mudanca`) e o hash dele não é recalculado. Nada é migrado.

### U+0000 (NUL) nos textos (§6, regra adicionada no PR #7)

jsonb/text do PostgreSQL não armazenam U+0000. Rejeitado com 422 `corpo_invalido`: NUL (escapado `\u0000` ou
não) em qualquer string ou chave do `payload` (percurso de `Percorrer`, junto com os demais critérios) e em
qualquer string/chave do corpo das 4 rotas POST (`Validacao.CorpoSemNul`, aplicada logo após o corpo ser
confirmado objeto: cobre `tipo`, `tributo`, `fonte`, `titular_id`, `ator`, `carteira`, `municipio`, `motivo`,
`cf`, `origem_ref`, tributo das saídas etc.). U+0001..U+001F continuam permitidos. Parâmetros de caminho
(`titularId`, `competencia`, `tipo`) também são checados (`ContemNul`), mas na prática o Kestrel (e o TestHost)
recusam `%00` no caminho antes do handler: resposta real 400, não 422. A checagem no handler é defensiva e não
é alcançável por HTTP; por isso não há teste de integração para ela (a decisão "400 vs 422" fica com o Kestrel).
Vetores: fixture com 54 (22 aceitos, 32 rejeitados); o campo `campos_texto` sobrescreve o campo do envelope em
`Vetor.CorpoFato()`.

### 15. Ambiguidade na §6.2: piso ou exato

O .NET implementa a leitura PISO: reavalia todos os entregáveis de todos os casos abertos do titular a cada fato (`MotorServico`/`AvaliarCaso`). A §6 passo 2 diz o que deve ser reavaliado, sem "apenas". Sem diferencial equivalente ao do Python. Ver `.kiro/steering/divergencia-reavaliacao-spec-vs-design.md`.


## Fase 4 — Manutenção M1 (semântica v4, V008, cenários C11–C13)

Nenhuma dúvida bloqueante; a base comum (`db/`, `contrato/`, `cenarios/`, `verificador/`,
`docs/`, `.kiro/steering/`) não foi tocada. Decisões tomadas ao implementar as tarefas 19–23:

### 16. `semantica_calculo` = 3 (tarefa 20)
A §9.2 e o §16 (M1.3/M1.4) pedem que a versão da semântica do cálculo suba quando uma regra de
cálculo muda o resultado para os mesmos fatos — exatamente o caso da CF-07 do LP e do par IRRF da
CF-09. Subi `EntradaHash.SemanticaCalculo` de 2 para 3; **todas** as conferências novas gravam 3 e
entram no `entrada_hash` com `"semantica_calculo":3` (linhas 1 continuam sem o campo, 2 com `":2"`).
O `sujeito_fator_r` e a tolerância v2 não exigiram código: a condição do CF-08 (só SN sujeito ao
Fator R) e os limiares de tolerância vêm da `fechamento.entregaveis` v3 / `fiscal.tolerancia` v2,
lidas pelo `status` ("em uso"), que o motor já lê desde a v3. O campo `sujeito_fator_r` chega no
snapshot e é copiado ao contexto por `Contexto.DeSnapshot` (nenhuma validação nova foi necessária:
o C11 publica o campo e o verificador passa; a `EmpresaValida` já aceita campos extras do snapshot).

### 17. CF-07: ramo SN agregado × ramo LP tributo a tributo (tarefa 21)
A regra v3 tem **uma** CF-07 sem `quando` por regime; a §9.1 ramifica por regime dentro da fórmula.
Decisão: ler o `regime` do contexto do caso. **SN** mantém o comportamento anterior (esperado =
`apurado[DAS]`, obtido = soma de **todas** as parcelas, sem olhar tributo). **LP**: tributos =
apurados vigentes do caso ∪ tributos das parcelas; para cada T, esperado = `apurado[T].valor`,
obtido = soma das parcelas com `tributo = T` (nenhuma = 0); grava o par de **maior diferença**
(empate: menor tributo por code point, via `SortedSet` ordinal). Parcela sem `tributo` (texto não
vazio) = operando inválido (classe S); tributo da união sem `apurado` no caso = dado ausente
(classe S). Os apurados vêm dos fatos vigentes do caso (publicados pela conclusão do E03), não das
entradas declaradas — como a §9.1 descreve ("os valores vêm dos fatos vigentes do próprio caso").

### 18. CF-09: de onde vem cada par (tarefa 22)
A §9.1 é literal: **INSS/FGTS da folha do próprio mês**, **IRRF da folha da competência anterior**.
A regra v3 do E08 declara a entrada `folha` com `competencia_relativa: -1` (que resolve o IRRF) e as
guias INSS/FGTS/IRRF do mês. A folha do próprio mês é publicada pela conclusão do E06 e está nos
fatos vigentes do caso; a folha do mês anterior é de **outra** competência, então não aparece em
`VigentesDoCasoAsync` — `Conferir` resolve a entrada `folha` do item (competência deslocada) e busca
o fato por chave (`VigenteAsync`), passando-o em `ContextoCalculo.FolhaConferencia`. INSS/FGTS lêem
`Buscar(fatos,"folha")` (mês); IRRF lê `FolhaConferencia.payload.irrf_centavos` (mês anterior).
Par de maior diferença; empate = INSS, depois FGTS, depois IRRF. Folha do mês anterior sem
`irrf_centavos` = dado ausente, classe S (C13 T018). A invalidação da folha M-1 já atinge o E08
deste mês (§11.1): o E08 liga a folha M-1 como `entregavel_fato` (papel entrada) ao ficar pronto, e
a invalidação busca sementes por `ComFatoLigadoAsync(fatoAnterior)` sem filtrar competência — nada
a mudar no mecanismo de invalidação/cascata.

> **Retrabalho registrado (medida de manutenção):** a primeira versão da CF-09 lia os três pares da
> folha do mês anterior e regrediu o C2 (12/13) — INSS/FGTS têm de vir da folha do próprio mês.
> Corrigido conforme acima; é a única reversão de rumo da fase 4.

### 19. §6 passo 2 (idempotência) — v4
Esta spike faz percurso completo (todos os entregáveis de todos os casos abertos do titular a cada
fato). A idempotência cobrada pela fase 4 (`espera.sem_efeito_nos_casos`: fato sem consumidor não
grava transição/evento/conferência/exceção/tarefa/`version`) já era respeitada: a prontidão só
transiciona quando o estado efetivamente muda, e as conferências reaproveitam pela chave
(`cf`, `entregavel_id`, `entrada_hash`) sem gravar outra. Os três cenários C11–C13 têm passo de fato
sem consumidor e passam. Mantida a leitura PISO descrita no item 15.
