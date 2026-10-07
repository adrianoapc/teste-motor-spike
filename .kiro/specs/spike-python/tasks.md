# Tarefas — spike Python

Regras: uma tarefa por vez, na ordem. Ao terminar cada uma, rodar os testes; nas tarefas que dizem "verificador", rodar o verificador. Não pular para a fase 2. Toda decisão não coberta pela semântica vai para `DUVIDAS.md`. Todo erro barrado pelo mypy antes de teste vai para `REGISTRO-COMPILADOR.md`.

- [ ] 1. Esqueleto e contêiner
  - Criar a estrutura de `spikes/python/` do design, com os quatro arquivos `.md` obrigatórios.
  - `GET /health` (consulta `SELECT 1`) e `GET /admin/fila` (`{"pendentes":0,"com_erro":0}`).
  - `Dockerfile` ouvindo em 8080; conexão por `MOTOR_DB`; log JSON em stdout.
  - Conferir: `docker compose --profile python up -d --build` e `curl localhost:8082/health`.
  - _Requisitos: 1, 10.1, 10.2_

- [ ] 2. Domínio puro com vetores
  - Competência (somar meses, virada de ano, mês do trimestre), condição `quando`, arredondamento meio para o par, hash canônico, `entrada_hash`, ordem dos estados.
  - Testes unitários com **todos** os vetores dos requisitos 4.2, 5.1, 5.2, 7.2 e 7.4.
  - Teste de arquitetura das camadas.
  - _Requisitos: 4.2, 5.1, 5.2, 7.2, 7.4, 11_

- [ ] 3. Infraestrutura de banco
  - Unidade de trabalho (uma transação por comando).
  - Leitura da versão em uso das regras; leitura de catálogo (`entregavel_tipo`, `conferencia_tipo`).
  - Repositórios de caso, entregável (com `FOR UPDATE`), dependência, fato (vigente por chave), conferência, exceção, tarefa, evento.
  - `Transicionar` única, gravando estado, `estado_desde`, `version` e linha de transição na mesma transação.
  - _Requisitos: 2, 9.1_

- [ ] 4. Abrir competências
  - Rota e caso de uso conforme §5. Resposta `criados`/`existentes`. `422` para corpo inválido.
  - _Requisitos: 3_

- [ ] 5. Publicar fato e prontidão
  - Rota `/fatos` conforme §6; `AvaliarCaso` conforme §7 (sem conferências ainda: deixar o ponto de chamada).
  - _Requisitos: 4, 5_

- [ ] 6. Conferências
  - Fórmulas de §9.1, classificação de §9.2, idempotência e desfecho de §9.3.
  - Testes unitários das fórmulas com os valores do C1 (CF-04: faturamento 10000000, alíquota 600 bp → 600000; CF-08: 28% → 2800000; CF-07: 360000 + 240000 = 600000).
  - _Requisitos: 7_

- [ ] 7. Concluir tarefa
  - Rota conforme §8 com `404`/`409`/`422`.
  - _Requisitos: 6_

- [ ] 8. Pós-validação, encerramento e consulta do caso
  - §10.1 (E11) e §10.2 (E12). Rota `GET /casos/{titular_id}/{competencia}`.
  - _Requisitos: 8_

- [ ] 9. Verificador: C7, depois C1
  - `python verificador/verificador.py --api http://localhost:8082 --db postgresql://spike:spike@localhost:5432/spike_python --cenario cenarios/c7-lp-terceiro-mes-trimestre.json --limpar`
  - Depois `--cenario cenarios/c1-feliz-simples-com-folha.json`, depois `--todos cenarios`.
  - Corrigir a spike até passar. **Nunca** alterar cenário ou verificador; se achar erro neles, registrar em `DUVIDAS.md` e parar.
  - _Requisitos: 9, 12.3_

- [ ] 10. Fechamento da fase
  - Preencher `METRICAS.md` pelo modelo de `docs/regras-do-jogo.md`.
  - Revisar `README.md`, `DUVIDAS.md`, `REGISTRO-COMPILADOR.md`.
  - _Requisitos: 12_

## Fase 2 · invalidação, exceções e override

Antes da tarefa 11: `git merge origin/main` (traz a semântica v2, os cenários novos e o verificador com `--fase`). A partir de agora, a regressão da fase 1 é `--todos cenarios --fase 1`.

- [ ] 11. Reavaliação em passos (§7.4) e regressão
  - Conferir que a reavaliação dá no máximo um passo por entregável por volta, em ordem de `tipo`, lendo o estado atual das dependências. Ajustar se preciso.
  - Rodar `--fase 1` e confirmar 2/2.
  - _Requisitos: 13_

- [ ] 12. Exceções: abertura única e resolução (§9.4)
  - Abertura única por (entregável, tipo) ou (caso, tipo); resolução `fato_alterado`; divergência A não abre exceção.
  - Testes unitários das regras de exceção.
  - _Requisitos: 15_

- [ ] 13. Invalidação e cascata (§11)
  - Sementes, tabela por estado, cascata com visitados, caso encerrado, ordem dentro da transação (§11.4).
  - `ConcluirTarefa`: não invalida o próprio entregável; liga a saída mesmo com `sem_mudanca` (§8, item 4).
  - CF-09: par de maior diferença (§9.1).
  - Testes unitários da cascata sobre um grafo em memória (sem banco), cobrindo os estados de §11.2.
  - Verificador: `--cenario cenarios/c4-r6-guia-retificada-duas-vezes.json`, depois C3, C2, C5, C8.
  - _Requisitos: 14, 17_

- [ ] 14. Override (§8.1)
  - Rota, validação 422 → 404 → 409, efeitos e evento `conferencia.override`.
  - Verificador: `--cenario cenarios/c9-override-conferencia.json`.
  - _Requisitos: 16_

- [ ] 15. Fechamento da fase 2
  - `--todos cenarios --fase 2 --limpar --relatorio resultado-fase2-python.json` com 8/8.
  - `METRICAS.md` (seção Fase 2), `DUVIDAS.md`, `REGISTRO-COMPILADOR.md`.
  - _Requisitos: 18_

## Fase 3 · escala

- [ ] 16. Cenário C6
  - Rodar `--cenario cenarios/c6-escala-4500-casos.json --limpar --relatorio resultado-c6-python.json` com só esta spike no ar.
  - Se não cumprir os limites: medir onde está o tempo antes de mudar (registrar em `DUVIDAS.md`), e só então otimizar ou passar para `eventos.fila`.
  - Fechar com `--todos cenarios --fase 3` (9/9) e a seção "Fase 3" em `METRICAS.md`.
  - _Requisitos: 19_
