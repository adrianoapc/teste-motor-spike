# Dúvidas e decisões — spike Python

Tudo o que a semântica (`docs/semantica-do-motor.md`) não respondeu, e a decisão tomada.
Nenhuma decisão aqui altera a base comum.

## D1 — `docker compose` v2 ausente no ambiente de desenvolvimento

O ambiente usado tinha só `docker-compose` v1 (sem o plugin `docker compose`) e `minio/minio`
não pôde ser puxado. Decisão: para C1/C7 o MinIO/objeto não é usado; subir só
`postgres` + `migrar`. Não altera a base comum — é só a forma de invocar o compose.
Registrado também em `REGISTRO-COMPILADOR.md` se virar erro de tipo.

## D2 — Escopo da reavaliação após publicar fato

A §6.2 pede reavaliar os entregáveis afetados; o design fixou **todos os entregáveis de
todos os casos abertos do titular** como superconjunto seguro. Adotado — é correto e simples;
o custo extra é irrelevante na escala de C1/C7.

## D3 — Ordem de avaliação dentro de uma volta

Semântica não fixa a ordem. Design fixou **ordem de `tipo`** (E01, E02, …). Adotado; a
repetição até ponto fixo (§7.4) torna o resultado independente da ordem, mas a ordem
determinística facilita depurar.

## D4 — `observado_em` das saídas de tarefa

Não especificado. Decisão (design): instante atual do servidor (`now()` do banco).

## D5 — `fatos_usados` de uma conferência

§9.2 diz "lista ordenada por `fato_id`". Para CF numéricas usamos os fatos vigentes do caso
que entram na fórmula (esperado e obtido). Para CF-06/CF-12 (sem par numérico) usamos os
fatos efetivamente lidos pela regra. Ordenado por `fato_id` em todos os casos.
