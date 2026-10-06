# Design — spike .NET 10

## Pilha

| Item | Escolha |
|---|---|
| Runtime | .NET 10 (SDK e imagem `mcr.microsoft.com/dotnet/sdk:10.0` para build, `mcr.microsoft.com/dotnet/aspnet:10.0` para execução) |
| Web | ASP.NET Core, minimal API |
| Banco | Npgsql (driver) + Dapper (mapeamento). **Sem** Entity Framework migrations |
| JSON | `System.Text.Json`. Para o hash canônico, ordenar chaves com `StringComparer.Ordinal`, sem espaços, `JavaScriptEncoder.UnsafeRelaxedJsonEscaping` (não escapar acentos) |
| Testes | xUnit; testes de arquitetura com NetArchTest.Rules |
| Compilação | `Nullable` habilitado, `TreatWarningsAsErrors` em todos os projetos |
| Logs | `Microsoft.Extensions.Logging` com console JSON (`AddJsonConsole`) |

Se algum pacote não existir na versão compatível com .NET 10, registre em `DUVIDAS.md` e use a alternativa mais simples; não invente API.

## Projetos

```
spikes/dotnet/
  Motor.sln
  src/
    Motor.Dominio/          sem dependência de pacote; módulos em pastas: Fechamento, Fatos, Conferencias, Regras, Trabalho, Eventos
    Motor.Aplicacao/        casos de uso (AbrirCompetencias, PublicarFato, ConcluirTarefa, AvaliarCaso, Conferir); interfaces de repositório
    Motor.Infraestrutura/   repositórios Npgsql/Dapper; unidade de trabalho (transação); leitura de regras
    Motor.Api/              Program.cs, rotas, validação do corpo, mapeamento de erros
  tests/
    Motor.Testes.Unidade/       domínio puro com os vetores dos requisitos
    Motor.Testes.Arquitetura/   NetArchTest: dependências entre camadas
  Dockerfile
  README.md  DUVIDAS.md  METRICAS.md  REGISTRO-COMPILADOR.md
```

Referências de projeto permitidas: Aplicacao → Dominio; Infraestrutura → Aplicacao, Dominio; Api → Aplicacao, Infraestrutura.

## Tipos de domínio

- `Competencia` (value object, `AAAAMM`, com `Somar(int meses)` e `MesDoTrimestre`).
- `Centavos` (wrapper de `long`) e `Arredondamento.MeioParaPar(long numerador, long denominador)`.
- `EstadoEntregavel` (enum com os 10 estados) e `Ordem(EstadoEntregavel)` → `int?`.
- `Condicao.Casa(IReadOnlyList<IReadOnlyDictionary<string, JsonElement>> quando, Contexto ctx)`.
- `HashCanonico.Calcular(JsonNode)`.
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
