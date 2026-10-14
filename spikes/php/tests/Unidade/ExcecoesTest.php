<?php

declare(strict_types=1);

// Abertura única de exceções (§9.4) sem banco.

use Tests\Unidade\Fakes\Mundo;

it('abre a exceção e publica excecao.aberta com o id', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $e01 = $m->idDe($caso, 'E01');

    $id = $m->abertura->abrirUnica($caso->id, $e01, 'CF-01', 'O', 'C-01');

    expect($id)->toBe('exc-1')
        ->and($m->excecoes->resumo())->toBe(['CF-01:aberta'])
        ->and($m->excecoes->excecoes['exc-1']['dono'])->toBe('C-01')
        ->and($m->eventos->eventos[1])->toBe([
            'nome' => 'excecao.aberta', 'casoId' => $caso->id, 'entregavelId' => $e01, 'payload' => ['excecao_id' => 'exc-1'],
        ]);
});

it('não abre outra do mesmo tipo para o mesmo entregável enquanto houver uma aberta', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $e01 = $m->idDe($caso, 'E01');

    $primeira = $m->abertura->abrirUnica($caso->id, $e01, 'CF-01', 'O', 'C-01');
    $segunda = $m->abertura->abrirUnica($caso->id, $e01, 'CF-01', 'S', 'C-01');

    expect($primeira)->not->toBeNull()
        ->and($segunda)->toBeNull()
        ->and($m->excecoes->excecoes)->toHaveCount(1)
        ->and($m->eventos->quantos('excecao.aberta'))->toBe(1);
});

it('tipos diferentes e entregáveis diferentes têm cada um a sua exceção', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $e01 = $m->idDe($caso, 'E01');
    $e03 = $m->idDe($caso, 'E03');

    $m->abertura->abrirUnica($caso->id, $e01, 'CF-01', 'O', 'C-01');
    $m->abertura->abrirUnica($caso->id, $e01, 'CF-08', 'O', 'C-01');
    $m->abertura->abrirUnica($caso->id, $e03, 'CF-01', 'O', 'C-01');

    expect($m->excecoes->resumo())->toBe(['CF-01:aberta', 'CF-08:aberta', 'CF-01:aberta']);
});

it('exceção de caso (sem entregável) é única por caso e tipo, independente das de entregável', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso('T1', '202601');
    $outro = $m->abrirCaso('T1', '202602');
    $e01 = $m->idDe($caso, 'E01');

    expect($m->abertura->abrirUnica($caso->id, null, 'competencia_encerrada', 'O', 'C-01'))->not->toBeNull()
        ->and($m->abertura->abrirUnica($caso->id, null, 'competencia_encerrada', 'O', 'C-01'))->toBeNull()
        ->and($m->abertura->abrirUnica($outro->id, null, 'competencia_encerrada', 'O', 'C-01'))->not->toBeNull()
        ->and($m->abertura->abrirUnica($caso->id, $e01, 'competencia_encerrada', 'O', 'C-01'))->not->toBeNull()
        ->and($m->excecoes->excecoes)->toHaveCount(3);
});

it('depois de resolvida, uma nova exceção do mesmo tipo pode ser aberta', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $e11 = $m->idDe($caso, 'E11');

    $m->abertura->abrirUnica($caso->id, $e11, 'entrega_falhou', 'O', 'C-01');
    $m->excecoes->resolverPorTipo($e11, 'entrega_falhou', 'reenviado');
    $reaberta = $m->abertura->abrirUnica($caso->id, $e11, 'entrega_falhou', 'O', 'C-01');

    expect($reaberta)->toBe('exc-2')
        ->and($m->excecoes->resumo())->toBe(['entrega_falhou:resolvida:reenviado', 'entrega_falhou:aberta']);
});
