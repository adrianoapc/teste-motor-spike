<?php

declare(strict_types=1);

// ConcluirTarefa (§8) e RegistrarOverride (§8.1) sem banco.

use App\Aplicacao\Fechamento\ConcluirTarefaComando;
use App\Aplicacao\Fechamento\RegistrarOverrideComando;
use App\Aplicacao\Fechamento\ResultadoEntregavel;
use App\Aplicacao\Fechamento\SaidaDaTarefa;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\EstadoEntregavel;
use Tests\Unidade\Fakes\Mundo;

/** Caso SN com E01 e E02 validados e o E03 humano `pronto` (tarefa aberta). */
function casoComE03Pronto(Mundo $m): Caso
{
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 5000000);
    $m->fato('aliquota_mes', null, (object) ['aliquota_bp' => 600]);

    return $caso;
}

/** @param  list<SaidaDaTarefa>|null  $saidas */
function concluirE03(Mundo $m, ?array $saidas = null, string $tipo = 'E03', string $titular = 'T1'): ResultadoEntregavel
{
    return $m->concluir->concluir(new ConcluirTarefaComando(
        $titular, Mundo::COMP, $tipo, 'ana', $saidas ?? [new SaidaDaTarefa('DAS', 300000, new stdClass)],
    ));
}

function codigoDoErro(Closure $acao): string
{
    try {
        $acao();
    } catch (ErroDeNegocio $e) {
        return $e->status.':'.$e->codigo;
    }

    return 'sem erro';
}

it('conclui: publica a saída como fato da tarefa, fecha a tarefa e vai a validado (§8)', function (): void {
    $m = new Mundo;
    $caso = casoComE03Pronto($m);

    $r = concluirE03($m);

    $apurado = $m->fatos->vigente('T1', Mundo::COMP, 'apurado', 'DAS') ?? throw new RuntimeException;
    expect($r->tipo)->toBe('E03')
        ->and($r->estado)->toBe('validado')
        ->and($m->tarefas->estados())->toBe(['concluida'])
        ->and($apurado->versao)->toBe(1)
        ->and($apurado->valorCentavos)->toBe(300000)
        ->and($m->entregaveis->fatosLigados[count($m->entregaveis->fatosLigados) - 1])->toBe(['entregavelId' => $m->idDe($caso, 'E03'), 'fatoId' => $apurado->id, 'papel' => 'saida'])
        ->and($m->eventos->quantos('apuracao.concluida'))->toBe(1)
        ->and($m->eventos->quantos('fato.publicado'))->toBe(4);
});

it('registra a transição pronto -> processado com o ator informado, causada pela tarefa (§4.1)', function (): void {
    $m = new Mundo;
    casoComE03Pronto($m);
    $tarefaId = array_key_first($m->tarefas->tarefas);

    concluirE03($m);

    $t = array_values(array_filter($m->entregaveis->transicoes, static fn (array $x): bool => $x['motivo'] === 'tarefa_concluida'));
    expect($t)->toHaveCount(1)
        ->and($t[0])->toMatchArray(['tipo' => 'E03', 'de' => 'pronto', 'para' => 'processado', 'ator' => 'ana', 'causadoPorTipo' => 'tarefa', 'causadoPorId' => $tarefaId]);
});

it('apurado diferente do esperado: CF-04 diverge, o entregável vai a divergente e abre exceção CF-04 (classe S)', function (): void {
    $m = new Mundo;
    $caso = casoComE03Pronto($m);

    $r = concluirE03($m, [new SaidaDaTarefa('DAS', 299000, new stdClass)]);

    expect($r->estado)->toBe('divergente')
        ->and($m->excecoes->resumo())->toBe(['CF-04:aberta'])
        ->and($m->excecoes->excecoes['exc-1']['classe'])->toBe('S')
        ->and($m->estado($caso, 'E10'))->toBe('aguardando_insumo');
});

it('mesma saída que o fato vigente: não cria versão nem evento, mas liga a versão vigente como saída (§8 item 4)', function (): void {
    $m = new Mundo;
    $caso = casoComE03Pronto($m);
    $m->fato('apurado', 300000, new stdClass, 'DAS');
    $publicados = $m->eventos->quantos('fato.publicado');

    concluirE03($m);

    $vigente = $m->fatos->vigente('T1', Mundo::COMP, 'apurado', 'DAS') ?? throw new RuntimeException;
    expect($vigente->versao)->toBe(1)
        ->and($m->eventos->quantos('fato.publicado'))->toBe($publicados)
        ->and($m->entregaveis->fatosLigados)->toContain(['entregavelId' => $m->idDe($caso, 'E03'), 'fatoId' => $vigente->id, 'papel' => 'saida']);
});

it('saída nova sobre fato já usado invalida os dependentes mas não o próprio entregável (§8 item 4, §11)', function (): void {
    $m = new Mundo;
    $caso = casoComE03Pronto($m);
    $velho = $m->fato('apurado', 250000, new stdClass, 'DAS');
    $e11 = $m->entregaveis->definirEstado($caso->id, 'E11', EstadoEntregavel::Validado);
    $m->entregaveis->ligarFato($m->idDe($caso, 'E03'), $velho->fatoId, 'saida'); // o E03 também usava a versão velha
    $m->entregaveis->ligarFato($e11->id, $velho->fatoId, 'entrada');
    $m->entregaveis->transicoes = [];

    $r = concluirE03($m);

    expect($m->fatos->vigente('T1', Mundo::COMP, 'apurado', 'DAS')->versao ?? null)->toBe(2)
        ->and($r->estado)->toBe('validado')
        ->and($m->entregaveis->resumoTransicoes())->toContain('E11:validado>invalidado')
        ->and($m->entregaveis->resumoTransicoes())->not->toContain('E03:validado>invalidado')
        // E11 invalidado não cumpre mais a prontidão (falta a guia): volta a aguardando_insumo.
        ->and($m->estado($caso, 'E11'))->toBe('aguardando_insumo');
});

it('observado_em da saída vem do relógio do banco (port Relogio), não do PHP', function (): void {
    $m = new Mundo;
    casoComE03Pronto($m);
    $leiturasAntes = $m->relogio->leituras;

    concluirE03($m);

    $apurado = $m->fatos->vigente('T1', Mundo::COMP, 'apurado', 'DAS') ?? throw new RuntimeException;
    expect($m->fatos->observadoEm[$apurado->id])->toBe('2026-01-15 12:00:00+00')
        ->and($m->relogio->leituras)->toBe($leiturasAntes + 1);
});

it('saída idêntica ao fato vigente não consulta o relógio (nada é gravado)', function (): void {
    $m = new Mundo;
    casoComE03Pronto($m);
    $m->fato('apurado', 300000, new stdClass, 'DAS');
    $leiturasAntes = $m->relogio->leituras;

    concluirE03($m);

    expect($m->relogio->leituras)->toBe($leiturasAntes);
});

it('ordem de locks: titular, depois as chaves dos fatos de saída ordenadas, antes da linha do entregável (§13)', function (): void {
    $m = new Mundo;
    casoComE03Pronto($m);
    $m->casos->travas = [];
    $m->fatos->travas = [];

    concluirE03($m, [new SaidaDaTarefa('IRPJ', 0, new stdClass), new SaidaDaTarefa('DAS', 300000, new stdClass)]);

    expect($m->casos->travas)->toBe(['T1'])
        ->and(array_slice($m->fatos->travas, 0, 2))->toBe(['apurado|DAS', 'apurado|IRPJ']);
});

it('caso inexistente: 404; entregável inexistente: 404', function (): void {
    $m = new Mundo;
    expect(codigoDoErro(fn () => concluirE03($m, null, 'E03', 'NINGUEM')))->toBe('404:nao_encontrado');

    casoComE03Pronto($m);
    expect(codigoDoErro(fn () => concluirE03($m, null, 'E99')))->toBe('404:nao_encontrado');
});

it('entregável fora de pronto, ou de sistema: 409 entregavel_nao_concluivel', function (): void {
    $m = new Mundo;
    $m->abrirCaso(); // E03 aguardando_insumo
    expect(codigoDoErro(fn () => concluirE03($m)))->toBe('409:entregavel_nao_concluivel');

    $m2 = new Mundo;
    $caso = casoComE03Pronto($m2);
    $m2->entregaveis->definirEstado($caso->id, 'E10', EstadoEntregavel::Pronto);
    expect(codigoDoErro(fn () => concluirE03($m2, null, 'E10')))->toBe('409:entregavel_nao_concluivel');
});

it('saídas vazias: 422 saida_invalida, sem gravar nada', function (): void {
    $m = new Mundo;
    casoComE03Pronto($m);
    $fatos = count($m->fatos->versoes);

    expect(codigoDoErro(fn () => $m->concluir->concluir(new ConcluirTarefaComando('T1', Mundo::COMP, 'E03', 'ana', []))))
        ->toBe('422:saida_invalida')
        ->and(count($m->fatos->versoes))->toBe($fatos)
        ->and($m->tarefas->estados())->toBe(['aberta']);
});

// --- RegistrarOverride (§8.1) -------------------------------------------------------------------------------

function casoComE01Divergente(Mundo $m): Caso
{
    $caso = $m->abrirCaso();
    $m->fato('faturamento_mes', 5000000);
    $m->fato('notas_oneflow', 4000000);

    return $caso;
}

function overrideDe(Mundo $m, string $cf = 'CF-01', string $motivo = 'nota fora do período', string $ator = 'bia', string $tipo = 'E01', string $titular = 'T1'): ResultadoEntregavel
{
    return $m->override->registrar(new RegistrarOverrideComando($titular, Mundo::COMP, $tipo, $ator, $cf, $motivo));
}

it('override: grava na conferência vigente, resolve a exceção, emite o evento e valida o entregável', function (): void {
    $m = new Mundo;
    $caso = casoComE01Divergente($m);

    $r = overrideDe($m);

    $ultima = array_values(array_filter($m->entregaveis->transicoes, static fn (array $t): bool => $t['motivo'] === 'override'));
    expect($r->estado)->toBe('validado')
        ->and($m->conferencias->linhas[0]['override'])->toBeTrue()
        ->and($m->excecoes->resumo())->toBe(['CF-01:resolvida:override'])
        ->and($m->eventos->quantos('conferencia.override'))->toBe(1)
        ->and($m->eventos->quantos('insumos.completos'))->toBe(1)
        ->and($ultima[0])->toMatchArray(['tipo' => 'E01', 'de' => 'divergente', 'para' => 'validado', 'ator' => 'bia', 'causadoPorTipo' => 'conferencia'])
        ->and($m->estado($caso, 'E01'))->toBe('validado');
});

it('com outra CF ainda divergente B sem override, o entregável continua divergente', function (): void {
    $m = new Mundo;
    $caso = casoComE01Divergente($m);
    $e01 = $m->idDe($caso, 'E01');
    $m->conferencias->gravarSeNova('CF-08', $caso->id, $e01, 'r', [], 'h-cf08', 'divergente', null, null, [], 'B', 'S');

    $r = overrideDe($m);

    expect($r->estado)->toBe('divergente')
        ->and($m->eventos->quantos('insumos.completos'))->toBe(0)
        ->and($m->conferencias->cfsDivergentesBloqueantes($e01))->toBe(['CF-08']);
});

it('422 antes de qualquer acesso: cf fora do padrão, motivo curto, ator vazio', function (): void {
    $m = new Mundo;
    casoComE01Divergente($m);
    $m->casos->travas = [];

    expect(codigoDoErro(fn () => overrideDe($m, 'CF1')))->toBe('422:corpo_invalido')
        ->and(codigoDoErro(fn () => overrideDe($m, 'CF-01', 'abcd')))->toBe('422:corpo_invalido') // mínimo 5 caracteres
        ->and(codigoDoErro(fn () => overrideDe($m, 'CF-01', 'motivo válido', '')))->toBe('422:corpo_invalido')
        ->and($m->casos->travas)->toBe([]);
});

it('404 para caso ou entregável inexistente; 409 se não está divergente ou a CF não é divergência B sem override', function (): void {
    $m = new Mundo;
    casoComE01Divergente($m);

    expect(codigoDoErro(fn () => overrideDe($m, titular: 'NINGUEM')))->toBe('404:nao_encontrado')
        ->and(codigoDoErro(fn () => overrideDe($m, tipo: 'E99')))->toBe('404:nao_encontrado')
        ->and(codigoDoErro(fn () => overrideDe($m, tipo: 'E03')))->toBe('409:entregavel_nao_divergente')
        ->and(codigoDoErro(fn () => overrideDe($m, 'CF-09')))->toBe('409:conferencia_sem_divergencia_b');

    overrideDe($m); // primeiro override vale; o entregável vai a validado
    expect(codigoDoErro(fn () => overrideDe($m)))->toBe('409:entregavel_nao_divergente');
});
