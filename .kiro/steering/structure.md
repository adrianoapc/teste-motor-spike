---
inclusion: always
---

# Estrutura do repositório

```
docker-compose.yml          base comum (SÓ LEITURA)
db/
  init/                     cria os bancos spike_dotnet e spike_python (SÓ LEITURA)
  migrations/V001..V005     esquema e regras provisórias (SÓ LEITURA)
  scripts/migrar.sh         aplica as migrações (SÓ LEITURA)
contrato/openapi.yaml       contrato HTTP idêntico nas duas spikes (SÓ LEITURA)
docs/
  semantica-do-motor.md     O QUE o motor faz. Fonte da verdade (SÓ LEITURA)
  regras-do-jogo.md         como as spikes são comparadas (SÓ LEITURA)
cenarios/c*.json            cenários executados pelo verificador (SÓ LEITURA)
verificador/                juiz comum (SÓ LEITURA)
massa/, anonimizacao/       scripts de dados (SÓ LEITURA)
.kiro/specs/spike-dotnet/   spec da spike .NET
.kiro/specs/spike-python/   spec da spike Python
spikes/dotnet/              ÚNICA pasta editável pela spike .NET
spikes/python/              ÚNICA pasta editável pela spike Python
```

## Dentro de cada spike (camadas)

| Camada | Pode depender de | Não pode depender de |
|---|---|---|
| `dominio` | nada além da biblioteca padrão | banco, HTTP, framework web, aplicação |
| `aplicacao` | `dominio` | HTTP, framework web, driver de banco concreto |
| `infraestrutura` | `dominio`, `aplicacao` | `api` |
| `api` | `aplicacao`, `infraestrutura` (só para montar dependências) | — |

Módulos de negócio (pastas ou namespaces dentro de cada camada): `fechamento`, `fatos`, `conferencias`, `regras`, `trabalho`, `eventos`. Um módulo não lê tabela de outro módulo diretamente; usa a interface pública do outro módulo na camada de aplicação.

Arquivos obrigatórios em cada spike:
- `README.md`: como construir, testar e rodar.
- `DUVIDAS.md`: tudo o que a semântica não respondeu e a decisão tomada.
- `METRICAS.md`: preenchido ao final (modelo em `docs/regras-do-jogo.md`).
- `REGISTRO-COMPILADOR.md`: cada vez que o compilador (.NET) ou o mypy (Python) barrar um erro antes de qualquer teste, uma linha com data, arquivo e o tipo do erro.
