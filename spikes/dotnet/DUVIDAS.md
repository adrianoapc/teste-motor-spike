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
