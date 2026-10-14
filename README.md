# Spike do motor do fechamento — Rissi

Protótipo **descartável** para decidir duas coisas com medida:
1. se o motor do fechamento funciona como **tabela de prontidão em Postgres**;
2. qual linguagem é melhor para o núcleo do sistema novo: **.NET 10**, **Python** ou **PHP**.

As três spikes implementam o mesmo comportamento e passam pelo mesmo juiz. Quem perde sai; quem ganha é reescrita com rigor de produção depois do ADR-013. A **base comum** (esquema, cenários, verificador, contrato) não é descartável: vira o ponto de partida do motor de produção.

Contexto e decisões: spec do spike v0.5 e ADR-014 no projeto Rissi. A spike PHP entrou em 12/10, já na semântica v3 (ver `docs/regras-do-jogo.md` §2).

## Subir no Mac

Requisito: Docker (Docker Desktop, OrbStack ou Colima) e Python 3.12+ para o verificador.

```sh
docker compose up -d                             # Postgres 16, emulador de GCS (fake-gcs-server) e migrações nos três bancos
docker compose logs migrar                       # deve terminar com "migrações ok"

docker compose --profile dotnet up -d --build    # spike .NET   → http://localhost:8081
docker compose --profile python up -d --build    # spike Python → http://localhost:8082
docker compose --profile php up -d --build       # spike PHP    → http://localhost:8083
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

python verificador/verificador.py --api http://localhost:8083 \
  --db postgresql://spike:spike@localhost:5432/spike_php \
  --todos cenarios --fase 2 --limpar --relatorio resultado-php.json
```

O verificador chama a API de cada cenário, confere o banco, checa 15 invariantes (transições, versões de fatos, eventos, conferências, exceções, tarefas, override, protocolo com a guia, caso encerrado imutável) e mede as 6 consultas de indicador. `--fase N` roda as fases 1 a N.

| Fase | Cenários | O que cobre |
|---|---|---|
| 1 | C1, C7 | Caminho feliz do Simples com folha; LP no 3º mês até a apuração |
| 2 | C2, C3, C4, C5, C8, C9, C10 | Invalidação e cascata, exceções, competência encerrada, override, encerramento do LP, acompanhamento depois do protocolo |
| 3 | C6 | Escala: 4.500 casos e 300 guias em lote |

Mapa completo de cobertura: `docs/cobertura-cenarios.md`. Os cenários são gerados por `cenarios/fonte/gerar_cenarios.py`.

## O que tem aqui

| Pasta | O quê | Quem altera |
|---|---|---|
| `db/` | Esquema (V001–V006), regras **provisórias**, script de migração | Só o Adriano |
| `contrato/openapi.yaml` | API idêntica nas três spikes | Só o Adriano |
| `docs/semantica-do-motor.md` | **O que** o motor faz. Fonte da verdade | Só o Adriano |
| `docs/regras-do-jogo.md` | Como as spikes são comparadas | Só o Adriano |
| `cenarios/` | C1 a C10, em três fases (gerados por `cenarios/fonte/gerar_cenarios.py`) | Só o Adriano |
| `verificador/` | Juiz comum | Só o Adriano |
| `massa/` | Massa sintética para o cenário de escala | Só o Adriano |
| `anonimizacao/` | Anonimização local de amostras reais (roda só no Mac) | Só o Adriano |
| `.kiro/` | Orientações e specs para o Kiro implementar cada spike | Só o Adriano |
| `spikes/dotnet/` | Spike .NET 10 | Kiro (spec `spike-dotnet`) |
| `spikes/python/` | Spike Python | Kiro (spec `spike-python`) |
| `spikes/php/` | Spike PHP | Kiro (spec `spike-php`) |

## Como usar com o Kiro

1. Abrir o repositório no Kiro.
2. Abrir a spec `spike-dotnet`, `spike-python` ou `spike-php` e executar as tarefas em ordem.
3. Fazer cada uma em sessão separada, sem uma ler a pasta de outra.
4. Ao fim de cada tarefa com verificador, guardar o `--relatorio`.

## Vetores do hash

`contrato/vetores-hash.json` traz vetores compartilhados do hash do fato (semântica §6): ordem das chaves por code point, escapes mínimos, `payload` só com inteiros int64, sem chave duplicada, até 64 níveis. **As três spikes (`spikes/dotnet`, `spikes/python`, `spikes/php`) devem passar nos vetores.**

O harness de cada spike é um **teste unitário/de integração da própria spike** que lê `contrato/vetores-hash.json` (não é o verificador HTTP). Para cada vetor `aceito`, o JSON canônico e o SHA-256 calculados a partir de `payload` (texto JSON bruto) e `valor_centavos` precisam ser idênticos a `canonico` e `sha256`. Para cada `rejeitado`, a API (ou a camada de validação do corpo) responde 422 `corpo_invalido`; o corpo é montado com um envelope válido (`titular_id`, `competencia`, `tipo`, `fonte`, `observado_em`) mais o `payload` bruto e, quando existir, `valor_centavos_bruto` como texto JSON do campo `valor_centavos`. Em vetores com `campos_texto` (objeto `{campo: string}`, ex. `{"tipo": "a\u0000b"}`), cada valor já vem decodificado do arquivo e substitui o campo de mesmo nome do envelope (`tipo`, `tributo`, `fonte`, `titular_id`...); o resultado esperado é 422 `corpo_invalido` (U+0000 não é aceito em campo de texto). Os vetores de NUL valem só para o corpo da requisição, não para parâmetros de caminho (nestes, 422 da aplicação ou 400 do servidor web são conformes; o .NET só exercita o corpo). Em vetores com `campos_omitidos`, esses campos não devem constar do corpo.

### Vetores do `entrada_hash`

`contrato/vetores-entrada-hash.json` traz vetores do `entrada_hash` da conferência (semântica §9.2, incluindo `semantica_calculo`). Formato: objeto `{"descricao": texto, "vetores": [...]}`; cada vetor tem `nome`, `cf` (texto), `fatos_usados_entrada` (lista `[{"fato_id": uuid, "versao": int}]`, possivelmente fora de ordem), `regra_versao_id` (uuid), `semantica_calculo` (inteiro >= 1), `canonico` e `sha256`. Para cada vetor, o spike ordena `fatos_usados_entrada` por `fato_id` (texto, code point), monta o JSON canônico `{"cf","fatos_usados","regra_versao_id"}` (chaves por code point, sem espaços, UTF-8 cru), acrescenta `"semantica_calculo":N` como última chave **somente se N > 1**, e o JSON e o SHA-256 (hex minúsculo do UTF-8) precisam ser idênticos a `canonico` e `sha256`. Os valores foram calculados em Python e conferidos contra `EntradaHash.Calcular` do .NET (9/9). Este arquivo é separado de `contrato/vetores-hash.json`.

## Protocolo recomendado para o comparativo

Tempos de parede do C6 variaram de 17,6 s a 328 s com o **mesmo** código em sessões e ambientes diferentes; não servem para comparar spikes. Para a próxima rodada (nada é executado agora):

1. **Máquina quieta**, sem outros contêineres ou spikes no ar, e a mesma máquina para as três linguagens.
2. **Mesmo modo de montagem do Docker**: imagem com o código embutido, **sem bind mount**.
3. **N >= 3 execuções** de cada medição; reportar **mediana e dispersão** (mínimo e máximo), nunca uma execução só.
4. **SQLs por operação** como métrica principal: `log_statement=all` no Postgres, contando todas as instruções, **incluindo `BEGIN`/`COMMIT`**.
5. **Conjunto fixo de operações, idêntico nas três linguagens**, sobre o mesmo cenário (1 empresa SN com folha):
   - abrir 1 caso SN com folha;
   - fato que não afeta nada;
   - fato que leva o E01 a validado;
   - fato repetido (mesmo conteúdo);
   - nova versão de um fato existente.
6. Registrar junto: commit, versões das imagens, CPUs/memória do Docker e carga média da máquina.

## Dados

- Os cenários usam **pseudônimos** (`T001`, `T002`) e valores inventados.
- Amostra real só entra depois de passar por `anonimizacao/anonimizar.py`, no Mac. A pasta `dados-anon/` e a chave de anonimização **nunca** vão para o Git (`.gitignore`).

## Estado

- **07/10/2026, semântica v3:** o fechamento termina no **protocolo de entrega** (pacote disponibilizado no App ou disparado por e-mail). O pagamento da guia saiu do fechamento e virou sinal para a Regularidade Fiscal. A V006 aposenta o estado `pago` e troca a regra `fechamento.entregaveis` para a v2. Falha de envio, leitura e falta de leitura depois do protocolo viram exceção com dono, sem reabrir o caso (C10).

- Base comum validada contra Postgres 16 e contra uma implementação de referência mínima (fora deste repositório): C1 e C7 passam, e o verificador reprova estados errados, transições proibidas e eventos com dado indevido.
- Regras de negócio **provisórias**: R-01 a R-07 ainda não validadas com Fiscal e DP.
