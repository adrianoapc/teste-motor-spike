# Registro do compilador (mypy --strict)

Cada vez que o `mypy --strict` barrar um erro **antes de qualquer teste**, uma linha:
`data · arquivo · tipo do erro`.

| Data | Arquivo | Tipo do erro |
|---|---|---|
| 2026-10-07 | `infraestrutura/unidade_de_trabalho.py` | `unused-ignore` (3×): `# type: ignore` desnecessário em `cur.execute` |
| 2026-10-07 | `infraestrutura/unidade_de_trabalho.py` | `arg-type`: `banco.conexao()` devolvia `Connection[tuple[Any,...]]`, não `Connection[dict[str, Any]]` (faltava parametrizar o tipo do pool) |
| 2026-10-12 | `aplicacao/motor_fechamento.py` | `arg-type` (3×): `pl = {"entregavel_id": e11.id}` inferido como `dict[str, str]`, incompatível com `dict[str, object]` de `gravar_evento` (§10.3). Anotado explicitamente. |

## Fase 4 — Manutenção M1 (2026-10-15)

Nenhum erro barrado pelo `mypy --strict` antes de teste nesta fase. `mypy --strict src` (sem
`# type: ignore`) passou em toda rodada, antes de cada execução de testes, nas tarefas 19–24.

## Fase 4.5 — Manutenção M2 (2026-10-28)

| Data | Arquivo | Tipo do erro |
|---|---|---|
| 2026-10-28 | `dominio/contrato.py` | `import-untyped` (2×): `jsonschema` e `jsonschema.exceptions` sem stubs. Resolvido com `types-jsonschema` nas deps de dev (mypy-only). |
| 2026-10-28 | `dominio/hashing.py` | `arg-type`: `ordenar(normalizar(...))` — `normalizar` devolve `object`; o resultado da composição em `entrada_hash_v2` precisou ser estreitado para `Mapping[str, object]` antes de `ordenar`. |
| 2026-10-28 | `dominio/contrato.py` | `comparison-overlap` (2×): `jsonschema.ValidationError.validator` é tipado `Validator \| Unset`; comparar direto com os literais `'required'`/`'type'` é não sobreposto. Resolvido comparando `str(erro.validator)`. |

Depois dessas correções, `mypy --strict` passou em toda rodada seguinte (antes de cada execução
de testes, tarefas 26–30), sem `# type: ignore` novo. Fechamento da fase 4.5: `Success: no issues
found in 25 source files`.
