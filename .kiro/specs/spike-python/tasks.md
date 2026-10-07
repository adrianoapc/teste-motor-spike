# Tarefas — spike Python

Regras: uma tarefa por vez, na ordem. Ao terminar cada uma, rodar os testes; nas tarefas que dizem "verificador", rodar o verificador. Não pular para a fase 2. Toda decisão não coberta pela semântica vai para `DUVIDAS.md`. Todo erro barrado pelo mypy antes de teste vai para `REGISTRO-COMPILADOR.md`.

- [x] 1. Esqueleto e contêiner
  - Criar a estrutura de `spikes/python/` do design, com os quatro arquivos `.md` obrigatórios.
  - `GET /health` (consulta `SELECT 1`) e `GET /admin/fila` (`{"pendentes":0,"com_erro":0}`).
  - `Dockerfile` ouvindo em 8080; conexão por `MOTOR_DB`; log JSON em stdout.
  - Conferir: `docker compose --profile python up -d --build` e `curl localhost:8082/health`.
  - _Requisitos: 1, 10.1, 10.2_

- [x] 2. Domínio puro com vetores
  - Competência (somar meses, virada de ano, mês do trimestre), condição `quando`, arredondamento meio para o par, hash canônico, `entrada_hash`, ordem dos estados.
  - Testes unitários com **todos** os vetores dos requisitos 4.2, 5.1, 5.2, 7.2 e 7.4.
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

- [x] 8. Pós-validação, encerramento e consulta do caso
  - §10.1 (E11) e §10.2 (E12). Rota `GET /casos/{titular_id}/{competencia}`.
  - _Requisitos: 8_

- [x] 9. Verificador: C7, depois C1
  - `python verificador/verificador.py --api http://localhost:8082 --db postgresql://spike:spike@localhost:5432/spike_python --cenario cenarios/c7-lp-terceiro-mes-trimestre.json --limpar`
  - Depois `--cenario cenarios/c1-feliz-simples-com-folha.json`, depois `--todos cenarios`.
  - Corrigir a spike até passar. **Nunca** alterar cenário ou verificador; se achar erro neles, registrar em `DUVIDAS.md` e parar.
  - _Requisitos: 9, 12.3_

- [x] 10. Fechamento da fase
  - Preencher `METRICAS.md` pelo modelo de `docs/regras-do-jogo.md`.
  - Revisar `README.md`, `DUVIDAS.md`, `REGISTRO-COMPILADOR.md`.
  - _Requisitos: 12_

## Fase 2 (não iniciar sem novos cenários na pasta `cenarios/`)

- [ ] 11. Invalidação e cascata (§11) e override.
- [ ] 12. Cenários 2 a 5.
- [ ] 13. Cenário 6 (escala): fila assíncrona em `eventos.fila`, massa de `massa/gerar_massa_sintetica.py`.
