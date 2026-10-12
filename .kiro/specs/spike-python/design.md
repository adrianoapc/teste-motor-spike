# Design — spike Python

## Pilha

| Item | Escolha |
|---|---|
| Runtime | Python 3.13 (imagem `python:3.13-slim`) |
| Web | FastAPI + uvicorn |
| Banco | psycopg 3 (`psycopg[binary]`), SQL explícito, `dict_row`. **Sem** ORM de migração |
| Modelos | pydantic v2 para corpo de requisição e resposta |
| JSON canônico | `json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False)` |
| Tipagem | `mypy --strict` sem `# type: ignore` |
| Lint | ruff |
| Testes | pytest; testes de arquitetura com import-linter (contratos de camadas) |
| Dependências | `pyproject.toml` com versões fixadas |
| Logs | `logging` com formatador JSON simples (uma linha por registro) |

Se algum pacote não suportar Python 3.13, registre em `DUVIDAS.md` e use a alternativa mais simples; não invente API.

## Pacotes

```
spikes/python/
  pyproject.toml
  src/motor/
    dominio/          sem import de fastapi, psycopg, pydantic; módulos: fechamento, fatos, conferencias, regras, trabalho, eventos
    aplicacao/        casos de uso; protocolos (typing.Protocol) de repositório
    infraestrutura/   repositórios psycopg; unidade de trabalho (transação); leitura de regras
    api/              app FastAPI, rotas, modelos pydantic, mapeamento de erros
  tests/
    unidade/          domínio puro com os vetores dos requisitos
  .importlinter       contratos: dominio não importa nada do projeto; aplicacao não importa api nem psycopg
  Dockerfile
  README.md  DUVIDAS.md  METRICAS.md  REGISTRO-COMPILADOR.md
```

## Tipos de domínio

- `Competencia` (dataclass imutável, `AAAAMM`, com `somar(meses: int)` e `mes_do_trimestre`).
- `Centavos = NewType("Centavos", int)` e `arred_meio_par(numerador: int, denominador: int) -> int`.
- `EstadoEntregavel` (`enum.StrEnum` com os 10 estados) e `ordem(estado) -> int | None`.
- `casa(quando: Sequence[Mapping[str, object]], ctx: Contexto) -> bool`.
- `hash_canonico(obj: Mapping[str, object]) -> str`.
- Fórmulas das conferências como funções puras que recebem os fatos já lidos.

## Fluxo principal (igual nas duas spikes)

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

| Tema | Decisão |
|---|---|
| Processamento nesta fase | Síncrono, dentro da requisição. `/admin/fila` devolve `{"pendentes":0,"com_erro":0}` |
| Reavaliação após fato | Todos os casos **abertos** do titular (superconjunto seguro de §6.2) |
| `observado_em` das saídas de tarefa | Instante atual do servidor |
| Ordem de avaliação | Entregáveis em ordem de `tipo` (E01, E02, …) dentro de cada volta |
| Erros | Corpo `{"erro": "<codigo>", "mensagem": "<texto>"}`; códigos curtos em snake_case |
| Versão da regra | Sempre a versão **em uso** (`status IN ('provisoria','ativa')`), lida na abertura e guardada no caso. Nunca `versao = 1` no código (v3: a V006 publicou a v2) |
| Estado `pago` | Aposentado na v3. O código não deve ter nenhum caminho para ele |
| Hora | Sempre do banco (`now()`, `clock_timestamp()`) para gravação; nunca do relógio local para ordenar transições |

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

