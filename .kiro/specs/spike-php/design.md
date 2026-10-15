# Design — spike PHP

## Pilha

| Item | Escolha |
|---|---|
| Runtime | PHP 8.4 com `declare(strict_types=1)` em todo arquivo |
| Web | Laravel 12, só o necessário para rotas, validação e injeção de dependência |
| Servidor | FrankenPHP (imagem `dunglas/frankenphp:1-php8.4`) em **modo clássico**, sem worker; extensão `pdo_pgsql` |
| Banco | SQL explícito pela fachada `DB` (`DB::select`, `DB::insert`, `DB::transaction`) ou PDO direto. **Proibido:** Eloquent, migrações do Laravel (`database/migrations`), `php artisan migrate` |
| Conexão | `config/database.php` com a conexão `pgsql` lendo `url => env('MOTOR_DB')`; `MOTOR_DB=pgsql://spike:spike@postgres:5432/spike_php` |
| Modelos | DTOs `final readonly class` para entrada e saída; validação do Laravel só na borda (camada `api`) |
| Tipagem | PHPStan nível `max` com Larastan, sem baseline e sem `@phpstan-ignore` |
| Lint | Laravel Pint |
| Testes | Pest 3; testes de arquitetura com `arch()` do Pest |
| Dependências | `composer.json` com versões fixadas e `composer.lock` versionado |
| Logs | Monolog em `stderr` com `JsonFormatter` (uma linha por registro) |

Se algum pacote não suportar PHP 8.4, registre em `DUVIDAS.md` e use a alternativa mais simples; não invente API.

## Armadilhas do PHP que os vetores pegam

| Armadilha | Como evitar |
|---|---|
| `json_decode(..., true)` transforma `{}` em `[]`, e o hash muda (vetor 1) | Ler o corpo cru (`$request->getContent()`) e decodificar o `payload` com objetos (`json_decode($s, false, flags: JSON_THROW_ON_ERROR)`); o hash canônico recebe objetos e listas como vieram |
| `json_encode` escapa `/` e Unicode por padrão (vetor 4) | `JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES \| JSON_THROW_ON_ERROR` |
| Ordem das chaves | Ordenação recursiva das chaves dos objetos com `strcmp` (ordem de bytes UTF-8 = ordem de código Unicode); listas mantêm a ordem |
| Laravel responde 422 com `{"message","errors"}` | Tratar `ValidationException` no `bootstrap/app.php` e devolver `{"erro","mensagem"}` |
| Número grande vira `float` | Dinheiro sempre `int`; recusar `float` na entrada de `valor_centavos` (`422`) |
| Arredondamento com `round()` usa `float` | `arred_meio_par` só com `intdiv` e `%` |

## Pastas

```
spikes/php/
  composer.json  composer.lock  phpstan.neon  pint.json
  app/
    Dominio/          sem Illuminate, sem PDO; módulos: Fechamento, Fatos, Conferencias, Regras, Trabalho, Eventos
    Aplicacao/        casos de uso; interfaces de repositório
    Infraestrutura/   repositórios com SQL explícito; unidade de trabalho (transação); leitura de regras
    Api/              rotas, controllers finos, validação, mapeamento de erros
  bootstrap/  config/  routes/api.php  public/index.php
  tests/
    Unidade/          domínio puro com os vetores dos requisitos
    Arquitetura/      arch(): Dominio não usa Illuminate nem PDO; Aplicacao não usa Api nem Infraestrutura
  Dockerfile  Caddyfile
  README.md  DUVIDAS.md  METRICAS.md  REGISTRO-COMPILADOR.md
```

`REGISTRO-COMPILADOR.md` registra cada erro barrado pelo **PHPStan** antes de qualquer teste.

## Tipos de domínio

- `Competencia` (`final readonly class`, `AAAAMM`, com `somar(int $meses)` e `mesDoTrimestre()`).
- Centavos como `int` (nunca `float`) e `arredMeioPar(int $numerador, int $denominador): int`.
- `EstadoEntregavel` (`enum` com valores `string`) e `ordem(): ?int`.
- `casa(array $quando, Contexto $ctx): bool`.
- `hashCanonico(object|array $obj): string`.
- Fórmulas das conferências como funções puras que recebem os fatos já lidos.

## Fluxo principal (igual nas três spikes)

Cada requisição que muda estado roda **numa transação**. Dentro dela:

```
AbrirCompetencias(corpo):
  para cada empresa:
    transação:
      se caso existe → existentes; continuar
      regra ← versão em uso de 'fechamento.entregaveis'
      ctx ← contexto(snapshot, competencia)                 # §3
      criar caso; para cada item da regra com casa(item.quando, ctx):
          criar entregável em aguardando_insumo + transição de criação
      para cada dependência com casa(dep.quando, ctx) e alvo existente: gravar
      evento competencia.aberta
      AvaliarCaso(caso)

PublicarFato(f):
  transação:
    h ← hash(f)                                             # §6
    vigente ← fato vigente da chave
    se vigente.hash = h → retornar sem_mudanca
    inserir versão; evento fato.publicado
    (fase 2: se nova_versao → invalidação §11)
    para cada caso aberto do titular: AvaliarCaso(caso)     # superconjunto seguro de §6.2

ConcluirTarefa(caso, tipo, corpo):
  transação:
    validar (404/409/422)                                   # §8
    para cada saída: PublicarFato(sem reavaliar); ligar 'saida'
    concluir tarefa; transicionar pronto→processado (tarefa_concluida)
    Conferir(entregável)
    para cada caso aberto do titular: AvaliarCaso(caso)

AvaliarCaso(caso):                                          # §7, §10
  repetir até nenhuma mudança:
    carregar entregáveis do caso com trava (FOR UPDATE), em ordem de tipo
    para cada entregável E:
      se E ∈ {aguardando_insumo, invalidado}:
         se dependências ≥ mínimo e entradas resolvidas presentes:
             → pronto (prontidao); ligar entradas; se humano: criar tarefa
         senão se E = invalidado: → aguardando_insumo (prontidao)
      senão se E = pronto e executor = sistema:
         → processado (processamento_sistema); Conferir(E)
      senão se E = validado e item.pos_validacao.liberacao = automatica:
         → liberado (liberacao_automatica)
      senão se E = validado e item.encerra_caso:
         → encerrado (encerramento); caso encerrado; evento fechamento.concluido; parar
      senão se E = liberado e existe fato disponibilizado_quando[tributo da guia]:
         → disponibilizado (protocolo); evento documento.disponibilizado
           com payload {entregavel_id, fato_id = versão vigente da guia de entrada do E11}   # v3 §10.1

Conferir(E):                                                # §9
  para cada CF do item com casa(cf.quando, ctx):
     calcular esperado/obtido pela fórmula; classificar pela tolerância
     inserir conferência (ON CONFLICT DO NOTHING pela chave de idempotência)
     se divergente e severidade B: exceção + eventos
  → divergente (se alguma B divergente) ou → validado + evento do tipo

Transicionar(E, para, motivo, causado_por, ator):           # §4.1
  UPDATE entregável (estado, estado_desde, version+1)
  INSERT transição (com regra_versao_id do caso)
```

"Tributo da guia" do E11 = tributo da entrada `guia` resolvida para o caso (DAS no Simples, IRPJ no Presumido).

## Decisões fixas (não reabrir)

> **Nota:** a decisão "Reavaliação após fato" saiu desta tabela: a §6 passo 2 da spec é ambígua (piso ou exato) e o design não tem autoridade para decidir. Ver "Interpretações em aberto" logo abaixo e `.kiro/steering/divergencia-reavaliacao-spec-vs-design.md`.

| Tema | Decisão |
|---|---|
| Processamento nesta fase | Síncrono, dentro da requisição. `/admin/fila` devolve `{"pendentes":0,"com_erro":0}` |
| `observado_em` das saídas de tarefa | Instante atual do servidor |
| Ordem de avaliação | Entregáveis em ordem de `tipo` (E01, E02, …) dentro de cada volta |
| Erros | Corpo `{"erro": "<codigo>", "mensagem": "<texto>"}`; códigos curtos em snake_case |
| Versão da regra | Sempre a versão **em uso** (`status IN ('provisoria','ativa')`), lida na abertura e guardada no caso. Nunca `versao = 1` no código (v3: a V006 publicou a v2) |
| Estado `pago` | Aposentado na v3. O código não deve ter nenhum caminho para ele |
| Hora | Sempre do banco (`now()`, `clock_timestamp()`) para gravação; nunca do relógio local para ordenar transições |

## Interpretações em aberto (sem autoridade sobre a spec)

| Tema | Interpretação | 
|---|---|
| Reavaliação após fato | Todos os casos **abertos** do titular (superconjunto seguro de §6.2) ⚠ interpretação do §6 passo 2 (piso x exato em aberto); ver .kiro/steering/divergencia-reavaliacao-spec-vs-design.md |

## Fase 2 · acréscimos ao fluxo

```
PublicarFato(f):                                       # §6, §11.4
  transação:
    ... grava versão; evento fato.publicado
    e11_antes ← estado do E11 do caso (titular, competência), se existir     # v3 §10.3
    se nova_versao: Invalidar(versão anterior, fato novo, excluir = nenhum)
    para cada caso aberto do titular: AvaliarCaso(caso)
    se gravou e e11_antes = disponibilizado: Acompanhar(caso, E11, fato, nova_versao)

Acompanhar(caso, E11, f, nova_versao):                 # v3 §10.3 — caso aberto OU encerrado; nunca transiciona
  se f.tributo ≠ tributo da guia de entrada do E11: nada
  conforme f.tipo (nomes vêm do bloco 'acompanhamento' e de 'pos_validacao' da regra):
    documento_disponibilizado e nova_versao:  evento documento.disponibilizado (com fato_id da guia)
                                              resolver exceções abertas 'entrega_falhou' do E11 ('reenviado')
    entrega_falhou:        AbrirExcecao(E11, 'entrega_falhou', classe O)
    entrega_confirmada:    evento recebimento.confirmado {entregavel_id}
                           resolver abertas 'entrega_falhou' e 'recebimento_pendente' do E11 ('recebido')
    recebimento_pendente:  se não existe fato vigente entrega_confirmada com a mesma chave:
                               AbrirExcecao(E11, 'recebimento_pendente', classe O)

ConcluirTarefa(...):                                   # §8 item 4
    para cada saída: r ← PublicarFato(sem reavaliar, excluir = este entregável)
                     ligar r.fato_id como 'saida' (também se sem_mudanca)

Invalidar(anterior, novo, excluir):                    # §11
  sementes ← entregáveis com 'anterior' em entregavel_fato, menos 'excluir', por caso, em ordem de tipo
  por caso:
    se encerrado: AbrirExcecao(caso, sem entregável, 'competencia_encerrada'); continuar
    fila ← sementes do caso; visitados ← {}
    enquanto fila:
      E ← próximo; se E ∈ visitados: continuar; marcar
      se E.estado ∈ {pronto, processado, divergente, validado, liberado}:
          Transicionar(E, invalidado, 'fato_alterado', causado_por = fato novo)
          evento entregavel.invalidado; cancelar tarefa aberta de E
          se veio de divergente: resolver exceções CF- de E ('fato_alterado')
          fila += dependentes de E (em ordem de tipo)
      senão se disponibilizado: AbrirExcecao(E, 'substituicao')

AbrirExcecao(...):                                     # §9.4
  se já existe aberta do mesmo tipo (no entregável, ou no caso sem entregável): não faz nada
  senão: insere e emite excecao.aberta

RegistrarOverride(caso, tipo, corpo):                  # §8.1
  422 → 404 → 409; grava override na conferência vigente da CF
  resolve exceções da CF ('override'); evento conferencia.override
  se nenhuma outra CF vigente divergente B sem override: divergente → validado ('override') + evento do tipo
  para cada caso aberto do titular: AvaliarCaso(caso)
```

## Fase 3 · escala

- Medir antes de otimizar. Os pontos prováveis: uma transação por empresa na abertura, leituras repetidas de catálogo e regra (podem ser lidas uma vez por requisição), e a reavaliação de todos os casos do titular a cada fato.
- Fila assíncrona (`eventos.fila`) só se a medida mostrar necessidade (requisito 19.4).
- O modo worker do FrankenPHP (Laravel Octane) só se a medida mostrar que o custo de subir o Laravel a cada requisição é o gargalo. Nesse caso, registrar em `DUVIDAS.md` e provar que nada de negócio fica em memória entre requisições (requisito 1.4).

