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
todos os casos abertos do titular** como superconjunto seguro. Adotado — é correto e simples;
o custo extra é irrelevante na escala de C1/C7.

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
