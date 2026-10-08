# Registro do compilador — spike .NET

Cada vez que o compilador C# (com `TreatWarningsAsErrors=true`) barrou um erro
**antes de qualquer teste**, uma linha com data, arquivo e tipo do erro. É a
"rede de proteção" medida no critério 5 de `docs/regras-do-jogo.md`.

| Data | Arquivo | Erro | Natureza |
|---|---|---|---|
| 2026-10-06 | `src/Motor.Dominio/Regras/RegraEntregaveis.cs` | CS0246: `EstadoEntregavel` não encontrado | `using Motor.Dominio.Fechamento` faltando |
| 2026-10-06 | `src/Motor.Aplicacao/MotorServico.cs` | CS0103: `HashCanonico` não existe no contexto | `using Motor.Dominio.Fatos` faltando |
| 2026-10-06 | `src/Motor.Aplicacao/MotorServico.cs` | CS8130: não infere tipo da desconstrução `(id, versao)` | cascata do CS0103 acima (tipo de retorno desconhecido) |
| 2026-10-06 | `tests/Motor.Testes.Unidade/DominioVetoresTeste.cs` | CS0103: `Contexto`/`Condicao` não existem no contexto (8×) | `using Motor.Dominio.Regras` faltando no teste |
| 2026-10-06 | `src/Motor.Api/Program.cs` | CS0103: `Validacao` não existe no contexto (7×) | `using Motor.Api` faltando no `Program.cs` top-level |
| 2026-10-08 | `src/Motor.Aplicacao/MotorServico.cs` | CS1061: `JsonElement` não tem `.Value` (2×) | review Codex P1: `ConteudoPorIdAsync` devolve `JsonElement?`; após `?? throw` o valor já é `JsonElement`, `.Value` sobra |

Observações:

- Todos foram erros de **referência de símbolo/namespace** (using faltando),
  pegos no `dotnet build` antes de rodar os testes. Nenhum passou para a fase de
  teste.
- O compilador C# também barraria qualquer `null` não tratado (`Nullable`
  habilitado) e qualquer aviso (promovido a erro por `TreatWarningsAsErrors`); o
  build final fecha com **0 avisos, 0 erros**.
- Erros de LÓGICA (não de compilação) encontrados ao rodar o verificador — e que
  o compilador não poderia pegar — estão fora deste registro por definição; foram
  dois, ambos de conversão `DateTimeOffset`/`timestamptz` no Npgsql, descritos em
  `DUVIDAS.md` §2.
