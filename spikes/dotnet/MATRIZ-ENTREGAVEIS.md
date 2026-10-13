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
| **E11** | Protocolo de entrega ao cliente | sistema | sempre | `guia` tributo **DAS (SN)** / **IRPJ (LP)** + pós-validação `documento_disponibilizado` → `disponibilizado` (protocolo) | E03, E04, E08 | CF-05, CF-06 |
| **E12** | Encerramento da competência | sistema | sempre (`encerra_caso`) | — | E01, E02, E03, E04, E05, E06, E07, E08, E10, **E11 ≥ disponibilizado** | — |

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

## Pós-validação e acompanhamento do E11 (semântica v3 §10.1, §10.3)

Liberação automática. A partir de `liberado`:

| Gatilho | Transição / efeito |
|---------|--------------------|
| fato `documento_disponibilizado` (mesmo tributo da guia de entrada) | `liberado → disponibilizado` (**protocolo**); evento `documento.disponibilizado` com `entregavel_id` **e** `fato_id` da guia (INV-14) |

**O fechamento termina no protocolo.** Não há mais estado `pago` (aposentado na
V006) nem evento `guia.paga` — o pagamento é da Regularidade Fiscal (§15).

Depois do protocolo (E11 já em `disponibilizado`), o **acompanhamento** (§10.3)
não muda estado nem reabre o caso:

| Fato (mesmo tributo da guia) | Efeito |
|------------------------------|--------|
| `documento_disponibilizado` (**nova versão**) | **reenvio**: novo evento `documento.disponibilizado`; resolve `entrega_falhou` como `reenviado` |
| `entrega_falhou` | exceção `entrega_falhou` classe `O` (abertura única); evento `excecao.aberta` |
| `entrega_confirmada` | evento `recebimento.confirmado`; resolve `entrega_falhou`/`recebimento_pendente` como `recebido` |
| `recebimento_pendente` | exceção `recebimento_pendente` classe `O` (só se não há `entrega_confirmada` vigente) |

## Encerramento (E12, semântica §10.2)

O E12 depende de **todos os demais entregáveis que existem no caso** (E11 em
`disponibilizado`). Como só são materializados os entregáveis aplicáveis às
flags/regime, um caso **LP sem folha e sem pró-labore** encerra com **6
entregáveis** (E01, E03, E04, E10, E11, E12) — sem E02, E05, E06, E07, E08. Um
caso **SN com folha e pró-labore** encerra com **10** (todos menos E05, quando
sem taxa municipal). Quando o E12 chega a `validado`, há transição imediata
`validado → encerrado` e o caso passa a `estado = 'encerrado'` (evento
`fechamento.concluido`). Depois do encerramento, nenhuma transição ocorre em
entregável do caso (INV-15).

## Executores

- **Humanos** (exigem `POST .../entregaveis/{tipo}/concluir`): **E03, E06, E07**.
- **Sistema** (avançam sozinhos quando insumo/dependência chegam): todos os demais.

## Evidência de execução (semântica v3)

Verificador oficial contra o spike em `http://localhost:8081`, todas as fases:

```
python verificador/verificador.py --api http://localhost:8081 \
  --db postgresql://spike:spike@localhost:5432/spike_dotnet --todos cenarios --limpar
→ RESULTADO: todos passaram (10/10)
```

Cobre C1–C10 (fases 1–3): feliz SN/LP, reabertura após entrega, nota cancelada
com DAS protocolado, guia retificada duas vezes, alteração retroativa em
competência encerrada, escala (4.500 casos), override de conferência e
acompanhamento depois do protocolo. Testes do projeto: Unidade 27/27,
Arquitetura 3/3, build 0 avisos / 0 erros.
