# Spike do motor do fechamento — Rissi

Protótipo **descartável** para decidir duas coisas com medida:
1. se o motor do fechamento funciona como **tabela de prontidão em Postgres**;
2. qual linguagem é melhor para o núcleo do sistema novo: **.NET 10** ou **Python**.

As duas spikes implementam o mesmo comportamento e passam pelo mesmo juiz. Quem perde sai; quem ganha é reescrita com rigor de produção depois do ADR-013. A **base comum** (esquema, cenários, verificador, contrato) não é descartável: vira o ponto de partida do motor de produção.

Contexto e decisões: spec do spike v0.3 e ADR-014 no projeto Rissi.

## Subir no Mac

Requisito: Docker (Docker Desktop, OrbStack ou Colima) e Python 3.12+ para o verificador.

```sh
docker compose up -d                             # Postgres 16, emulador de GCS (fake-gcs-server) e migrações nos dois bancos
docker compose logs migrar                       # deve terminar com "migrações ok"

docker compose --profile dotnet up -d --build    # spike .NET   → http://localhost:8081
docker compose --profile python up -d --build    # spike Python → http://localhost:8082
```

Tudo fica preso em `127.0.0.1`. Nada é exposto para a rede.

## Rodar o verificador

```sh
python3 -m venv .venv && . .venv/bin/activate
pip install -r verificador/requirements.txt

python verificador/verificador.py --api http://localhost:8081 \
  --db postgresql://spike:spike@localhost:5432/spike_dotnet \
  --todos cenarios --fase 2 --limpar --relatorio resultado-dotnet.json

python verificador/verificador.py --api http://localhost:8082 \
  --db postgresql://spike:spike@localhost:5432/spike_python \
  --todos cenarios --fase 2 --limpar --relatorio resultado-python.json
```

O verificador chama a API de cada cenário, confere o banco, checa 13 invariantes (transições, versões de fatos, eventos, conferências, exceções, tarefas, override) e mede as 5 consultas de indicador. `--fase N` roda as fases 1 a N.

| Fase | Cenários | O que cobre |
|---|---|---|
| 1 | C1, C7 | Caminho feliz do Simples com folha; LP no 3º mês até a apuração |
| 2 | C2, C3, C4, C5, C8, C9 | Invalidação e cascata, exceções, competência encerrada, override, encerramento do LP |
| 3 | C6 | Escala: 4.500 casos e 300 guias em lote |

Mapa completo de cobertura: `docs/cobertura-cenarios.md`. Os cenários são gerados por `cenarios/fonte/gerar_cenarios.py`.

## O que tem aqui

| Pasta | O quê | Quem altera |
|---|---|---|
| `db/` | Esquema (V001–V005), regras **provisórias**, script de migração | Só o Adriano |
| `contrato/openapi.yaml` | API idêntica nas duas spikes | Só o Adriano |
| `docs/semantica-do-motor.md` | **O que** o motor faz. Fonte da verdade | Só o Adriano |
| `docs/regras-do-jogo.md` | Como as spikes são comparadas | Só o Adriano |
| `cenarios/` | C1 a C9, em três fases (gerados por `cenarios/fonte/gerar_cenarios.py`) | Só o Adriano |
| `verificador/` | Juiz comum | Só o Adriano |
| `massa/` | Massa sintética para o cenário de escala | Só o Adriano |
| `anonimizacao/` | Anonimização local de amostras reais (roda só no Mac) | Só o Adriano |
| `.kiro/` | Orientações e specs para o Kiro implementar cada spike | Só o Adriano |
| `spikes/dotnet/` | Spike .NET 10 | Kiro (spec `spike-dotnet`) |
| `spikes/python/` | Spike Python | Kiro (spec `spike-python`) |

## Como usar com o Kiro

1. Abrir o repositório no Kiro.
2. Abrir a spec `spike-dotnet` (ou `spike-python`) e executar as tarefas em ordem.
3. Fazer as duas em sessões separadas, sem uma ler a pasta da outra.
4. Ao fim de cada tarefa com verificador, guardar o `--relatorio`.

## Dados

- Os cenários usam **pseudônimos** (`T001`, `T002`) e valores inventados.
- Amostra real só entra depois de passar por `anonimizacao/anonimizar.py`, no Mac. A pasta `dados-anon/` e a chave de anonimização **nunca** vão para o Git (`.gitignore`).

## Estado

- Base comum validada contra Postgres 16 e contra uma implementação de referência mínima (fora deste repositório): C1 e C7 passam, e o verificador reprova estados errados, transições proibidas e eventos com dado indevido.
- Regras de negócio **provisórias**: R-01 a R-07 ainda não validadas com Fiscal e DP.
