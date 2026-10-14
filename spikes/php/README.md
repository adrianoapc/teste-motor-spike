# Spike PHP — motor do fechamento

Protótipo descartável do motor de fechamento (Rissi), em **Laravel 12 / PHP 8.4**,
sem Eloquent e sem migrações do Laravel. SQL explícito pela fachada `DB`. É uma das
três spikes (`.NET`, Python, PHP) comparadas pelo mesmo verificador. Semântica
normativa: `docs/semantica-do-motor.md`. Contrato HTTP: `contrato/openapi.yaml`.

## Pilha

- PHP 8.4 (`declare(strict_types=1)` em todo arquivo), Laravel 12.
- FrankenPHP (`dunglas/frankenphp:1-php8.4`) em modo clássico, `pdo_pgsql`.
- PostgreSQL 16 (esquema pronto em `db/migrations/`), SQL explícito.
- PHPStan nível `max` (Larastan), Laravel Pint, Pest 3.

## Limites de execução

`/competencias/abrir` abre uma transação por empresa (~35 ms cada no ambiente de teste). O Dockerfile grava
`conf.d/zz-motor.ini` com `max_execution_time=900` e `memory_limit=1024M`: medido com 2.500 empresas (87,6 s, 2.500
criados, HTTP 200) e com 10.000 empresas só na validação (2,9 s, 422 na última). O pior caso contratual (10.000 criações,
~6 min estimados) não foi rodado de ponta a ponta. FrankenPHP/Caddy não impõem timeout de escrita por padrão.

## Camadas (`app/`)

| Camada | Pode depender de |
|---|---|
| `Dominio` | nada além da biblioteca padrão |
| `Aplicacao` | `Dominio` |
| `Infraestrutura` | `Dominio`, `Aplicacao` |
| `Api` | `Aplicacao`, `Infraestrutura` (só para montar dependências) |

Módulos: `Fechamento`, `Fatos`, `Conferencias`, `Regras`, `Trabalho`, `Eventos`.

## Como subir

Na raiz do repositório (a base comum sobe o Postgres e aplica as migrações):

```sh
docker compose up -d
docker compose --profile php up -d --build    # spike PHP em http://localhost:8083
curl localhost:8083/health                     # {"status":"ok"}
curl localhost:8083/admin/fila                 # {"pendentes":0,"com_erro":0}
```

A conexão vem só da variável `MOTOR_DB` (string de conexão `pgsql://…`).

## Testes e qualidade (dentro do contêiner)

```sh
docker compose --profile php exec spike-php composer install
docker compose --profile php exec spike-php ./vendor/bin/pest
docker compose --profile php exec spike-php ./vendor/bin/phpstan analyse
docker compose --profile php exec spike-php ./vendor/bin/pint --test
```

## Verificador (fase 1)

```sh
python verificador/verificador.py --api http://localhost:8083 \
  --db postgresql://spike:spike@localhost:5432/spike_php \
  --todos cenarios --fase 1 --limpar
```

## Documentos

- `DUVIDAS.md` — decisões não cobertas pela semântica.
- `METRICAS.md` — medidas da comparação (modelo em `docs/regras-do-jogo.md`).
- `REGISTRO-COMPILADOR.md` — erros barrados pelo PHPStan antes de qualquer teste.
