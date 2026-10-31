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

## Fase 2 — invalidação, exceções, override, acompanhamento

- **D12. Tarefa 11 (v3) não se aplica.** A spike nasceu na v3 com a V006 em uso, então
  não há mudança de domínio a absorver. Registrado em `METRICAS.md` (seção Fase 2).
- **D13. Ordem na publicação de fato (§11.4).** `PublicarFato`: grava a versão e o
  evento `fato.publicado`; lê o estado do E11 **antes** de reavaliar (para o §10.3);
  roda a invalidação (§11) se `nova_versao`; reavalia os casos abertos do titular; por
  fim aplica o acompanhamento (§10.3) se o E11 já estava `disponibilizado`.
- **D14. Invalidação exclui o próprio entregável concluído (§8 item 4).** Em
  `ConcluirTarefa`, a saída publicada com `nova_versao` dispara a invalidação, mas o
  entregável que está sendo concluído é excluído das sementes.
- **D15. Override emite o evento do tipo (§8.1 item 4).** Quando o override leva o
  entregável a `validado`, publica-se o evento do tipo (ex.: `insumos.completos` no
  E01) via catálogo — por isso o C9 conta `insumos.completos = 2` (um por override, um
  pela reconferência depois da invalidação).
- **D16. Reconferência depois da invalidação gera nova linha de conferência.** Como o
  `entrada_hash` muda (novas versões de fato), a idempotência (§9.2) não bloqueia: o
  C9 fica com `conferencias_total = 2` para a mesma CF-01 (`divergente:B`, depois
  `ok:B`), e o override gravado na primeira conferência permanece.

## Refatoração de manutenção (PRs de camadas, testes e robustez)

- **D17. Regra malformada falha alto.** `fiscal.tolerancia` sem `padrao.ok_ate_centavos`/`alerta_ate_centavos` inteiros (0 <= ok <= alerta) ou `fiscal.parametros` sem `prolabore_percentual_bp` inteiro (0..10000) lança `RegraInvalida`; a API responde 500 `{"erro":"regra_invalida","mensagem":...}` em vez de assumir tolerância 0/0 ou pró-labore 0%. O contrato não enumera códigos de 500; este é erro de configuração do servidor, não de entrada.
- **D18. Tributo do recibo (CF-12) vem da regra.** O tributo exigido é o da entrada `recibo_obrigacao` do item cujo `quando` casa com o contexto do caso (SN: PGDAS-D, LP: DCTFWEB na regra v2). Item sem entrada aplicável é `RegraInvalida`.
- **D19. `observado_em` das saídas de tarefa = `now()` do banco** (port `Relogio`/`RelogioDb`), não o relógio do PHP: dentro da transação é o instante de início dela.
- **D20. Memória de leitura por requisição.** Regra (por id e a em uso), catálogo e dependências são lidos uma vez por requisição (repositórios `scoped`; dependências são descartadas em `gravar`). Nenhum estado sobrevive à requisição.

- **D21. Ambiguidade na §6.2: piso ou exato.** O PHP implementa a leitura PISO: reavalia todos os entregáveis de todos os casos abertos do titular a cada fato (`PublicarFato` -> `AvaliarCaso::avaliarAbertosDoTitular`). A §6 passo 2 diz o que deve ser reavaliado, sem "apenas". Sem diferencial equivalente ao do Python. Ver `.kiro/steering/divergencia-reavaliacao-spec-vs-design.md`.

## Fase 4 — manutenção M1

- **D22. `semantica_calculo` subiu para 3 na tarefa 20, não só na 21.** A constante `HashConferencia::SEMANTICA_CALCULO` passou de 2 para 3 junto com a aceitação do `sujeito_fator_r` (tarefa 20), e não apenas quando o CF-07 do LP mudou (tarefa 21). Motivo: o C11 (cenário da tarefa 20) já cobra `semantica_calculo = 3` na CF-08, porque a v4 é uma mudança de cálculo única (§9.2, "o valor atual é 3"); separar o bump por CF não faria sentido — a semântica do cálculo é um inteiro só, do motor inteiro. Nenhuma conferência nova grava mais 2.
- **D23. Regime do caso para a CF-07 vem do contexto, não de um parâmetro de regra.** `Conferidor::regimeDoCaso` lê `regime` do snapshot/contexto do caso (SN|LP) e escolhe o cálculo da CF-07 (SN soma todas as parcelas × `apurado[DAS]`; LP confere tributo a tributo). A §9.1 descreve os dois cálculos por regime; o `tributoGuia` (DAS/IRPJ) sozinho não bastava para decidir, então o regime entra explícito. A condição de *rodar* a CF-07 continua vindo da regra (`casa(quando)`); o regime só escolhe entre as duas fórmulas da mesma CF.
- **D24. Folha do mês anterior (CF-09 IRRF) é lida direto por chave, não só pelo `entregavel_fato`.** `AvaliarCaso` busca `folha` vigente em `competência − 1` com `RepositorioFato::vigente` e injeta no `FatosDoCaso` (acessor `folhaMesAnterior`), mantendo `Formulas::cf09` pura. A ligação em `entregavel_fato` (papel entrada, via `competencia_relativa: -1` da regra v3) continua acontecendo na prontidão e é o que faz a invalidação da folha de M-1 alcançar o E08 do mês M (semente cross-competência em `comFato`, §11.1) — mas o *valor* usado no cálculo é lido pela chave, para a fórmula não depender da ordem de ligação.
- **D25. Idempotência (§6 passo 2 v4, req 21.6) não exigiu código novo.** O percurso completo do PHP já é idempotente: `PublicarFato` devolve `sem_mudanca` sem gravar quando o hash não muda, e prontidão/conferência são idempotentes por chave; logo um fato sem consumidor não grava transição, evento (além do `fato.publicado`), conferência, exceção, tarefa nem `version`. As checagens `espera.sem_efeito_nos_casos` dos cenários da fase 4 passam sem alteração no motor.
- **D26. `.dockerignore` passou a excluir `bootstrap/cache/*.php`.** Um `composer install` **com dev** feito fora do build (para rodar PHPStan/Pest/Pint com o worktree montado) grava `bootstrap/cache/packages.php` referenciando pacotes de dev (ex.: Collision). Esses arquivos entravam no contexto de build e quebravam o `composer install --no-dev` da imagem (o `package:discover` tentava carregar o provider de Collision, ausente no `--no-dev`). Excluídos do contexto; o build gera os caches limpos.

## Fase 4.5 — manutenção M2

- **D27. Validação do §6.2 antes da idempotência do §6.5.** A §6.5 diz "depois das validações do §6.2 e já sob a trava". Implementado assim: o núcleo `PublicacaoDeFato` resolve o contrato (tipo ativo, fonte, qualificador/valor/payload) e **só então** consulta a `chave_idempotencia`. Uma requisição repetida com corpo válido devolve a primeira resposta (`repetido: true`) sem gravar nada; a chave manda (§6.5), por isso o corpo repetido não é recomparado.
- **D28. Esquema do contrato lido como objeto, não array associativo.** O `opis/json-schema` (draft 2020-12) exige que `properties`, etc., sejam objetos JSON. `json_decode(..., true)` troca `{}` por `[]`, e o opis recusa com `properties must be an object`. `RepositorioContratoTipoFatoDb` decodifica o `esquema` com `$assoc = false` (stdClass) e o passa direto ao validador. (Este foi o único retrabalho da M2: o C20 falhou com HTTP 500 antes da correção.)
- **D29. Mapeamento das violações do opis para a taxonomia da §6.2.** O validador caminha a árvore de erros do opis até as folhas e mapeia por `keyword`: `required` -> `obrigatorio` (campo = caminho + propriedade ausente), `type` -> `tipo`, qualquer outro (`pattern`, `minLength`, `minimum`...) -> `esquema`. O qualificador (tributo) e o `valor_centavos` são checados fora do esquema, nesta ordem, e todas as violações voltam juntas em `violacoes`.
- **D30. Comparação de `observado_em` por microssegundo.** A §6.4 compara `observado_em` como instante. `PublicacaoDeFato` compara segundos e depois microssegundos (`DateTimeImmutable` -> `U` e `u`), nunca como texto nem float, para não perder precisão no desempate `nova_versao`/`obsoleto`/`conflito_fonte`. Vigente sem `observado_em` (fato pré-v5) conta como anterior.
- **D31. Chave da trava por titular trocada para a string do contrato (§13).** A v5 fixa a chave em `'titular|' || titular_id`, idêntica nas três spikes, para que um consumidor de fila externo tome o mesmo `pg_advisory_xact_lock` sem saber a linguagem. O PHP usava `json_encode(['titular', id])` (herança pré-v5); passou a `'titular|'.$id`. A trava continua sendo o primeiro comando de `PublicarFato`, `ConcluirTarefa`, `RegistrarOverride` e de cada transação de `AbrirCompetencias`.
- **D32. Núcleo único de publicação (`PublicacaoDeFato`).** `/fatos` e cada saída de `/concluir` (§8 passo 3a) passam pelo mesmo núcleo: valida o contrato, calcula o hash v2, decide o efeito (§6.4), grava fato quando é o caso, grava o recebimento de toda publicação válida e abre `conflito_fonte`. Evita duas implementações da ordem de validação. O passo 3a valida **todas** as saídas antes de gravar qualquer uma (pré-passo `validar()`), para "nada gravado; a tarefa segue aberta".
- **D33. Testes de v4 adaptados à v5, não à toa.** Alguns testes de fase anterior assumiam comportamento que a v5 mudou e foram atualizados: (a) `VetoresFatosTest` passou a cobrir só o recorte que continua valendo end-to-end — a **forma do corpo** (§6 passo 1) recusa com 422 `corpo_invalido` antes do contrato; a antiga asserção "hash gravado = sha256 da v4" saiu, porque o hash agora é v2 (projetado por `campos_hash`) e é coberto por `HashV2Test` + C19/C23. (b) O teste "CF-01 classe S com faturamento de valor nulo" foi removido: `faturamento_mes` agora exige `valor_centavos` (não pode ser nulo), então esse fato é recusado na entrada e a CF-01 nunca chega a rodar por esse caminho; a classe S por fato ausente segue coberta pela CF-12. (c) `RevisaoCodexTest` passou a usar a fonte permitida por contrato de cada tipo e a dar `observado_em` crescente entre versões da mesma chave (senão seria `conflito_fonte` na v5). (d) `InfraBancoTest` foi alinhado à base M2: `fechamento.entregaveis` em uso é v3 e `fiscal.tolerancia` tem `alerta_ate_centavos = 10`.
- **D34. `ContratosMemoria` (fake) espelha o qualificador/valor da V009 por tipo, com esquema permissivo.** Os testes de unidade não validam esquema (isso tem cobertura própria em `HashV2Test`/integração); o fake foca a lógica de estado. Mantém `campos_hash` reais por tipo para que payloads distintos deem hashes distintos (ex.: acompanhamento por `canal`/`motivo`).

## Fase 4.5 — correções da review P2 (Codex)

- **D35. Idempotência global precisa de trava própria (§6.5).** O índice único `recebimento_idempotencia` da V009 é `(fonte, chave_idempotencia)` — **global**, não por titular. As travas de titular e de chave do fato não serializam duas requisições válidas com a mesma `(fonte, chave)` e titulares diferentes: ambas liam "sem recebimento anterior" e o 2º INSERT estourava o índice (500 `erro_interno`) em vez de devolver a primeira resposta com `repetido: true`. Correção: nova trava `RepositorioFato::travarIdempotencia(fonte, chave)` (`pg_advisory_xact_lock` sobre a string `'idem|' || fonte || '|' || chave`, a mesma nas três spikes), tomada em `PublicarFato` **antes** da trava do titular, só quando há `chave_idempotencia`. O perdedor bloqueia até o vencedor commitar, relê `porIdempotencia` e devolve o recebimento vencedor (`repetido: true`), sem fato órfão. Saídas de `/concluir` não têm chave, então não precisam da trava.
- **D36. `required` devolve TODAS as propriedades ausentes (§6.2).** O `opis/json-schema` lista em `args['missing']` só até `maxErrors` campos ausentes por objeto (padrão 1). `{"parcelas":[{}]}` em `divisao_socios` (exige `socio` e `valor_centavos`) voltava uma violação só. Correção: `Validator::setMaxErrors(100)` em `ValidadorContrato`, e o mapeamento expande cada entrada de `missing` numa `Violacao` própria (`payload.parcelas[0].socio`, `payload.parcelas[0].valor_centavos`). Assim o cliente não descobre um campo faltante por vez.
- **D37. Caminho das violações de `/concluir` prefixado com `saidas[i]` também em tributo e valor (§8 passo 3a, OpenAPI 1.4.0).** O prefixo era só do payload (`saidas[i].payload`); as violações de `tributo` e `valor_centavos` saíam nuas (`valor_centavos` em vez de `saidas[0].valor_centavos`), contra o contrato de caminho indexado. Correção: o `ValidadorContrato` recebe agora o prefixo do **item** (`''` no `/fatos`, `saidas[i]` no `/concluir`) e monta `saidas[i].tributo`, `saidas[i].valor_centavos` e `saidas[i].payload.x`; no `/fatos` os escalares seguem nus (`tributo`, `valor_centavos`, `payload.x`).
- **D38. Comparação de instante normaliza a fração à precisão do `timestamptz` (§6.4).** `FatosController::dataHoraValida` aceita até 9 dígitos de fração, mas o PostgreSQL grava `timestamptz` com 6 casas **arredondando** meio-para-o-par (`…1234567 → .123457`), enquanto `DateTimeImmutable` em PHP **trunca** (`.123456`). A comparação antiga (`U`+`u`) media o fato que chega truncado contra a vigente que o banco já arredondou, então uma republicação no mesmo instante com fração de 7+ dígitos, mesma fonte e valor diferente caía em `obsoleto` por 1 µs em vez de `conflito_fonte`. Correção: novo helper puro `App\Dominio\Fatos\Instante::microssegundos()` converte qualquer instante (o RFC 3339 da requisição ou o texto `2026-10-01 12:00:00.123457+00` que o Postgres devolve) em microssegundos inteiros, arredondando a fração em 6 casas por meio-para-o-par (só inteiros, §tech), e `PublicacaoDeFato::compararInstantes` passou a comparar esses inteiros. Testes: `tests/Unidade/InstanteTest.php` (vetores confirmados contra o Postgres 16, inclusive o carry `.9999996 → +1 s`) e `RevisaoCodexTest::test_republicacao_no_mesmo_instante_com_fracao_de_7_digitos_e_conflito_fonte`.

## Fase 4.6 — manutenção M3 (prazos, risco de multa, motivo de catálogo)

- **D39. `observado_em` passou a TRUNCAR, não arredondar (§6.4 v5.1), corrigindo a premissa da D38.** A decisão v5.1 de Adriano (30/10) é: o motor **trunca** a fração de `observado_em` para 6 dígitos (descarta o 7º em diante, sem arredondar), antes de comparar e antes de gravar, e grava já truncado — assim o Postgres recebe sempre 6 dígitos e nunca arredonda (o arredondamento dele, meio-para-o-par, levaria `.9999996` ao segundo seguinte). O PHP era o único que arredondava como o Postgres (D38); agora `Instante::microssegundos` trunca e `Instante::truncar` devolve a string de 6 dígitos usada na gravação (`PublicacaoDeFato` trunca `observado_em` na entrada). O C22 (republicação de mesmo instante, mesma fonte) é o que cobra isso. `InstanteTest` e `RevisaoCodexTest` foram reescritos para a semântica de truncamento. Fração com mais de 9 dígitos continua `422 corpo_invalido` (regex do controller). Esta tarefa (31) fica **fora** da medição da M3.
- **D40. Datas de prazo são domínio puro (§18.2); o motor continua sem relógio.** `App\Dominio\Prazos` (Data, Calendario, CatalogoObrigacoes, ItemPrazo, RegraPrazos, CalculoDePrazos) é função pura das três regras + competência + contexto. Nada lê "hoje": `VerificarPrazos` recebe `data_ref` de quem chama. As datas (`vencimento`/`meta`/`acao`) e a linha de `entregavel_prazo` são gravadas na abertura (motivo `abertura`) e recalculadas por `AplicarCalendario` (motivo `calendario_v<N>`). Testes unitários do cálculo com os vetores do próprio §18.2 (C27) em `CalculoDePrazosTest`.
- **D41. Só `fechamento.prazos` é fixada no caso; calendário e catálogo não.** `regra_prazos_versao_id` é gravada no caso na abertura (como `fechamento.entregaveis`). O calendário e o catálogo são lidos sempre "em uso": é por isso que `AplicarCalendario` existe — quando a Receita prorroga (versão nova do calendário), o prazo de um caso aberto muda. `RepositorioRegras::prazosEmUso` (abertura) e `prazosComPrazosFixos($prazosVersaoId)` (AplicarCalendario/VerificarPrazos) combinam calendário+catálogo em uso com a versão de prazos pedida.
- **D42. A resolução de `prazo_em_risco` mora na função única de transição (§18.4).** `Transicionador::transicionar`, depois de gravar o estado, resolve a `prazo_em_risco` aberta do entregável (`resolucao = 'cumprido'`) quando o novo estado atinge o `estado_cumprimento` do tipo (padrão `validado`; E11 = `disponibilizado`). Fica aqui, não em cada comando, para nenhum caminho (fato, conferência, tarefa, override) esquecer. Para saber o `estado_cumprimento` do tipo, a transição lê a regra de prazos fixada no caso.
- **D43. `prazo_em_risco` não impede o encerramento, e não muda estado.** Como manda a §9.4 (v6), `prazo_em_risco` (classe O) e `impedimento_externo` (classe S) são resolvidas por ação fora do motor (cumprimento do entregável; `ResolverImpedimento`) e **não** entram na decisão do E12 (que só olha estados dos entregáveis). `AplicarCalendario` e `VerificarPrazos` não gravam nenhuma transição (cobrado pelos cenários).
