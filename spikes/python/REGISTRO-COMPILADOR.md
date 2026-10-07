# Registro do compilador (mypy --strict)

Cada vez que o `mypy --strict` barrar um erro **antes de qualquer teste**, uma linha:
`data · arquivo · tipo do erro`.

| Data | Arquivo | Tipo do erro |
|---|---|---|
| 2026-10-07 | `infraestrutura/unidade_de_trabalho.py` | `unused-ignore` (3×): `# type: ignore` desnecessário em `cur.execute` |
| 2026-10-07 | `infraestrutura/unidade_de_trabalho.py` | `arg-type`: `banco.conexao()` devolvia `Connection[tuple[Any,...]]`, não `Connection[dict[str, Any]]` (faltava parametrizar o tipo do pool) |
