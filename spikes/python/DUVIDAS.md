# Dúvidas e decisões — spike Python

Tudo o que a semântica (`docs/semantica-do-motor.md`) não respondeu, e a decisão tomada.
Nenhuma decisão aqui altera a base comum.

## D1 — `docker compose` v2 ausente no ambiente de desenvolvimento

O ambiente usado tinha só `docker-compose` v1 (sem o plugin `docker compose`) e `minio/minio`
não pôde ser puxado. Decisão: para C1/C7 o MinIO/objeto não é usado; subir só
`postgres` + `migrar`. Não altera a base comum — é só a forma de invocar o compose.
Registrado também em `REGISTRO-COMPILADOR.md` se virar erro de tipo.

## D2 — Escopo da reavaliação após publicar fato

A §6.2 pede reavaliar os entregáveis afetados; o design fixou **todos os entregáveis de
todos os casos abertos do titular** como superconjunto seguro. Adotado na fase 1, quando o
custo era irrelevante na escala de C1/C7.

**Superado (manutenção 3, ver D11).** O motor agora reavalia só o que a §6.2 manda. A decisão
"superconjunto seguro" foi trocada por um recorte provado equivalente (D11).

## D3 — Ordem de avaliação dentro de uma volta

Semântica não fixa a ordem. Design fixou **ordem de `tipo`** (E01, E02, …). Adotado; a
repetição até ponto fixo (§7.4) torna o resultado independente da ordem, mas a ordem
determinística facilita depurar.

## D4 — `observado_em` das saídas de tarefa

Não especificado. Decisão (design): instante atual do servidor (`now()` do banco).

## D5 — `fatos_usados` de uma conferência

§9.2 diz "lista ordenada por `fato_id`". Para CF numéricas usamos os fatos vigentes do caso
que entram na fórmula (esperado e obtido). Para CF-06/CF-12 (sem par numérico) usamos os
fatos efetivamente lidos pela regra. Ordenado por `fato_id` em todos os casos.

## D6 — Semântica v3 (Task 11): fim no protocolo de entrega

Após `git merge origin/main`, a base comum passou para a **semântica v3** (`docs/semantica-do-motor.md`)
e a V006 trocou a regra `fechamento.entregaveis` da v1 pela v2. Decisões/mudanças aplicadas na Task 11:

- **Estado `pago` aposentado (§4).** Removido o caminho `disponibilizado → pago` e o evento
  `guia.paga` do motor. Em `estados.py`, `pago` fica no enum como rótulo histórico mas **sem
  ordem** (`ordem(pago) is None`), então nunca é alvo de transição nem é `≥` a nada. O caminho
  principal pula de `disponibilizado(6)` direto para `encerrado(8)`.
- **`documento.disponibilizado` carrega `fato_id` da guia (§10.1).** O evento agora inclui
  `{"entregavel_id", "fato_id"}`, onde `fato_id` é a versão vigente da guia de entrada do E11
  (DAS no SN, IRPJ no LP). É o que a Regularidade Fiscal usa para agendar a checagem de
  pagamento (§15). Fechou a falha `INV-14` que derrubava C1, C8 e C10.
- **Regra em uso lida por `status`.** A leitura já era `status IN ('provisoria','ativa')`
  (nunca `versao = 1`), então a troca v1→v2 da V006 foi absorvida sem mudança nesse ponto.

Resultado: `--fase 1 --limpar` volta a 2/2 (C1 170 checagens, C7 92). No conjunto completo,
Task 11 levou 2/10 → **4/10** (C1, C6, C7, C8).

## D7 — Revisão da fase 1 (achado para a Task 12)

**Corrigido (rodada 1 de revisão do PR #5; `_regra_do_caso()` lê a versão fixada no caso).**
Descrição original: `_regra_do_caso()` relê `regra_entregaveis_em_uso()` em vez
de usar o `caso.regra_entregaveis_versao_id` gravado na abertura. O `Caso` já carrega esse id.
Enquanto só existe **uma** versão em uso (fase 1 e início da fase 2, pós-V006) o comportamento
é idêntico; mas §2 exige que o caso **sempre** use a versão com que foi aberto, mesmo que outra
seja publicada depois. Correção pertence à **Task 12** (reavaliação em passos e versão fixa do
caso): ler a regra por `versao_id` do caso, com cache por `versao_id` dentro da transação.
**Cache por `versao_id` dentro da transação: entregue na manutenção 2 (D10).** A regra pinada é
imutável, então a leitura e a montagem acontecem uma vez por transação.
Fora isso, a revisão da base da fase 1 (domínio: hash canônico, arredondamento meio-para-o-par,
competência com virada de ano, ordem de estados; infra: unidade de trabalho com uma transação
por comando e `FOR UPDATE`) não encontrou divergência com a semântica v3.

## D8 — Fase 3 (C6): mecanismo síncrono aguenta a escala; pool e restart do Postgres

O C6 (4.500 casos + 300 guias em lote) passou com **só esta spike no ar**, dentro dos limites
do Requisito 19 com folga larga: abertura em 49,2 s (teto 300 s) e p95 do lote 0,139 s
(teto 30 s), 0 erros. **Decisão:** manter o processamento **síncrono dentro da requisição** —
não foi preciso ir para `eventos.fila` (§13). Medidas em `METRICAS.md` (seção Fase 3).

Observação de robustez (não corrigida, fora do escopo da medição): na primeira tentativa, com
as três spikes no ar, o Postgres reiniciou (pressão de memória do host) e o pool do spike ficou
com conexões mortas — `/health` devolveu 500 até reiniciar o contêiner. O `ConnectionPool` do
psycopg aceita `check=ConnectionPool.check_connection`, que descartaria a conexão morta sozinho;
é um endurecimento barato se o motor precisar sobreviver a quedas do banco em produção. No
spike, o run válido foi feito com o banco íntegro e só esta spike contendo o Postgres.

## D9 — Manutenção 1: uma hierarquia de erro, um handler, sem código morto

Revisão de manutenção/complexidade (code-review xhigh). Sem mudança de comportamento observável.

- **Uma só hierarquia de erro.** `ErroDominio` (api) e `ErroNegocio` (aplicação) eram idênticas.
  Ficou `motor.aplicacao.erros.ErroNegocio` (+ `nao_encontrado`/`conflito`/`invalido`), com
  UM handler em `api/erros.py`. `ConflitoConcorrencia` agora é subclasse (409
  `conflito_concorrencia`), então perdeu o handler próprio. Fica em `aplicacao` porque a
  regra de camadas só deixa `api → aplicacao`.
- **int64: validação só na borda, tradução em um lugar.** A borda (`numeros._inteiro` para
  qualquer número do JSON e `Int64` estrito do Pydantic para `valor_centavos`) continua a barrar
  entrada com `corpo_invalido`. Saíram os `try/except` de `publicar_fato`,
  `_conferir_entregavel` e `_valor_opcional` (que conferia de novo o que o Pydantic já garantia;
  as saídas de `/concluir` agora chegam tipadas como `SaidaTarefa`). O único valor que ainda
  pode estourar depois da borda é o **calculado** (esperado/obtido de uma conferência, coluna
  `bigint`): `conferencia._gravar` chama `garantir_int64` e um exception handler único
  (`ValorForaDoIntervalo` → 422 `valor_fora_do_intervalo`) faz a tradução. A transação dá
  rollback como antes.
- **Código morto removido:** `_indexar_por_tributo`, `saiu_de_divergente_tem_excecao_cf`,
  `tarefa_aberta_do_entregavel`, `Centavos`, o `conflito()` duplicado da api, o parâmetro
  `uow` sem uso de `_tributo_da_guia`, e `excecao_aberta_existe` saiu do protocolo da unidade de
  trabalho (só a implementação o usa, dentro de `abrir_excecao_unica`). `_gravar` passou a
  receber `TipoConferencia` (sem import local nem `assert isinstance`).
  Observação: `.kiro/specs/spike-python/design.md` cita `Centavos = NewType(...)` como tipo de
  domínio; nunca foi usado no código e foi retirado (a spec é somente leitura para esta tarefa).

## D10 — Manutenção 2: regras lidas da regra pinada e prontidão pura

Sem mudança de comportamento observável (verificador 10/10, sequência de transições e eventos
idêntica à da `main` nos cenários C1-C10).

- **Prontidão como função de domínio (requisito 11.3).** `motor.dominio.prontidao` decide
  "está pronto?" (`esta_pronto`: dependências no estado mínimo E toda entrada resolvida
  presente, §7.2) e resolve as entradas da regra em chaves de fato (`entradas_resolvidas`, §7.1,
  com `quando` e `competencia_relativa`). Testada sem banco em `tests/unidade/test_prontidao.py`.
  A aplicação (`_pronto`) só carrega os dados: o estado das dependências vem de UMA consulta com
  JOIN (antes: 1 + N) e a presença das entradas entra como gerador, então o banco só é lido
  se as dependências cumprem e para na primeira entrada ausente (mesma ordem de curto-circuito
  de antes).
- **Tributos e tipos lidos da regra pinada do caso (§2), sem repetir o conhecimento.**
  O tributo da guia (DAS/IRPJ) vem das `entradas` do item de entrega; o do recibo do CF-12
  (PGDAS-D/DCTFWEB) das `entradas` do item conferido (E10); o tipo do fato de protocolo
  (`documento_disponibilizado`) vem de `pos_validacao.disponibilizado_quando`; os tipos de falha,
  confirmação e pendência já vinham de `acompanhamento`. O "E11" fixo some: o item de entrega é
  o que declara `pos_validacao`/`acompanhamento` (`RegraEntregaveis.item_de_entrega`).
  Os tipos de fato das fórmulas (`guia`, `apurado`, `recibo_obrigacao`) continuam no código:
  são a definição da conferência (§9.1), não uma regra de negócio mutável.
- **Lacuna da regra (CF-07).** A regra v2 não declara `apurado` nas entradas do E04, embora a
  fórmula do §9.1 use `apurado[DAS]` (SN) / `apurado[IRPJ]` (LP). Decisão: o tributo do `apurado`
  é o da guia do item de entrega (é a mesma tabela regime -> tributo do §9.1). Se a regra
  pinada não declara a entrada exigida (guia, recibo), a conferência vira insumo ausente
  (`divergente`, classe `S`), nunca exceção.
- **Cache da regra por `versao_id` dentro da transação** (promessa do D7). A regra pinada é
  imutável; `UnidadeDeTrabalhoSQL.regra_entregaveis_da_versao` lê e monta uma vez por
  transação. Foi preciso aqui (e não só no PR de reavaliação) porque agora `publicar_fato`
  consulta a regra do caso para achar o item de entrega: sem cache seriam +1 leitura por fato.
- **Assinaturas que mudaram:** `conferir(..., regra)` e `_acompanhamento_pos_protocolo(..., regra,
  ...)` recebem a regra do caso; `dependencias_do_entregavel` virou `dependencias_com_estado`
  (devolve `(estado_atual, estado_minimo)`).

Observação de robustez (**corrigida na manutenção 3, ver D12**): na primeira tentativa, com as
três spikes no ar, o Postgres reiniciou (pressão de memória do host) e o pool do spike ficou com
conexões mortas — `/health` devolveu 500 até reiniciar o contêiner. O pool agora usa
`check=ConnectionPool.check_connection` (descarta a conexão morta ao entregá-la) e toda conexão
sai com `lock_timeout` (D12). Nota sobre os números acima: o tempo de parede do C6 varia muito de
uma execução para outra na mesma base de código (observado 40-70 s), por isso as manutenções
2 e 3 comparam o custo por **contagem de SQLs**.

## D11 — Manutenção 3: reavaliação por sementes e ator das transições automáticas

**Recorte da reavaliação (spec §6 passo 2, §7.1, §10).** Antes, todo fato reavaliava todos os
entregáveis de todos os casos abertos do titular (SELECT FOR UPDATE de cada caso, 2 voltas ou
mais, uma consulta por dependência e por entrada). Agora (`_Pendencias`, `_reavaliar_titular`,
`avaliar_caso(..., sementes)`):

- **Sementes** de cada caso aberto do titular = entregáveis cuja entrada resolvida (com
  `competencia_relativa`) é a chave de um fato publicado na transação + entregáveis cujo gatilho
  de pós-validação (`disponibilizado_quando`) é essa chave + entregáveis que mudaram de estado
  FORA da avaliação (invalidação §11, conclusão de tarefa §8, override §8.1) + os que dependem
  deles. Caso sem semente não é nem lido nem travado.
- **Propagação:** quando um entregável muda no passo, ele e seus dependentes entram na fila
  (na mesma volta se vierem depois na ordem de tipo, como no percurso completo; senão, na
  próxima). A avaliação termina quando a fila esvazia. A abertura do caso continua avaliando
  todos os entregáveis (§5 passo 6).
- **Por que é equivalente ao percurso completo.** Antes do comando o titular está num ponto
  fixo da avaliação. Um passo de entregável depende só do estado dele, do estado das suas
  dependências, da presença das suas entradas resolvidas e do fato-gatilho: se nenhum mudou, o
  passo não faz nada. Os vetores de mudança são exatamente as sementes + propagação.
- **Prova empírica, não só argumento:** (1) verificador 10/10; (2) a sequência de transições e
  eventos por caso dos cenários C1-C10 é idêntica à da versão anterior; (3)
  `scripts/diferencial_reavaliacao.py` roda a MESMA sequência aleatória (abrir, fatos coerentes
  e ruidosos, conclusões, overrides, caminho feliz até o protocolo, retificações) nos dois modos
  e compara transições, eventos, exceções, conferências, tarefas e estados finais. O script
  apanha mutantes (tirar a propagação aos dependentes dos que mudaram, ou o gatilho de
  pós-validação) e compara também o modo completo com ele mesmo, para separar divergência de
  não determinismo. Ele FAZ TRUNCATE do banco de `MOTOR_DB`: usar banco descartável.
- **Risco assumido:** se um dia entrar um caminho que mude estado de entregável fora de
  invalidação/conclusão/override sem registrar em `_Pendencias`, o recorte o perde. A regra de
  ouro (§4.1) concentra as mudanças em `transicionar`; o script diferencial é a rede de proteção.

**Ator das transições automáticas.** A semântica fixa `ator` só nos casos de ação de pessoa:
`tarefa_concluida` e `override` levam `corpo.ator` (§8 item 6, §8.1) e a invalidação leva
`sistema` (§11.2); a criação leva o ator da abertura (§5). Para o desfecho da conferência
(§9.3) ela é omissa. Estava inconsistente: `processado -> validado` gravava `sistema` e
`processado -> divergente` gravava o ator do chamador (o `ator` da abertura, da conclusão ou
`sistema`, conforme quem disparou). Decisão: **toda transição automática grava `sistema`**
(`ATOR_SISTEMA`): prontidão, processamento, conferências (ok ou divergente), liberação,
protocolo, encerramento e invalidação. O parâmetro `ator` saiu de `avaliar_caso`, `_passo` e
`_conferir_entregavel`. Efeito observável: só em `processado -> divergente` disparado por
`/concluir` ou pela abertura (antes gravava o ator da requisição, agora `sistema`); a pessoa
continua registrada em `tarefa_concluida` do mesmo entregável.

## D12 — Manutenção 3: pool de conexões

- `ConnectionPool(check=ConnectionPool.check_connection)`: ao entregar uma conexão do pool o
  psycopg a testa e descarta a morta (restart do Postgres, `pg_terminate_backend`), em vez de
  devolver `OperationalError` até alguém reiniciar o contêiner (o que o D8 observou). Custo: uma
  consulta trivial por retirada de conexão.
- `lock_timeout = 10 s` em toda conexão do pool (`options=-c lock_timeout=10000`,
  `banco.LOCK_TIMEOUT_MS`). Vale para os advisory locks (titular, chave de fato, exceção) e
  para o `FOR UPDATE` das linhas. É folga larga sobre a espera normal (p95 do lote do C6
  0,13-0,15 s); só dispara com transação travada de verdade. Estourou: o Postgres devolve
  55P03 (`LockNotAvailable`), a transação dá rollback e a API responde **409
  `conflito_concorrencia`** (o mesmo código de `ConflitoConcorrencia`; o cliente repete).
  Antes, esperar para sempre prendia a conexão do pool e a requisição.
- Espera por uma conexão livre do pool: o padrão do psycopg-pool (30 s, `PoolTimeout`)
  continua; não foi alterado porque o C6 nunca chega perto.
- Testes de integração em `tests/integracao/test_pool.py` (conexão morta descartada, mordido: sem
  `check` o teste falha; `lock_timeout` presente; espera acima do tempo vira 55P03 e 409).

## D13 — O que ficou como decisão consciente (revisão de manutenção)

- **Catálogos por conferência.** `tolerancia_em_uso`, `parametros_em_uso` e `tipo_conferencia`
  ainda são lidos a cada conferência (cache só da regra pinada, que o D7 prometeu). São 2-3
  leituras por conferência; deixar para quando o custo aparecer na medida.
- **Abertura do caso (~82-96 SQLs).** Dominada pela criação (entregável, transição, dependências,
  tarefas, eventos) e pela avaliação completa do §5 passo 6; não foi tocada.
- **Locks.** A ordem global (titular, chave, linhas) e o `SELECT ... FOR UPDATE` de todo o caso a
  cada volta ficaram: o recorte reduz quantos casos são travados, não a política de trava.

## D14 — O teste de arquitetura não verificava nada (corrigido)

Todas as afirmações anteriores de que o import-linter verifica as camadas (README, CROSS-REVIEW,
METRICAS/D-anteriores, `scripts/checar.sh`) estavam sem lastro: `python -m importlinter.cli lint`
não executa o comando (o módulo não tem `__main__`), sai com 0 e não imprime nada; o teste só
conferia o exit code. Além disso, o `lint-imports` real recusava a config: contratos `forbidden`
com módulos externos (`fastapi`, `psycopg`...) exigem `include_external_packages = True`.

Agora: `.importlinter` tem `include_external_packages = True`; `tests/unidade/test_arquitetura.py`
roda o `lint-imports` do mesmo ambiente e exige exit 0, os 3 nomes de contrato com `KEPT` e
`Contracts: 3 kept, 0 broken.`; `scripts/checar.sh` usa `lint-imports`. Provado com violações em
cópia temporária: `import psycopg` e `import fastapi` em `dominio`, `import motor.api` em
`aplicacao` e `import psycopg` em `aplicacao` quebram os contratos e fazem teste e comando
falharem (exit 1); sem violação, tudo volta a passar.
