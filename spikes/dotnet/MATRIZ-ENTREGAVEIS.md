# Matriz de Entregáveis × Insumos × Regimes

> **Fonte**: regra `fechamento.entregaveis` em `regras.regra_versao` (banco `spike_dotnet`),
> cruzada com `docs/semantica-do-motor.md` (seções de conferências e pós-validação).
> Extraída da configuração **em execução** do spike, não de uma tabela fiscal homologada.
>
> ⚠️ **As regras fiscais são provisórias** (`status = 'provisoria'` no banco) — "não
> validado pelas áreas", conforme a própria semântica. Esta matriz descreve o
> comportamento atual do motor, não uma referência fiscal oficial.

## Entregáveis (E01–E12)

| E## | Nome | Executor | Materializa quando | Insumo(s) de entrada | Depende de | Conferências |
|-----|------|----------|--------------------|----------------------|------------|--------------|
| **E01** | Insumos: faturamento e notas | sistema | sempre | `faturamento_mes`, `notas_oneflow` | — | CF-01 |
| **E02** | Alíquota do mês | sistema | regime **SN** | `aliquota_mes` | — | — |
| **E03** | Apuração fiscal | **humano** | sempre | `apurado[IRPJ]` **só p/ LP no 3º mês do trimestre** (meses `-1` e `-2`, competência relativa) | E01, E02 | CF-04 |
| **E04** | Divisão por sócio | sistema | sempre | `divisao_socios` | E03 | CF-07 |
| **E05** | Taxas municipais | sistema | `tem_taxa_municipal = true` | `taxa_municipal` | — | — |
| **E06** | Folha CLT | **humano** | `tem_folha = true` | (conclusão humana) | — | — |
| **E07** | Pró-labore | **humano** | `tem_prolabore = true` | (conclusão humana) | E03 | CF-08 |
| **E08** | Guias do DP | sistema | `tem_folha` **ou** `tem_prolabore` | `guia[INSS]` (sempre); `guia[FGTS]` **só se `tem_folha`** | E06, E07 | CF-09 |
| **E10** | Obrigações acessórias | sistema | sempre | `recibo_obrigacao` tributo **PGDAS-D (SN)** / **DCTFWEB (LP)** | E03 | CF-12 |
| **E11** | Entrega e acompanhamento | sistema | sempre | `guia` tributo **DAS (SN)** / **IRPJ (LP)** + pós-validação `documento_disponibilizado` → `guia_paga` | E03, E04, E08 | CF-05, CF-06 |
| **E12** | Encerramento da competência | sistema | sempre (`encerra_caso`) | — | E01, E02, E03, E04, E05, E06, E07, E08, E10, **E11 ≥ pago** | — |

> **Nota**: não existe **E09** na regra — a numeração salta de E08 para E10.

## Variação por **regime**

| Dimensão | **SN** (Simples Nacional) | **LP** (Lucro Presumido) |
|----------|---------------------------|--------------------------|
| E02 (alíquota) | **existe** | não materializa |
| E03 entrada | alíquota do próprio mês (via E02) | `apurado[IRPJ]` dos **2 meses anteriores** do trimestre (só no 3º mês) |
| E04 / CF-07 base | soma de parcelas = `apurado[DAS]` | soma de parcelas = `apurado[IRPJ]` |
| E10 recibo (CF-12) | tributo **PGDAS-D** | tributo **DCTFWEB** |
| E11 guia (CF-05/06) | tributo **DAS** | tributo **IRPJ** |

## Variação por **flag da empresa** (insumo cadastral)

| Flag | Efeito |
|------|--------|
| `tem_folha = true` | materializa **E06**; habilita `guia[FGTS]` em **E08** |
| `tem_prolabore = true` | materializa **E07** |
| `tem_folha` **ou** `tem_prolabore` | materializa **E08** |
| `tem_taxa_municipal = true` | materializa **E05** |

## Pós-validação do E11 (semântica §10.1)

Liberação automática. A partir de `liberado`:

| Gatilho | Transição |
|---------|-----------|
| fato `documento_disponibilizado` (mesmo tributo da guia de entrada) | `liberado → disponibilizado` |
| fato `guia_paga` (mesmo tributo) | `disponibilizado → pago` |

## Encerramento (E12, semântica §10.2)

O E12 depende de **todos os demais entregáveis que existem no caso** (E11 em
`pago`). Como só são materializados os entregáveis aplicáveis às flags/regime,
um caso **LP sem folha e sem pró-labore** encerra com **6 entregáveis**
(E01, E03, E04, E10, E11, E12) — sem E02, E05, E06, E07, E08. Um caso **SN com
folha e pró-labore** encerra com **10** (todos menos E05, quando sem taxa
municipal). Quando o E12 chega a `validado`, há transição imediata
`validado → encerrado` e o caso passa a `estado = 'encerrado'` (evento
`fechamento.concluido`).

## Executores

- **Humanos** (exigem `POST .../entregaveis/{tipo}/concluir`): **E03, E06, E07**.
- **Sistema** (avançam sozinhos quando insumo/dependência chegam): todos os demais.

## Evidência de execução (sessão de 2026-10-07)

Reproduzido manualmente contra o spike em `http://localhost:8081`, dois casos na
competência `202609`:

- **C1 · T001** (SN, com folha e pró-labore) → encerrado com **10** entregáveis.
- **C7 · T002** (LP, sem folha/pró-labore) → encerrado com **6** entregáveis.
  O caminho até o encerramento do LP (E10 `DCTFWEB`, E11 `IRPJ`) vai **além** do
  que o cenário C7 oficial exercita — o C7 do verificador para na conclusão do
  E03. Isto foi exploração manual guiada pela config e **não** altera a barra de
  aceite, que segue `C1 + C7 = 2/2` via `verificador/verificador.py`.
