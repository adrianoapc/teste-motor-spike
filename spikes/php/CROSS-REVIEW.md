# Cross-review da spike PHP — para o Claude auditar antes do merge

Fluxo de revisão mútua (combinado com o Adriano): **o Kiro escreveu esta spike; o
Claude revisa antes do merge.** Este arquivo é o pacote de revisão. Não é código da
spike; é um guia para quem revisa.

## O que é

Spike PHP do motor do fechamento (Rissi), uma das três (`.NET`, Python, PHP) julgadas
pelo **mesmo** verificador (`verificador/verificador.py`) com os **mesmos** cenários
(`cenarios/*.json`). Fonte da verdade do comportamento: `docs/semantica-do-motor.md`
(v3). Contrato HTTP: `contrato/openapi.yaml`. Pasta da spike: `spikes/php/`.

Branch: `spike/php`. Commits: fase 1 (`2a56f05`), fase 2 (`5e9971c`), fase 3 (`bca9e96`).

## Estado declarado (reproduza antes de confiar)

```sh
docker compose up -d                               # Postgres + migrações (base comum)
docker compose --profile php up -d --build         # spike PHP em :8083

# dentro do contêiner (o host pode não ter PHP/composer):
docker compose --profile php exec spike-php composer install
docker compose --profile php exec spike-php ./vendor/bin/phpstan analyse   # nível max, 0 erros
docker compose --profile php exec spike-php ./vendor/bin/pint --test       # sem alteração
docker compose --profile php exec spike-php ./vendor/bin/pest              # 43 testes

# verificador (precisa de httpx + psycopg):
python verificador/verificador.py --api http://localhost:8083 \
  --db postgresql://spike:spike@localhost:5432/spike_php --todos cenarios --fase 3 --limpar
# esperado: RESULTADO: todos passaram (10/10)
```

Medidas em `spikes/php/METRICAS.md`; decisões em `DUVIDAS.md`; erros barrados pelo
PHPStan em `REGISTRO-COMPILADOR.md`.

## O que auditar (prioridade)

1. **Correção vs. semântica, não só "o verificador passou".** O verificador cobre C1-C10,
   mas leia a semântica e confira se a implementação segue a regra, não só o número. Em
   especial:
   - §4.1 (regra de ouro das transições: estado + estado_desde + version + linha de
     transição com a versão da regra do caso + eventos, tudo na mesma transação) —
     `app/Aplicacao/Fechamento/Transicionador.php`.
   - §6 hash canônico e preservação de `{} vs []` — `app/Dominio/.../HashCanonico.php`,
     e a leitura do corpo cru em `app/Api/Controllers/FatosController.php` /
     `ConcluirController.php`. É o modo de falha nº 1 do PHP (`json_decode(...,true)`).
   - §7.4 reavaliação em voltas (um passo por entregável por volta, estado das
     dependências lido no momento) — `app/Aplicacao/Fechamento/AvaliarCaso.php`.
   - §9.1 fórmulas das 8 CFs, arredondamento meio-para-o-par só com inteiros, e §9.2
     entrada_hash / idempotência — `Conferencias/Formulas.php`, `Conferidor.php`,
     `Dominio/.../Dinheiro.php`, `HashConferencia.php`.
   - §10.1/§10.2 protocolo e encerramento no protocolo (v3, sem `pago`); §10.3
     acompanhamento — `AvaliarCaso.php`, `Acompanhar.php`. INV-14: `documento.disponibilizado`
     leva o `fato_id` da **guia**, não do fato de protocolo.
   - §11 invalidação + cascata (sementes, visitados, caso encerrado, substituicao) —
     `Invalidador.php`. §8.1 override — `RegistrarOverride.php`.
2. **Fronteiras de camada.** `dominio` não pode depender de Illuminate/PDO/outras camadas;
   `aplicacao` não pode depender de `api` nem de driver concreto. Há teste de arquitetura
   (`tests/Arquitetura/CamadasTest.php`), mas confira se nenhum vazamento passou.
3. **Dinheiro inteiro em centavos** em todo caminho; nenhum `float`. Recusa de `float` na
   borda (FatosController/ConcluirController).
4. **SQL explícito, sem Eloquent, sem migração do Laravel.** Confirme que nada cria/altera
   esquema e que as tabelas só-inserção (`fato`, `entregavel_transicao`) nunca recebem
   UPDATE/DELETE.
5. **Não tocar a base comum.** `db/`, `contrato/`, `cenarios/`, `verificador/`, `docs/`,
   `docker-compose.yml`, `.kiro/steering/` devem estar intactos. O diff deve conter só
   `spikes/php/` e os checkboxes de `.kiro/specs/spike-php/tasks.md`.
6. **PHPStan nível max sem `@phpstan-ignore` nem baseline** (Requisito 12.1). A única
   exclusão é `tests/Arquitetura/CamadasTest.php` (DSL do Pest sem tipo estático) —
   avalie se é aceitável (ver `DUVIDAS.md` D5).

## Decisões que valem contestar (estão em DUVIDAS.md)

- D5: excluir o arquivo de DSL do Pest da análise do PHPStan em vez de usar baseline.
- D8: reavaliar **todos** os casos abertos do titular após um fato (superconjunto seguro
  de §6.2) — mais simples; vale discutir custo em escala (fase 3 mediu 82.9s/4500).
- D13-D16: ordem de invalidação/acompanhamento, override emitindo o evento do tipo,
  reconferência gerando nova linha de conferência.

## Como responder

Aponte problemas concretos com arquivo:linha e a seção da semântica violada. Se achar
erro na **base comum** (não na spike), diga — mas isso é decisão do Adriano, não se
corrige na spike. **Nunca** altere cenário ou verificador.
