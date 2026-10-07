---
inclusion: always
---

# Tecnologia comum às duas spikes

| Item | Decisão |
|---|---|
| Banco | PostgreSQL 16, esquema pronto em `db/migrations/` (V001–V005). Um banco por spike: `spike_dotnet`, `spike_python` |
| Acesso ao banco | SQL explícito. **Proibido** gerar migração por ORM ou alterar o esquema |
| Contêiner | Uma imagem por spike, `Dockerfile` em `spikes/<linguagem>/`, ouvindo na porta **8080** do contêiner |
| Configuração | Só por variável de ambiente. String de conexão em `MOTOR_DB` |
| Estado | Nenhum estado em memória entre requisições. O banco é a única fonte |
| Logs | stdout, uma linha JSON por evento de log |
| Fila | `eventos.fila` no próprio Postgres, consumida com `FOR UPDATE SKIP LOCKED` (quando usada) |
| Hash | SHA-256 hexadecimal minúsculo de JSON canônico (chaves em ordem ordinal, sem espaços, UTF-8 sem escape) |
| Arredondamento | Meio para o par, só com inteiros |
| Testes de integração | Contra um Postgres com as migrações aplicadas (`db/scripts/migrar.sh <banco>`) |
| Verificador | `python verificador/verificador.py --api http://localhost:<porta> --db <dsn> --todos cenarios --limpar` |

## Como subir

```sh
docker compose up -d                       # Postgres, emulador de GCS (fake-gcs-server) e migrações
docker compose --profile dotnet up -d --build   # spike .NET em http://localhost:8081
docker compose --profile python up -d --build   # spike Python em http://localhost:8082
```

DSN do banco a partir do Mac: `postgresql://spike:spike@localhost:5432/spike_dotnet` (ou `spike_python`).
