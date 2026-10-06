# Requisitos — spike Python

**Escopo desta fase:** cenários **C1** e **C7** (`cenarios/`). Semântica normativa: `docs/semantica-do-motor.md` (citada como §n). Contrato HTTP: `contrato/openapi.yaml`. Porta no host: **8082**. Banco: **spike_python**. Pasta editável: **`spikes/python/`**.

Formato: cada requisito tem uma história e critérios de aceite no padrão "QUANDO … ENTÃO O SISTEMA DEVE …". Todo critério aponta para a seção da semântica ou para a checagem do verificador que o comprova. **Nada além disto deve ser implementado nesta fase.**

---

### Requisito 1 · Serviço em contêiner

**História:** como avaliador, quero subir a spike com um comando e a mesma imagem em qualquer máquina, para comparar as duas spikes nas mesmas condições.

1. O SISTEMA DEVE ter um `Dockerfile` em `spikes/python/` que produz uma imagem que ouve na porta 8080.
2. O SISTEMA DEVE ler a conexão com o banco só da variável `MOTOR_DB`; nenhum valor de conexão no código ou na imagem.
3. QUANDO `docker compose --profile python up -d --build` for executado na raiz, ENTÃO o serviço DEVE responder `GET /health` com `200 {"status":"ok"}` depois de consultar o banco.
4. O SISTEMA NÃO DEVE guardar estado de negócio em memória entre requisições.
5. O SISTEMA DEVE escrever logs em stdout, uma linha JSON por registro.

### Requisito 2 · Banco somente pelo esquema existente

**História:** como avaliador, quero que as duas spikes usem exatamente o mesmo modelo de dados, para que a comparação seja só de linguagem.

1. O SISTEMA DEVE usar só tabelas e views existentes em `db/migrations/`.
2. O SISTEMA NÃO DEVE criar, alterar ou apagar tabela, coluna, índice, view, função ou regra de banco.
3. O SISTEMA NÃO DEVE executar `UPDATE` ou `DELETE` em `fatos.fato` nem em `fechamento.entregavel_transicao` (o banco recusa com erro).
4. QUANDO precisar de uma regra, ENTÃO o SISTEMA DEVE ler a versão em uso de `regras.regra_versao` (`status IN ('provisoria','ativa')`) (§2).

### Requisito 3 · Abrir competências

**História:** como agenda do dia 1, quero abrir os casos de uma lista de empresas, para que o fechamento comece sem ninguém montar planilha.

1. QUANDO `POST /competencias/abrir` receber um corpo válido, ENTÃO O SISTEMA DEVE criar um caso por empresa nova e responder `200` com `criados` e `existentes` (§5).
2. QUANDO o caso (`titular_id`, `competencia`) já existir, ENTÃO O SISTEMA NÃO DEVE criar nada para aquela empresa e DEVE listá-la em `existentes` (§5.1). *Verificador: C1 passo 2.*
3. O SISTEMA DEVE criar só os entregáveis cujo `quando` casa com o contexto (§3, §5.3). *Verificador: `entregaveis_inexistentes` em C1 e C7.*
4. O SISTEMA DEVE gravar as dependências conforme §5.4, ignorando as que apontam para entregável inexistente no caso.
5. O SISTEMA DEVE gravar a transição de criação (`de_estado` nulo, `para_estado = aguardando_insumo`, `motivo = abrir_competencia`) e o evento `competencia.aberta` (§5, §12).
6. Depois de criar, O SISTEMA DEVE avaliar a prontidão de todos os entregáveis do caso (§5.6). *Verificador: C1 passo 4 (E06 já em `pronto` com tarefa).*
7. QUANDO o corpo for inválido segundo o contrato, ENTÃO O SISTEMA DEVE responder `422` com `{"erro","mensagem"}`.

### Requisito 4 · Publicar fato

**História:** como adaptador de uma fonte, quero publicar um valor uma ou várias vezes, para que o motor só reaja quando o valor de fato mudar.

1. O SISTEMA DEVE calcular o hash conforme §6 e responder `efeito` = `novo`, `nova_versao` ou `sem_mudanca`.
2. O SISTEMA DEVE produzir exatamente estes hashes (vetores de teste obrigatórios em teste unitário):

| JSON canônico de entrada | SHA-256 |
|---|---|
| `{"payload":{},"valor_centavos":10000000}` | `1e949949f87138fe966f349922ebff0167b25b1ad5fdc5f7432f7252355faa3d` |
| `{"payload":{"aliquota_bp":600},"valor_centavos":null}` | `5bafcc08855a4f461c99d302521e44641944b690fc4ab93f56375599289c1449` |
| `{"payload":{"parcelas":[{"socio":"S1","valor_centavos":360000},{"socio":"S2","valor_centavos":240000}]},"valor_centavos":null}` | `b405b94732752a986eb16f76b252ffb975b339e8fbeb56b6012cfac2d9f192ad` |
| objeto `{"payload":{"descrição":"ção","b":1,"a":{"z":1,"y":2}},"valor_centavos":5}` (as chaves devem ser ordenadas pelo sistema) | `a8b6cf1558cc659b04237ac8295a0f3556b60efb6b06a9b7be922431c745b0b0` |

3. QUANDO o hash for igual ao da versão vigente, ENTÃO O SISTEMA NÃO DEVE gravar nada (§6). *Verificador: C1 passo 7 e `fatos.versoes = 1`.*
4. QUANDO gravar, ENTÃO O SISTEMA DEVE emitir `fato.publicado` com `caso_id` preenchido se existir caso do titular naquela competência (§12), e reavaliar os entregáveis afetados de todos os casos abertos do titular, inclusive por `competencia_relativa` (§6.2). *Verificador: C7.*
5. O SISTEMA DEVE aceitar fato de competência sem caso aberto. *Verificador: C7 (202607 e 202608).*

### Requisito 5 · Prontidão e propagação

**História:** como operação, quero que cada entregável fique pronto sozinho quando o que ele precisa chegar, sem rodada manual.

1. O SISTEMA DEVE resolver as entradas conforme §7.1, inclusive `competencia_relativa` com virada de ano (`202601` − 1 = `202512`).
2. O SISTEMA DEVE calcular `mes_do_trimestre = ((mes − 1) % 3) + 1`.
3. O SISTEMA DEVE levar o entregável a `pronto` se e somente se as condições de §7.2 forem verdadeiras, e gravar `entregavel_fato` (papel `entrada`).
4. QUANDO o executor for `sistema`, ENTÃO O SISTEMA DEVE passar imediatamente a `processado` e conferir (§7.3).
5. QUANDO o executor for `humano`, ENTÃO O SISTEMA DEVE criar uma `tarefa_humana` aberta com `responsavel` = carteira do caso (§7.3).
6. O SISTEMA DEVE repetir a reavaliação do caso até não haver mudança (§7.4).

### Requisito 6 · Concluir tarefa humana

**História:** como analista, quero informar o resultado do meu trabalho, para que o motor confira e siga adiante.

1. `POST /casos/{titular_id}/{competencia}/entregaveis/{tipo}/concluir` DEVE seguir §8, com os códigos `404`, `409`, `422` e `200` descritos lá.
2. O SISTEMA DEVE publicar cada item de `saidas` como fato (`fonte = 'tarefa'`), ligá-lo com papel `saida`, concluir a tarefa, gravar a transição `pronto → processado` com `motivo = tarefa_concluida` e o id da tarefa, e conferir.

### Requisito 7 · Conferências

**História:** como coordenação fiscal, quero que cada valor seja conferido por regra escrita, com rastro, para não depender de alguém olhar planilha.

1. O SISTEMA DEVE implementar exatamente as fórmulas de §9.1 para CF-01, 04, 05, 06, 07, 08, 09 e 12.
2. O SISTEMA DEVE arredondar meio para o par, só com inteiros. Vetores obrigatórios em teste unitário: `arred(60000000000, 10000) = 6000000`; `arred(5, 2) = 2`; `arred(7, 2) = 4`; `arred(15, 10) = 2`; `arred(25, 10) = 2`; `arred(26, 10) = 3`.
3. O SISTEMA DEVE classificar resultado e severidade conforme §9.2 usando `fiscal.tolerancia`.
4. O SISTEMA DEVE gravar `entrada_hash` conforme §9.2. Vetor obrigatório: `{"cf":"CF-01","fatos_usados":[{"fato_id":"00000000-0000-0000-0000-000000000001","versao":1},{"fato_id":"00000000-0000-0000-0000-000000000002","versao":1}],"regra_versao_id":"00000000-0000-0000-0000-0000000000aa"}` → `4e0ad4ed509cc1fa2e908b6ef9253d5927588b01a9001b8195c7185e75c878ca`.
5. O SISTEMA NÃO DEVE gravar duas conferências com o mesmo (`cf`, `entregavel_id`, `entrada_hash`). *Verificador: `quantidade = 1` e `conferencias_total`.*
6. O SISTEMA DEVE aplicar o desfecho de §9.3 (validado, ou divergente com exceção e eventos).

### Requisito 8 · Pós-validação e encerramento

1. O SISTEMA DEVE aplicar §10.1 ao E11 (liberação automática, disponibilização e pagamento pelo tributo da guia de entrada), inclusive quando o fato chega antes do estado anterior. *Verificador: C1 sequência de E11.*
2. O SISTEMA DEVE aplicar §10.2 ao E12 e encerrar o caso. *Verificador: C1 caso `encerrado`, E12 `… validado, encerrado`.*

### Requisito 9 · Transições e eventos

1. Toda mudança de estado DEVE seguir §4.1 na mesma transação: estado, `estado_desde`, `version`, linha de transição com a versão da regra do caso, eventos. *Verificador: INV-1, INV-2, INV-3, INV-5, INV-6.*
2. O SISTEMA DEVE usar só os valores de `motivo` de §4.1 e os nomes de evento de §12.
3. O payload de evento DEVE conter só as chaves de §12. *Verificador: INV-7.*

### Requisito 10 · Fila e concorrência

1. `GET /admin/fila` DEVE responder `pendentes = 0` só quando todo efeito das requisições já estiver gravado (§13).
2. Nesta fase, o processamento PODE ser síncrono dentro da requisição; nesse caso `pendentes` é sempre 0.
3. Duas requisições simultâneas sobre o mesmo caso NÃO DEVEM gerar transições duplicadas (trava de linha ou `version`).

### Requisito 11 · Arquitetura limpa e modular

1. O código DEVE seguir as camadas e módulos de `.kiro/steering/structure.md`.
2. DEVE existir teste automatizado de arquitetura que falha se `dominio` depender de outra camada, ou `aplicacao` depender de `api` ou de driver de banco.
3. As funções de domínio (condições `quando`, competência relativa, mês do trimestre, arredondamento, hash canônico, fórmulas das conferências, regra de prontidão) DEVEM ser puras e testadas sem banco.

### Requisito 12 · Qualidade e entrega

1. O código DEVE passar em `mypy --strict` sem `# type: ignore`, e em `ruff check`. Modelos de entrada e saída com pydantic v2.
2. Todos os testes unitários e de arquitetura DEVEM passar.
3. QUANDO a spike estiver no ar, ENTÃO `python verificador/verificador.py --api http://localhost:8082 --db postgresql://spike:spike@localhost:5432/spike_python --todos cenarios --limpar` DEVE terminar com `RESULTADO: todos passaram (2/2)`.
4. `spikes/python/README.md`, `DUVIDAS.md`, `METRICAS.md` e `REGISTRO-COMPILADOR.md` DEVEM existir e estar preenchidos.

### Fora desta fase (não implementar)

Invalidação e cascata (§11); override (rota existe no contrato, mas só será exercida no cenário 2); cenários 2 a 6; fila assíncrona para lote; autenticação; RLS; conectores reais; qualquer tela.
