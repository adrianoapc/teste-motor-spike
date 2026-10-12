# Dúvidas e decisões — spike PHP

Tudo o que a semântica (`docs/semantica-do-motor.md`) não respondeu, com a decisão
tomada e por quê. Regra: não inventar comportamento; seguir o que está escrito e
registrar o resto aqui.

## Tarefa 1 — esqueleto e contêiner

- **D1. Servidor PHP.** O design manda FrankenPHP em modo clássico (sem worker),
  para garantir que nada de negócio fique em memória entre requisições
  (Requisito 1.4). Decisão: `dunglas/frankenphp:1-php8.4`, `php_server` com a raiz
  em `public/`, `auto_https off` (só HTTP no spike).
- **D2. `APP_KEY`.** O Laravel exige uma chave para subir, mas o spike não cifra
  nada sensível. Decisão: chave fixa na imagem. Não há sessão nem cookie cifrado.
- **D3. `/admin/fila` nesta fase.** O processamento é síncrono dentro da requisição
  (design: decisões fixas), então a fila fica vazia. Mesmo assim a rota **consulta**
  `eventos.fila` de verdade (contagem por `concluido_em`/`ultimo_erro`), para o
  contrato continuar honesto se a fila for usada na fase 3 (Requisito 10.1/10.2).
- **D4. Erros.** O contrato pede `{"erro","mensagem"}`. O Laravel responde 422 com
  `{"message","errors"}`; `App\Api\ErrosHttp` traduz `ValidationException` →
  `{"erro":"corpo_invalido", ...}` e `ErroDeNegocio` → código + status próprios.
- **D5. PHPStan e a DSL de arquitetura do Pest.** O nível `max` não consegue tipar
  a cadeia `arch()->expect()->toUse()`: `arch()` devolve `Pest\PendingCalls\TestCall`,
  que não declara `expect()` — a cadeia é resolvida por `__call` em runtime, e não há
  plugin PHPStan do Pest que a tipe. Decisão: excluir **só** o arquivo de DSL
  (`tests/Arquitetura/CamadasTest.php`) da análise via `excludePaths`. Isso **não** é
  baseline (lista de erros tolerados) nem `@phpstan-ignore` (ambos proibidos pelo
  Requisito 12.1): é o escopo de quais arquivos são analisados. Todo o `app/`
  continua sob `max`, que é a superfície que o critério 5 (rede de proteção) mede.

## Tarefas 4 a 9 — motor

- **D6. Payload cru para preservar `{}` vs `[]`.** O corpo de `/fatos` e das saídas
  de `/concluir` é lido com `json_decode($request->getContent(), false)` (objetos),
  não pela representação associativa do Laravel (que colapsaria `{}` em `[]` e mudaria
  o hash, vetor 1 do Requisito 4.2). A validação do contrato roda sobre a versão
  associativa; o payload de negócio vem do corpo cru.
- **D7. `observado_em` das saídas de tarefa.** §8 manda `observado_em = agora`.
  Usei o instante do servidor no momento da conclusão (`DateTimeImmutable::ATOM`),
  conforme a decisão fixa do design.
- **D8. Reavaliação após fato.** §6.2 pede reavaliar os entregáveis afetados; o
  design autoriza o superconjunto seguro "todos os casos abertos do titular". Segui
  o superconjunto, mais simples e já coberto pelos cenários.
- **D9. Fase 1 sem invalidação.** §11 (invalidação por nova versão de fato) é da
  fase 2. Na fase 1, `PublicarFato` e `ConcluirTarefa` apenas gravam a nova versão
  e reavaliam; não há cascata. C1 e C7 não exercitam `nova_versao` de fato já ligado.
- **D10. `fato_id` do `documento.disponibilizado`.** §10.1/INV-14: o evento leva o
  `fato_id` da **guia** de entrada do E11 (não o do fato de protocolo). O fato de
  protocolo (`documento_disponibilizado`) é só o gatilho da transição; o id gravado
  no evento é o da guia vigente do tributo do regime (DAS no SN, IRPJ no LP).
- **D11. `/admin/fila` síncrono.** Processamento dentro da requisição (decisão fixa);
  a fila fica sempre vazia e `pendentes = 0` quando a resposta volta (Requisito 10.2).
