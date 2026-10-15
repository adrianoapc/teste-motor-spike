# Reavaliação após fato: ambiguidade na §6 passo 2 da spec (piso ou exato?)

**Status: ABERTA — ambiguidade na §6 passo 2 da spec (piso ou exato?). A spec prevalece (`product.md`, regra 1); o design não decide.**

## O problema

`docs/semantica-do-motor.md` §6, passo 2, diz: "Reavaliação: todo entregável de qualquer caso aberto do mesmo `titular_id` que tenha esta chave como entrada resolvida (§7.1, inclusive com `competencia_relativa`) ou como gatilho de pós-validação (§10) é reavaliado." O texto diz o que **deve** ser reavaliado; **não diz "apenas"**. Há duas leituras legítimas:

- **PISO (mínimo):** esses entregáveis têm de ser reavaliados; reavaliar mais é permitido. É a leitura do design ("superconjunto seguro de §6.2").
- **EXATO:** só esses entregáveis são reavaliados. É a leitura do Python.

A spec é, portanto, ambígua. O `design.md` de cada spike fixou uma interpretação ("Todos os casos abertos do titular (superconjunto seguro de §6.2)") sem autoridade para fazê-lo, e os spikes implementaram leituras diferentes. A divergência é de semântica, não só de performance.

## O que cada spike implementa (verificado na main)

| Spike | Leitura que implementa | Como | Evidência | Arquivo / função |
|---|---|---|---|---|
| .NET | PISO (superconjunto) | Reavalia todos os entregáveis de todos os casos abertos do titular a cada fato | C1–C10 passam; é o percurso completo, usado como referência | `spikes/dotnet/src/Motor.Aplicacao/MotorServico.cs`, `AvaliarCaso.cs` |
| PHP | PISO (superconjunto) | Idem | C1–C10 passam; percurso completo | `spikes/php/app/Aplicacao/Fatos/PublicarFato.php` -> `AvaliarCaso::avaliarAbertosDoTitular` |
| Python (desde o PR #20) | EXATO (sementes) | Reavalia por **sementes**: entradas resolvidas (inclusive `competencia_relativa`), gatilho de pós-validação, entregáveis que mudaram de estado fora da avaliação e quem depende deles. A **abertura** do caso continua avaliando tudo | `scripts/diferencial_reavaliacao.py` compara recorte x percurso completo: 450 sequências (3 x 150 x 150 operações), 0 divergência, 0 não determinismo; C1–C10 com a mesma sequência de transições/eventos da main | `spikes/python/src/motor/aplicacao/motor_fechamento.py` (`_Pendencias`); `spikes/python/DUVIDAS.md` D11 |

Não existe diferencial equivalente para PHP e .NET (eles fazem o percurso completo, que é a referência). Cobertura do diferencial do Python por execução: ~124–131 `disponibilizado` e ~115–123 `encerrado`. SQLs no Python (3 casos abertos do titular), antes -> depois do conjunto de PRs A+B+C (recorte + cache da regra por `versao_id` + outras mudanças; **não é o efeito isolado do recorte**): fato relevante 69 -> 14, fato irrelevante 69 -> 10, fato que leva o E01 a validado 124 -> 37. Risco registrado (D11): um caminho futuro que mude estado sem registrar em `_Pendencias` seria perdido pelo recorte.

## Instrução ao Kiro

- **Não assuma nenhuma das duas leituras.**
- Leve a pergunta abaixo ao dono da spec e **corrija a §6.2** para dizer explicitamente "apenas" ou "no mínimo".
- **Só depois** ajuste os `design.md`.
- Até lá, registre em `DUVIDAS.md` do spike o que ele implementa. As linhas "Reavaliação após fato" dos `design.md` estão na seção "Interpretações em aberto (sem autoridade sobre a spec)", não em "Decisões fixas".

## Consequências conforme a resposta

**Se EXATO -> opção A.** O teste diferencial recorte x completo vira critério de aceite dos três spikes, portado ao PHP e ao .NET; os `design.md` são ajustados; PHP e .NET deixam de ser conformes até implementarem o recorte.
- Prós: spec e código dizem a mesma coisa; menos SQLs por fato (medição do Python); o Python já está conforme.
- Contras: PHP e .NET precisam implementar o recorte e o diferencial; risco de o recorte perder um caminho que mude estado; mais complexidade e manutenção; a prova disponível (450 sequências, C1–C10) é empírica, não demonstração de equivalência em toda sequência possível.

**Se PISO -> opção B.** Emendar a §6.2 exigindo idempotência: reavaliar sem mudança de entrada não gera transição, evento, conferência nova nem exceção. O Python passa a ser uma otimização documentada, não um requisito.
- Prós: PHP e .NET já conformes; o percurso completo é a referência mais simples e robusta; sem risco de perda por recorte.
- Contras: custo em SQLs a cada fato (o design já cita a reavaliação de todos os casos como ponto provável de custo); a idempotência passa a ser requisito a provar nos três; o Python teria de reverter o PR #20 ou manter a otimização documentada à parte.

## O que o Kiro NÃO deve assumir

- Que a divergência é só de performance: é de semântica (o que a §6 passo 2 obriga).
- Que o PHP segue "a spec": o PHP implementa a leitura PISO, que é uma das duas possíveis.
- Que o `design.md` prevalece sobre a spec: a spec é a fonte da verdade (`product.md`, regra 1).
- Que a reavaliação ampla é sem efeito colateral: só foi observado que as sequências C1–C10 coincidem.

## Pergunta ao dono da spec

**A §6 passo 2 define o conjunto EXATO de entregáveis a reavaliar após um fato (então reavaliar mais é não conformidade) ou o conjunto MÍNIMO (então reavaliar mais é permitido, desde que idempotente)?**

## Inclinação (opinião de quem escreveu, não decisão)

Esta opinião só vale depois de a ambiguidade ser resolvida, e a regra de precedência já aponta que a spec decide. Se a resposta for EXATO, a evidência do Python (450 sequências sem divergência, menos SQLs) sustenta a opção A, desde que o diferencial seja portado; se for PISO, a opção B é a mais simples. A evidência não prova equivalência em geral.
