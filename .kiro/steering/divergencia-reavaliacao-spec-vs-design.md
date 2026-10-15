# Divergência: reavaliação após fato (spec §6 x design dos spikes)

**Status: ABERTA — requer decisão do dono da spec.**

## O conflito

`docs/semantica-do-motor.md` §6, passo 2, diz: "Reavaliação: todo entregável de qualquer caso aberto do mesmo `titular_id` que tenha esta chave como entrada resolvida (§7.1, inclusive com `competencia_relativa`) ou como gatilho de pós-validação (§10) é reavaliado." Ou seja, a spec restringe a reavaliação aos **entregáveis** que têm a chave do fato como entrada ou gatilho. Já os `design.md` dos três spikes (`.kiro/specs/spike-*/design.md`, tabela "Decisões fixas", linha "Reavaliação após fato") fixam: "Todos os casos abertos do titular (superconjunto seguro de §6.2)". Os spikes implementam coisas diferentes entre si (tabela abaixo). **A divergência é de semântica normativa, não só de performance.**

## O que cada spike faz (verificado na main)

| Spike | Segue | Como | Evidência | Arquivo / função |
|---|---|---|---|---|
| .NET | design (superconjunto) | Reavalia todos os entregáveis de todos os casos abertos do titular a cada fato | C1–C10 passam; é o percurso completo, usado como referência | `spikes/dotnet/src/Motor.Aplicacao/MotorServico.cs`, `AvaliarCaso.cs` |
| PHP | design (superconjunto) | Idem | C1–C10 passam; percurso completo | `spikes/php/app/Aplicacao/Fatos/PublicarFato.php` -> `AvaliarCaso::avaliarAbertosDoTitular` |
| Python (desde o PR #20) | §6.2 | Reavalia por **sementes**: entradas resolvidas (inclusive `competencia_relativa`), gatilho de pós-validação, entregáveis que mudaram de estado fora da avaliação e quem depende deles. A **abertura** do caso continua avaliando tudo | `scripts/diferencial_reavaliacao.py` compara recorte x percurso completo: 450 sequências (3 x 150 x 150 operações), 0 divergência, 0 não determinismo; C1–C10 com a mesma sequência de transições/eventos da main | `spikes/python/src/motor/aplicacao/motor_fechamento.py` (`_Pendencias`); decisão em `spikes/python/DUVIDAS.md` D11 |

Não existe diferencial equivalente para PHP e .NET (eles fazem o percurso completo, que é a referência). Cobertura do diferencial do Python por execução: ~124–131 `disponibilizado` e ~115–123 `encerrado`. Efeito em SQLs no Python (3 casos abertos do titular), antes -> depois do conjunto de PRs A+B+C (recorte + cache da regra por `versao_id` + outras mudanças; **não é o efeito isolado do recorte**): fato relevante 69 -> 14, fato irrelevante 69 -> 10, fato que leva o E01 a validado 124 -> 37. Risco registrado (D11): um caminho futuro que mude estado sem registrar em `_Pendencias` seria perdido pelo recorte.

## Opções

**Opção A: adotar a §6.2 literal como normativa.**
Exige o teste diferencial recorte x completo como critério de aceite dos três spikes e atualizar os três `design.md`.
- Prós: spec e código dizem a mesma coisa; menos SQLs por fato (ver medição do Python); o Python já está conforme.
- Contras: PHP e .NET precisam implementar o recorte e o diferencial; risco de o recorte perder um caminho que mude estado; mais complexidade e manutenção; a prova disponível (450 sequências, C1–C10) é empírica, não demonstração de que o recorte é equivalente em toda sequência possível.

**Opção B: manter o superconjunto como normativo e emendar a §6.2.**
A emenda diria que reavaliar mais entregáveis é permitido **desde que idempotente**: reavaliar sem mudança de entrada não gera transição, evento, conferência nova nem exceção. O Python passaria a ser conforme (voltando ao percurso amplo) ou o recorte seria documentado como otimização.
- Prós: PHP e .NET já conformes; o percurso completo é a referência mais simples e robusta; sem risco de perda por recorte.
- Contras: custo em SQLs a cada fato (o design já cita a reavaliação de todos os casos como ponto provável de custo); a idempotência passa a ser requisito a provar nos três; o Python teria de reverter o PR #20 ou carregar uma otimização documentada à parte.

## O que o Kiro NÃO deve assumir

- Que a divergência é só de performance: ela é de semântica normativa (quais entregáveis são reavaliados).
- Que o PHP segue a spec: o PHP segue o design (superconjunto), não o texto literal da §6.2.
- Que a reavaliação ampla é sem efeito colateral na spec: só foi observado que as sequências C1–C10 coincidem.

## Pergunta ao dono da spec

**A reavaliação após fato deve reavaliar apenas os entregáveis indicados pela §6.2 (opção A, com o teste diferencial recorte x completo como critério de aceite) ou todos os entregáveis dos casos abertos do titular (opção B, com a §6.2 emendada para exigir que reavaliar sem mudança de entrada seja idempotente)?**

## Inclinação (opinião de quem escreveu, não decisão)

Opinião: a opção A parece defensável porque o Python mostrou equivalência observada em 450 sequências com custo de SQLs bem menor, mas só se o diferencial virar critério de aceite e for portado ao PHP e .NET; sem isso, a opção B é a mais segura. A evidência não prova equivalência em geral.
