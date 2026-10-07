# Spike Python — motor do fechamento

Núcleo em Python 3.13 + FastAPI + psycopg 3, SQL explícito, sem ORM de migração.
Fase 1: cenários **C1** e **C7**. Semântica normativa: `docs/semantica-do-motor.md`.
Contrato: `contrato/openapi.yaml`. Porta no host: **8082**. Banco: **spike_python**.

## Arquitetura (camadas)

```
src/motor/
  dominio/          puro: competência, arredondamento, hash, condição `quando`, fórmulas CF
  aplicacao/        casos de uso + protocolos de repositório (typing.Protocol)
  infraestrutura/   repositórios psycopg, unidade de trabalho (1 transação por comando)
  api/              app FastAPI, rotas, modelos pydantic, mapeamento de erros
```

Dependências permitidas: `api → infraestrutura/aplicacao`, `infraestrutura → aplicacao/dominio`,
`aplicacao → dominio`, `dominio → nada do projeto`. Verificado por `.importlinter` e por teste.

## Rodar com Docker (recomendado — igual ao avaliador)

Na raiz do repositório:

```sh
docker compose up -d                              # Postgres + migrações
docker compose --profile python up -d --build     # esta spike em http://localhost:8082
curl -s localhost:8082/health                      # {"status":"ok"}
```

> Em ambientes só com `docker-compose` v1: use `docker-compose up -d postgres migrar`
> e `docker-compose --profile python up -d --build`.

## Rodar local (desenvolvimento)

```sh
cd spikes/python
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"
export MOTOR_DB="postgresql://spike:spike@localhost:5432/spike_python"
uvicorn motor.api.app:app --host 127.0.0.1 --port 8082
```

A conexão vem **apenas** de `MOTOR_DB`; nenhum valor de conexão no código.

## Testes

```sh
cd spikes/python
pip install -e ".[dev]"
pytest                       # unidade + arquitetura
mypy --strict src            # sem # type: ignore
ruff check .
lint-imports                 # contratos de camadas
```

## Verificador (juiz comum)

```sh
python verificador/verificador.py \
  --api http://localhost:8082 \
  --db postgresql://spike:spike@localhost:5432/spike_python \
  --todos cenarios --limpar
# esperado: RESULTADO: todos passaram (2/2)
```

## Decisões fixas desta fase

- Processamento **síncrono** dentro da requisição; `/admin/fila` sempre `{"pendentes":0,"com_erro":0}`.
- Reavaliação após fato: todos os casos **abertos** do titular (superconjunto seguro de §6.2).
- Dinheiro é inteiro em centavos; arredondamento meio para o par só com inteiros.
- Hora de gravação sempre do banco (`now()`/`clock_timestamp()`).
