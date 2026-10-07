# Spike .NET 10 — motor do fechamento

Protótipo descartável do motor que fecha a competência mensal de cada empresa.
Implementa o contrato comum (`contrato/openapi.yaml`) e a semântica normativa
(`docs/semantica-do-motor.md`). Juiz: `verificador/verificador.py` nos cenários
`cenarios/*.json`. Esta fase cobre **C1** e **C7**.

## Pilha

- .NET 10, ASP.NET Core minimal API (porta 8080 no contêiner).
- PostgreSQL via **Npgsql** (driver) + **Dapper** (mapeamento). SQL explícito;
  **sem** EF Core, sem migração por ORM. O esquema é o de `db/migrations/`.
- Hash canônico com `System.Text.Json` montado à mão (chaves ordinais, sem
  espaços, UTF-8 sem escapar acentos).
- Testes: xUnit (unidade) + NetArchTest (arquitetura).
- `Nullable` habilitado e `TreatWarningsAsErrors=true` em todos os projetos
  (ver `Directory.Build.props`).

## Camadas (ver `.kiro/steering/structure.md`)

```
src/Motor.Dominio/        value objects e lógica pura: Competencia, Centavos/arred,
                          EstadoEntregavel, Condicao (quando), HashCanonico,
                          EntradaHash, Classificacao, RegraEntregaveis. Sem I/O.
src/Motor.Aplicacao/      casos de uso (AbrirCompetencias, PublicarFato,
                          ConcluirTarefa), AvaliarCaso (ponto fixo), Conferir,
                          CalculadoraConferencias; interfaces de repositório (Portas.cs).
src/Motor.Infraestrutura/ repositórios Npgsql/Dapper, unidade de trabalho (transação),
                          leitura das regras em uso e dos catálogos.
src/Motor.Api/            Program.cs (rotas), validação de corpo (422), mapeamento de erro.
tests/Motor.Testes.Unidade/     vetores obrigatórios dos requisitos (domínio puro).
tests/Motor.Testes.Arquitetura/ NetArchTest: dependências entre camadas.
```

Dependências de projeto: Aplicacao → Dominio; Infraestrutura → Aplicacao, Dominio;
Api → Aplicacao, Infraestrutura. O domínio não referencia Npgsql, Dapper nem ASP.NET.

## Configuração

Só por variável de ambiente. A string de conexão vem de **`MOTOR_DB`** (formato
Npgsql, ex.: `Host=postgres;Port=5432;Database=spike_dotnet;Username=spike;Password=spike`).
Nenhum valor de conexão no código ou na imagem. Sem estado de negócio em memória
entre requisições: cada comando abre a sua própria transação (ADR-014).

## Como construir, testar e rodar

O SDK roda em contêiner (não exige .NET no host).

### Testes (unidade + arquitetura)

```sh
docker run --rm -v "$PWD":/w -w /w mcr.microsoft.com/dotnet/sdk:10.0 \
  dotnet test Motor.sln -c Release
```

### Subir pela raiz do repositório (como o avaliador faz)

```sh
docker compose up -d                              # Postgres + migrações
docker compose --profile dotnet up -d --build     # spike .NET em http://localhost:8081
curl localhost:8081/health                        # {"status":"ok"}
```

> Em máquinas sem o plugin `docker compose`, use o binário `docker-compose` com
> os mesmos argumentos.

### Verificador (barra de aceite)

```sh
python verificador/verificador.py \
  --api http://localhost:8081 \
  --db postgresql://spike:spike@localhost:5432/spike_dotnet \
  --todos cenarios --limpar
# RESULTADO: todos passaram (2/2)
```

## Rotas

| Método | Rota | Semântica |
|---|---|---|
| GET  | `/health` | saúde do serviço e do banco (`SELECT 1`) |
| GET  | `/admin/fila` | `{"pendentes":0,"com_erro":0}` (processamento síncrono nesta fase) |
| POST | `/competencias/abrir` | §5 |
| POST | `/fatos` | §6 |
| POST | `/casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir` | §8 |
| GET  | `/casos/{titular_id}/{competencia}` | consulta do caso |

Logs: uma linha JSON por registro em stdout (`AddJsonConsole`).

## Decisões fixas desta fase

- Processamento síncrono dentro da requisição; `/admin/fila` sempre devolve 0.
- Reavaliação após fato: todos os casos **abertos** do titular (superconjunto
  seguro de §6.2) — é o que cobre a reavaliação por `competencia_relativa` do C7.
- Datas gravadas pelo banco (`now()`, `clock_timestamp()`); `observado_em` da
  requisição é convertido para UTC antes de gravar em `timestamptz`.

Fora desta fase: invalidação/cascata (§11), override, cenários 2–6, fila
assíncrona, autenticação, RLS, conectores reais. Ver `DUVIDAS.md`.
