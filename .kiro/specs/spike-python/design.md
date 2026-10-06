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
         → disponibilizado; evento documento.disponibilizado
      senão se E = disponibilizado e existe fato pago_quando[tributo da guia]:
         → pago; evento guia.paga

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
| Hora | Sempre do banco (`now()`, `clock_timestamp()`) para gravação; nunca do relógio local para ordenar transições |
