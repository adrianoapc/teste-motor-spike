# Tarefas — spike PHP

Regras: uma tarefa por vez, na ordem. Ao terminar cada uma, rodar os testes; nas tarefas que dizem "verificador", rodar o verificador. Não pular para a fase 2. Toda decisão não coberta pela semântica vai para `DUVIDAS.md`. Todo erro barrado pelo PHPStan antes de teste vai para `REGISTRO-COMPILADOR.md`.

- [x] 1. Esqueleto e contêiner
  - Criar a estrutura de `spikes/php/` do design, com os quatro arquivos `.md` obrigatórios.
  - Laravel 12 sem Eloquent nem migrações; PHPStan nível `max`, Pint e Pest configurados desde o início.
  - `GET /health` (consulta `SELECT 1`) e `GET /admin/fila` (`{"pendentes":0,"com_erro":0}`).
  - `Dockerfile` ouvindo em 8080; conexão por `MOTOR_DB`; log JSON em stdout.
  - Conferir: `docker compose --profile php up -d --build` e `curl localhost:8083/health`.
  - _Requisitos: 1, 10.1, 10.2_

- [x] 2. Domínio puro com vetores
  - Competência (somar meses, virada de ano, mês do trimestre), condição `quando`, arredondamento meio para o par, hash canônico, `entrada_hash`, ordem dos estados.
  - Testes unitários com **todos** os vetores dos requisitos 4.2, 4.6, 5.1, 5.2, 7.2 e 7.4 (o vetor 1 pega a troca de `{}` por `[]`).
  - Teste de arquitetura das camadas.
  - _Requisitos: 4.2, 5.1, 5.2, 7.2, 7.4, 11_

- [x] 3. Infraestrutura de banco
  - Unidade de trabalho (uma transação por comando).
  - Leitura da versão em uso das regras; leitura de catálogo (`entregavel_tipo`, `conferencia_tipo`).
  - Repositórios de caso, entregável (com `FOR UPDATE`), dependência, fato (vigente por chave), conferência, exceção, tarefa, evento.
  - `Transicionar` única, gravando estado, `estado_desde`, `version` e linha de transição na mesma transação.
  - _Requisitos: 2, 9.1_

- [x] 4. Abrir competências
  - Rota e caso de uso conforme §5. Resposta `criados`/`existentes`. `422` para corpo inválido.
  - _Requisitos: 3_

- [x] 5. Publicar fato e prontidão
  - Rota `/fatos` conforme §6; `AvaliarCaso` conforme §7 (sem conferências ainda: deixar o ponto de chamada).
  - _Requisitos: 4, 5_

- [x] 6. Conferências
  - Fórmulas de §9.1, classificação de §9.2, idempotência e desfecho de §9.3.
  - Testes unitários das fórmulas com os valores do C1 (CF-04: faturamento 10000000, alíquota 600 bp → 600000; CF-08: 28% → 2800000; CF-07: 360000 + 240000 = 600000).
  - _Requisitos: 7_

- [x] 7. Concluir tarefa
  - Rota conforme §8 com `404`/`409`/`422`.
  - _Requisitos: 6_

- [x] 8. Pós-validação, protocolo, encerramento e consulta do caso
  - §10.1 (E11 até `disponibilizado`, com `documento.disponibilizado` levando a guia) e §10.2 (E12). Rota `GET /casos/{titular_id}/{competencia}`.
  - Versão da regra lida pelo `status`, nunca `versao = 1`; nenhum caminho para `pago`.
  - _Requisitos: 8, 20.1–20.4_

- [x] 9. Verificador: C7, depois C1
  - `python verificador/verificador.py --api http://localhost:8083 --db postgresql://spike:spike@localhost:5432/spike_php --cenario cenarios/c7-lp-terceiro-mes-trimestre.json --limpar`
  - Depois `--cenario cenarios/c1-feliz-simples-com-folha.json`, depois `--todos cenarios`.
  - Corrigir a spike até passar. **Nunca** alterar cenário ou verificador; se achar erro neles, registrar em `DUVIDAS.md` e parar.
  - _Requisitos: 9, 12.3_

- [x] 10. Fechamento da fase
  - Preencher `METRICAS.md` pelo modelo de `docs/regras-do-jogo.md`.
  - Revisar `README.md`, `DUVIDAS.md`, `REGISTRO-COMPILADOR.md`.
  - _Requisitos: 12_

## Fase 2 · invalidação, exceções, override e protocolo de entrega

Antes da tarefa 11: `git merge origin/main`, para trazer correções da base feitas depois do início. A regressão da fase 1 é `--todos cenarios --fase 1`.

- [x] 11. Conferir a v3 (nesta spike não há migração de domínio)
  - A v3 já foi feita na fase 1 (tarefa 8). Rodar `--fase 1` e confirmar 2/2.
  - Registrar em `METRICAS.md` (seção Fase 2) que esta tarefa não se aplica: a medida de manutenção da v3 só existe nas spikes .NET e Python.
  - _Requisitos: 20.1–20.4_

- [x] 12. Reavaliação em passos (§7.4) e regressão
  - Conferir que a reavaliação dá no máximo um passo por entregável por volta, em ordem de `tipo`, lendo o estado atual das dependências. Ajustar se preciso.
  - Rodar `--fase 1` e confirmar 2/2.
  - _Requisitos: 13_

- [x] 13. Exceções: abertura única e resolução (§9.4)
  - Abertura única por (entregável, tipo) ou (caso, tipo); resolução `fato_alterado`; divergência A não abre exceção.
  - Testes unitários das regras de exceção.
  - _Requisitos: 15_

- [x] 14. Invalidação e cascata (§11)
  - Sementes, tabela por estado, cascata com visitados, caso encerrado, ordem dentro da transação (§11.4).
  - `ConcluirTarefa`: não invalida o próprio entregável; liga a saída mesmo com `sem_mudanca` (§8, item 4).
  - CF-09: par de maior diferença (§9.1).
  - Testes unitários da cascata sobre um grafo em memória (sem banco), cobrindo os estados de §11.2.
  - Verificador: `--cenario cenarios/c4-r6-guia-retificada-duas-vezes.json`, depois C3, C2, C5, C8.
  - _Requisitos: 14, 17_

- [x] 15. Override (§8.1)
  - Rota, validação 422 → 404 → 409, efeitos e evento `conferencia.override`.
  - Verificador: `--cenario cenarios/c9-override-conferencia.json`.
  - _Requisitos: 16_

- [x] 16. Acompanhamento depois do protocolo (§10.3)
  - Ler o estado do E11 antes da reavaliação; aplicar a tabela de §10.3 depois dela, em caso aberto ou encerrado, sem transição.
  - Resoluções `reenviado` e `recebido`; evento `recebimento.confirmado`; `recebimento_pendente` só sem confirmação vigente.
  - Testes unitários da tabela de §10.3.
  - Verificador: `--cenario cenarios/c10-acompanhamento-apos-protocolo.json`.
  - _Requisitos: 20.5–20.8_

- [x] 17. Fechamento da fase 2
  - `--todos cenarios --fase 2 --limpar --relatorio resultado-fase2-php.json` com 9/9.
  - `METRICAS.md` (seção Fase 2), `DUVIDAS.md`, `REGISTRO-COMPILADOR.md`.
  - _Requisitos: 18_

## Fase 3 · escala

- [x] 18. Cenário C6
  - Rodar `--cenario cenarios/c6-escala-4500-casos.json --limpar --relatorio resultado-c6-php.json` com só esta spike no ar.
  - Se não cumprir os limites: medir onde está o tempo antes de mudar (registrar em `DUVIDAS.md`), e só então otimizar ou passar para `eventos.fila`.
  - Fechar com `--todos cenarios --fase 3` (10/10) e a seção "Fase 3" em `METRICAS.md`.
  - _Requisitos: 19_
