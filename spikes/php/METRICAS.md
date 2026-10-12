# Métricas — spike PHP

Modelo em `docs/regras-do-jogo.md` §4. Fase 1 (cenários C1 e C7).

## Correção
- Verificador (2026-10-12, branch spike/php): **C1 passou (170 checagens, 0 falhas); C7 passou (92 checagens, 0 falhas)** — `RESULTADO: todos passaram (2/2)`.
- Invariantes: todas ok (INV-1 a INV-15, embutidas na saída do verificador).
- Indicadores (segundos): I1≈0.001 · I2≈0.001 · I3≈0.0005 · I4≈0.001 · I5≈0.0015 · I6≈0.001 (todos muito abaixo do limite de 2 s).

## Tamanho (sem testes e sem gerados)
| Camada | Arquivos | Linhas |
|---|---|---|
| dominio | 20 | 817 |
| aplicacao | 26 | 1685 |
| infraestrutura | 12 | 887 |
| api | 7 | 285 |
| Providers + rotas | 2 | 79 |
| **Total** | **67** | **3753** |
| Testes (à parte) | 8 | 330 |

## Esforço do agente
- Tarefas concluídas: 10/10 (fase 1).
- Vezes que uma tarefa foi refeita: 0 (o verificador passou 2/2 na primeira execução, sem correção de lógica).
- Intervenções humanas: 1 — o usuário autorizou a execução autônoma até o portão do verificador (sem decidir nada de domínio).
- Tempo de relógio do início ao verificador passando: ~1 h (sessão única, dividida em ciclos de auto-condução).

## Rede de proteção
- Erros barrados pelo PHPStan (nível `max`) antes de teste: ~50, em três levas (ver `REGISTRO-COMPILADOR.md`). Nenhum `@phpstan-ignore`, nenhum baseline. Todos corrigidos antes de rodar testes.

## Imagem
- Tamanho da imagem: ~908 MB (FrankenPHP `1-php8.4` + extensões + vendor).
- Tempo de `/health` responder (contêiner já no ar, modo clássico): ~0.014 s por requisição.

## Observações
- Stack: Laravel 12 / PHP 8.4 / FrankenPHP modo clássico, SQL explícito pela fachada `DB`, sem Eloquent e sem migrações do Laravel.
- O motor passou C1 (fluxo feliz SN completo até o encerramento no protocolo) e C7 (LP, 3º mês do trimestre, com entrada por `competencia_relativa`) sem nenhuma correção de lógica no portão — a semântica v3 foi implementada direto da fonte da verdade.
- Esta spike já nasceu na v3 (fim no protocolo, estado `pago` aposentado, regra v2 em uso pela V006): a fase 1 já cobre os itens 1-4 do Requisito 20.
- Fases 2 (invalidação/exceções/override/acompanhamento) e 3 (escala C6) ficam fora desta entrega.
