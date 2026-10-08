# Métricas — spike .NET

## Correção
- Verificador (2026-10-06, branch `spike/dotnet`): **C1 passou** (164 checagens, 0 falhas); **C7 passou** (85 checagens, 0 falhas). `RESULTADO: todos passaram (2/2)`.
- Invariantes: todas ok (INV-1 a INV-9, verificadas em cada cenário).
- Indicadores (segundos, pior leitura entre os cenários): I1 ≈ 0,0016 · I2 ≈ 0,0011 · I3 ≈ 0,0048 · I4 ≈ 0,0012 · I5 ≈ 0,0026 — todos muito abaixo do limite de 2 s.

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 9 | 516 |
| aplicacao | 6 | 924 |
| infraestrutura | 2 | 523 |
| api | 2 | 256 |
| **Total** | **19** | **2219** |
| Testes (à parte) | 2 | 185 |

> Contagem por `find src/Motor.<camada> -name '*.cs'` excluindo `bin/`/`obj/`.
> Arquivos de projeto (`.csproj`, `.sln`, `Directory.Build.props`, `Dockerfile`)
> não entram na contagem de linhas de código.

## Esforço do agente
- Tarefas concluídas: 10/10 (fase 1 completa).
- Vezes que uma tarefa foi refeita: 0 tarefas inteiras refeitas. Ajustes pontuais: 5 erros de compilação (using faltando, ver `REGISTRO-COMPILADOR.md`) + 2 erros de lógica de runtime (conversão `DateTimeOffset`/`timestamptz`, ver `DUVIDAS.md` §2), todos corrigidos no mesmo fluxo.
- Intervenções humanas: 1 — autorização inicial para construir a spec diretamente nesta sessão. Nenhuma decisão de domínio precisou de intervenção.
- Tempo de relógio do início ao verificador passando: ~1 sessão de trabalho contínua (uma noite); build + teste + verificador rodando em contêiner.

## Rede de proteção
- Erros barrados pelo compilador/`TreatWarningsAsErrors` antes de teste: 5 (ver `REGISTRO-COMPILADOR.md`). Build final: 0 avisos, 0 erros.
- Testes automatizados: 25 unitários (vetores obrigatórios de arredondamento, hash canônico, `entrada_hash`, competência/virada de ano, mês do trimestre, ordem de estados, condição `quando`) + 3 de arquitetura (NetArchTest). Todos verdes.

## Imagem
- Tamanho da imagem: **371 MB** (`mcr.microsoft.com/dotnet/aspnet:10.0` + app publicado).
- Tempo de `up` até `/health` 200 (restart com Postgres já no ar): **~0,46 s**.

## Observações
- Processamento síncrono nesta fase; `/admin/fila` sempre devolve `{"pendentes":0,"com_erro":0}`.
- Dinheiro sempre em centavos (`long`); arredondamento meio-para-o-par só com inteiros, nunca ponto flutuante.
- Hash canônico montado à mão sobre `System.Text.Json` para garantir chaves ordinais, sem espaços e UTF-8 sem escapar acentos (os quatro vetores do requisito 4.2 batem).
- Fora da fase 1 (não implementado): invalidação/cascata (§11), override, cenários 2–6, fila assíncrona, autenticação, RLS, conectores reais.
