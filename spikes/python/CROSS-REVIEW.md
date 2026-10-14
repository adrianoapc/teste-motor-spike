# Cross-review da spike Python — para o Claude auditar antes do merge

Fluxo de revisão mútua (combinado com o Adriano): **o Kiro escreveu esta spike; o
Claude revisa antes do merge.** Este arquivo é o pacote de revisão. Não é código da
spike; é um guia para quem revisa.

## O que é

Spike Python do motor do fechamento (Rissi), uma das três (`.NET`, Python, PHP) julgadas
pelo **mesmo** verificador (`verificador/verificador.py`) com os **mesmos** cenários
(`cenarios/*.json`). Fonte da verdade do comportamento: `docs/semantica-do-motor.md`
(v3). Contrato HTTP: `contrato/openapi.yaml`. Pasta da spike: `spikes/python/`.

Branch: `spike/python`. PR: #5 (base `main`). Commits da spike, da base da fase 1 em
diante: `9680c2a` (v3 Task 11), `bd22263` (revisão fase 1), `22650d2` (fase 2),
`73f749d` (fecha fase 2), `c341743` (fecha fase 3). O merge de `main` (`f7504ef`) traz
a semântica v3, a V006, os cenários C1–C10 e o verificador com `--fase`.

## Estado declarado (reproduza antes de confiar)

Importante: o ambiente de dev pode **não** ter `docker compose` v2 nem Python 3.13 local.
A spike foi construída e testada **dentro de `python:3.13-slim`** (a imagem do avaliador).
Há um `spikes/python/scripts/checar.sh` que roda a suíte lá.

```sh
docker compose up -d                                  # Postgres + migrações (base comum, inclui V006)
docker compose --profile python up -d --build         # spike Python em :8082

# testes/tipos/lint dentro de python:3.13-slim (mypy strict, ruff, import-linter, pytest):
docker run --rm -v "$PWD/spikes/python:/app" -w /app python:3.13-slim \
  sh -c 'pip install -q -e ".[dev]" && python -m mypy src tests && \
         python -m ruff check src tests && python -m pytest -q'
# esperado: mypy --strict src sem erros e sem # type: ignore em src/ (os testes usam alguns
# # type: ignore para injetar fakes), ruff limpo, todos os testes verdes (a contagem muda a
# cada rodada; os de integração/locks só rodam com MOTOR_DB definido)

# verificador (httpx + psycopg):
python verificador/verificador.py --api http://localhost:8082 \
  --db postgresql://spike:spike@localhost:5432/spike_python --todos cenarios --fase 3 --limpar
# esperado: RESULTADO: todos passaram (10/10)
```

Medidas em `spikes/python/METRICAS.md` (seções Fase 1/2/3 + Manutenção); decisões em
`DUVIDAS.md` (D1–D8); erros barrados pelo mypy em `REGISTRO-COMPILADOR.md`.

## O que auditar (prioridade)

1. **Correção vs. semântica, não só "o verificador passou".** O verificador cobre C1–C10,
   mas leia a semântica e confira se a implementação segue a regra, não só o número.
   Mapa arquivo → seção:
   - §4.1 **regra de ouro** das transições (estado + `estado_desde` + `version++` + linha
     de transição com a versão da regra do caso, tudo na mesma transação) —
     `src/motor/infraestrutura/unidade_de_trabalho.py::transicionar`.
   - §6 **hash canônico** e preservação de `{}` vs `[]`, e versionamento/idempotência do
     fato — `src/motor/dominio/hashing.py`, `motor_fechamento.py::publicar_fato`. Confira
     que o corpo chega como objeto fiel (pydantic `dict[str, object]`), sem coerção que
     achate `{}`/`[]`.
   - §7.4 **reavaliação em voltas** (um passo por entregável por volta, estado das
     dependências lido no momento, até ponto fixo) — `motor_fechamento.py::avaliar_caso`
     e `_passo`.
   - §9.1 **fórmulas** das 8 CFs, arredondamento **meio-para-o-par só com inteiros**,
     CF-09 par de **maior diferença** (empate → INSS), §9.2 `entrada_hash`/idempotência —
     `src/motor/dominio/conferencias.py`, `src/motor/aplicacao/conferencia.py`,
     `src/motor/dominio/dinheiro.py`.
   - §9.2 **severidade computada**, não a do catálogo: divergência **A** não bloqueia nem
     abre exceção. `conferir()` devolve `(resultado, severidade)` — veja
     `conferencia.py` e o uso em `motor_fechamento.py::_conferir_entregavel`. (Foi o bug
     que derrubou o C3 na 1ª rodada da fase 2 — vale confirmar que a correção está certa.)
   - §10.1/§10.2 protocolo e encerramento (v3, sem `pago`); **INV-14**:
     `documento.disponibilizado` leva o `fato_id` da **guia** de entrada do E11, não do
     fato de protocolo — `motor_fechamento.py::_guia_vigente_e11` e o bloco `LIBERADO`.
   - §10.3 **acompanhamento** (estado do E11 lido **antes** da reavaliação; reenvio,
     entrega_falhou, entrega_confirmada, recebimento_pendente só sem confirmação vigente) —
     `_acompanhamento_pos_protocolo`, `_e11_em_disponibilizado`.
   - §11 **invalidação + cascata** (sementes pela **versão anterior** do fato, tabela por
     estado §11.2, cascata só a partir de quem foi para `invalidado`, cada entregável no
     máximo uma vez, caso encerrado → `competencia_encerrada` §11.3) — `_invalidar`,
     `_invalidar_entregavel`. §8 item 4: `ConcluirTarefa` **não** invalida o próprio
     entregável (`excluir_entregavel_id`).
   - §8.1 **override** (validação na ordem 422 → 404 → 409, evento `conferencia.override`,
     liberação quando não resta divergência B sem override) —
     `motor_fechamento.py::override_conferencia` e a rota em `api/app.py`.

2. **Fronteiras de camada.** `dominio` não depende de nada além da stdlib; `aplicacao`
   não depende de `api` nem de driver concreto (psycopg). Há teste de arquitetura
   (`tests/unidade/test_arquitetura.py`, que roda `lint-imports`; nota: antes do PR `fix/python-importlinter` esse teste não executava nada, ver DUVIDAS D14) — confira se nenhum vazamento
   passou (ex.: `import psycopg` em `aplicacao`).

3. **Dinheiro inteiro em centavos** em todo caminho; nenhum `float`. `valor_centavos` é
   `int | None`; `arred_meio_par` opera só com inteiros.

4. **SQL explícito, sem ORM, sem migração.** Confirme que nada cria/altera esquema e que
   as tabelas só-inserção (`fatos.fato`, `fechamento.entregavel_transicao`) nunca recebem
   UPDATE/DELETE pela spike.

5. **Não tocar a base comum.** `db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`,
   `docker-compose.yml`, `.kiro/steering/` devem estar intactos. O diff deve conter só
   `spikes/python/` e os checkboxes de `.kiro/specs/spike-python/tasks.md`.

6. **mypy `--strict src` sem `# type: ignore` em `src/`** (os testes têm `# type: ignore`
   pontuais para injetar fakes; mypy não roda neles) e `ruff` limpo. O contrato de camadas é
   verificado por `tests/unidade/test_arquitetura.py` (`lint-imports`; até o PR `fix/python-importlinter` o teste chamava `python -m importlinter.cli lint`, que não analisa nada, ver DUVIDAS D14). Os erros que o mypy barrou antes de teste estão em `REGISTRO-COMPILADOR.md`.

## Decisões que valem contestar (estão em DUVIDAS.md)

- **D2**: reavaliar **todos** os casos abertos do titular após um fato (superconjunto
  seguro de §6.2). Mais simples; a fase 3 mediu 49,2s/4500 casos — vale discutir custo.
- **D5**: `fatos_usados` de CF-06/CF-12 usa os fatos efetivamente lidos pela regra.
- **D7**: `_regra_do_caso()` relia a regra em uso em vez de usar o
  `caso.regra_entregaveis_versao_id` gravado. **Já corrigido** (a regra é lida pela versão
  fixada no caso; teste em `tests/unidade/test_revisao_codex.py`).
- **D8**: pool não recupera de um restart do Postgres sozinho (conexões mortas →
  `/health` 500). Fora do escopo da escala, mas `ConnectionPool(check=...)` resolveria.

## Como responder

Aponte problemas concretos com `arquivo:linha` e a seção da semântica violada. Se achar
erro na **base comum** (não na spike), diga — mas isso é decisão do Adriano, não se
corrige na spike. **Nunca** altere cenário ou verificador.
