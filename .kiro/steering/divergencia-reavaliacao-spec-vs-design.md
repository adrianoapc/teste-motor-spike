# Reavaliação após fato: §6 passo 2 (RESOLVIDA em 15/10/2026)

**Status: RESOLVIDA pelo dono da spec. Decisão: conjunto MÍNIMO, com idempotência obrigatória.** Texto normativo em `docs/semantica-do-motor.md` §6 passo 2 (v4).

## A decisão

- A lista da §6 passo 2 é o que **tem de ser** reavaliado. Reavaliar mais é permitido.
- **Idempotência obrigatória:** reavaliar um entregável cujo estado, dependências, entradas resolvidas e gatilho não mudaram não grava nada (transição, evento, conferência, exceção, tarefa, `version`). O verificador cobra isso nos cenários da fase 4 com fatos sem consumidor (`espera.sem_efeito_nos_casos`).
- **Recorte é otimização permitida, não requisito.** Quem recorta tem de (a) registrar o entregável para reavaliação **dentro da função única de transição** (§4.1), e (b) manter o teste diferencial recorte × percurso completo com 0 divergência.

## Por que (resumo da conversa de 15/10)

- O percurso completo se corrige sozinho no próximo fato do titular. O recorte, se um caminho novo mudar estado sem registrar, deixa o caso parado em silêncio (exemplo discutido: uma "dispensa de taxa municipal" que valida o E05 sem avisar o recorte; o E12 nunca mais é reavaliado).
- O ganho do recorte não apareceu no tempo de resposta (C6, p95 das 300 publicações: .NET 0,030 s com percurso completo; Python 0,050 s com recorte). O volume da Rissi não pede o recorte.

## O que cada spike faz agora

| Spike | Hoje | O que muda na fase 4 |
|---|---|---|
| .NET | Percurso completo (todos os casos abertos do titular) | Nada na reavaliação. Passar nas checagens de idempotência |
| PHP | Percurso completo | Idem |
| Python | Recorte por sementes (PR #20) | Mantém o recorte, mas move o registro em `_Pendencias` para dentro da função única de transição e adiciona teste de arquitetura que impeça mudar `estado` fora dela; o `diferencial_reavaliacao.py` vira critério de aceite (0 divergência em 150 × 150) |
