# Registro do compilador — spike PHP

Cada vez que o **PHPStan** (nível `max`, com Larastan) barrar um erro **antes de
qualquer teste**, uma linha aqui: data, arquivo e tipo do erro. É a medida da rede
de proteção da linguagem (critério 5 de `docs/regras-do-jogo.md`).

| Data | Arquivo | Tipo do erro |
|---|---|---|
| 2026-10-12 | tests/Arquitetura/CamadasTest.php | `method.notFound`/`method.nonObject`: a DSL `arch()->expect()->toUse()` do Pest é resolvida por `__call` em tempo de execução e não tem tipo estático no nível `max` (`arch()` devolve `TestCall`, que não declara `expect()`). Resolvido excluindo **só** esse arquivo de DSL da análise (`excludePaths`); `app/` inteiro segue sob `max`. Ver DUVIDAS.md D5. |
| 2026-10-12 | app/Api/Controllers/FilaController.php | `nullCoalesce.offset`: com o `$row` tipado como `array{pendentes:int,com_erro:int}`, o `?? 0` era código morto. Corrigido tipando o retorno de `selectOne` como `object` e lendo as propriedades com cast explícito. |
| 2026-10-12 | app/Api/ErrosHttp.php | `return.unusedType`: o closure do `NotFoundHttpException` declarava `?JsonResponse` mas sempre devolvia `JsonResponse`. Corrigido removendo o `?`. |
| 2026-10-12 | app/Aplicacao/Portas/*.php, app/Infraestrutura/Persistencia/*.php, tests/Integracao/InfraBancoTest.php | Tarefa 3: 45 erros barrados numa leva, antes de qualquer teste. Categorias: `missingType.iterableValue` (métodos devolvendo `array` sem tipo de valor → docblocks `@return list<...>`), `argument.type`/`missingType.generics` (genérico `T` não usado na porta UnidadeDeTrabalho + `callable`→`\Closure`), `argument.type` em `mapear(object)` recebendo `mixed` de `selectOne`/`select` (→ `mapear(mixed)` + helper `mapearLista`), `array_map` sobre resultado não-tipado (→ `foreach`), `nullsafe.neverNull` no teste (→ variável local), `function.alreadyNarrowedType`/`array_values` no-op. Tudo corrigido; nenhum `@phpstan-ignore` nem baseline. |
