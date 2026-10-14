<?php

declare(strict_types=1);

// Conferidor (§9) sem banco: classificação por tolerância, fato ausente, comparação de texto e idempotência.

use App\Dominio\Fechamento\Caso;
use App\Dominio\Regras\RegraInvalida;
use Tests\Unidade\Fakes\Mundo;

/**
 * Abre o caso, publica os fatos "no banco" (sem disparar reavaliação) e confere o E01.
 *
 * @param  list<array{string, ?int}>  $fatos  [tipo, valor] (tributo '', payload {})
 * @return array{bool, Mundo, Caso}
 */
function conferirE01(array $fatos): array
{
    $m = new Mundo;
    $caso = $m->abrirCaso();
    foreach ($fatos as $i => [$tipo, $valor]) {
        $m->fatos->inserir('T1', Mundo::COMP, $tipo, '', 1, $valor, new stdClass, 'teste', null, "h{$i}", null, '');
    }

    return [$m->conferir($caso, 'E01'), $m, $caso];
}

it('diferença dentro de ok_ate: ok, severidade do catálogo, devolve false', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', 5000000], ['notas_oneflow', 5000000]]);

    expect($divergiu)->toBeFalse()
        ->and($m->conferencias->linhas)->toHaveCount(1)
        ->and($m->conferencias->linhas[0])->toMatchArray([
            'cf' => 'CF-01', 'resultado' => 'ok', 'severidade' => 'B', 'classe' => 'O',
            'esperado' => 5000000, 'obtido' => 5000000, 'diferenca' => ['centavos' => 0],
        ]);
});

it('diferença na faixa de alerta: divergente com severidade A (não bloqueia), devolve false', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', 5000000], ['notas_oneflow', 5000050]]);

    expect($divergiu)->toBeFalse()
        ->and($m->conferencias->linhas[0])->toMatchArray(['resultado' => 'divergente', 'severidade' => 'A', 'diferenca' => ['centavos' => 50]]);
});

it('diferença acima do alerta: divergente com a severidade e a classe do catálogo, devolve true', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', 5000000], ['notas_oneflow', 4999000]]);

    expect($divergiu)->toBeTrue()
        ->and($m->conferencias->linhas[0])->toMatchArray(['resultado' => 'divergente', 'severidade' => 'B', 'classe' => 'O', 'diferenca' => ['centavos' => 1000]]);
});

it('fato ausente é divergente de classe S (dado faltando), com o motivo, e bloqueia (§9.2)', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', 5000000]]);

    expect($divergiu)->toBeTrue()
        ->and($m->conferencias->linhas[0])->toMatchArray([
            'resultado' => 'divergente', 'severidade' => 'B', 'classe' => 'S', 'esperado' => null, 'obtido' => null,
            'diferenca' => ['erro' => 'fato ausente: notas_oneflow[]'],
        ]);
});

it('operando com valor nulo nunca vira zero: é dado ausente (classe S)', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', null], ['notas_oneflow', 100]]);

    expect($divergiu)->toBeTrue()
        ->and($m->conferencias->linhas[0]['classe'])->toBe('S')
        ->and($m->conferencias->linhas[0]['diferenca'])->toBe(['erro' => 'fato ausente: faturamento_mes.valor']);
});

it('diferença acima de int64 é divergência numérica (classe do catálogo), com a diferença exata em texto', function (): void {
    [$divergiu, $m] = conferirE01([['faturamento_mes', PHP_INT_MAX], ['notas_oneflow', PHP_INT_MIN]]);

    expect($divergiu)->toBeTrue()
        ->and($m->conferencias->linhas[0])->toMatchArray([
            'resultado' => 'divergente', 'severidade' => 'B', 'classe' => 'O',
            'diferenca' => ['centavos' => '18446744073709551615'],
        ]);
});

it('conferir de novo com os mesmos fatos não grava outra linha (idempotente pelo entrada_hash)', function (): void {
    [, $m, $caso] = conferirE01([['faturamento_mes', 5000000], ['notas_oneflow', 4000000]]);

    $m->conferir($caso, 'E01');

    expect($m->conferencias->linhas)->toHaveCount(1);
});

it('uma nova versão de fato muda o entrada_hash: grava outra conferência e a vigente passa a ser ela', function (): void {
    [, $m, $caso] = conferirE01([['faturamento_mes', 5000000], ['notas_oneflow', 4000000]]);
    $m->fatos->inserir('T1', Mundo::COMP, 'notas_oneflow', '', 2, 5000000, new stdClass, 'teste', null, 'h-v2', null, '');

    $divergiu = $m->conferir($caso, 'E01');

    expect($m->conferencias->linhas)->toHaveCount(2)
        ->and($divergiu)->toBeFalse() // a vigente (a última) é ok
        ->and($m->conferencias->vigente($m->idDe($caso, 'E01'), 'CF-01')?->resultado)->toBe('ok');
});

it('só roda as conferências cujo quando casa com o contexto do caso', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso(regime: 'LP');

    // CF-04 é só do Simples: no Lucro Presumido não grava nada e não bloqueia.
    expect($m->conferir($caso, 'E03', 'IRPJ'))->toBeFalse()
        ->and($m->conferencias->linhas)->toBe([]);
});

it('CF-05, CF-06 e CF-12: valores em centavos ou existência/texto sem centavos, todos ok', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fatos->inserir('T1', Mundo::COMP, 'guia', 'DAS', 1, 100, (object) ['competencia_impressa' => '202601'], 'teste', null, 'hg', null, '');
    $m->fatos->inserir('T1', Mundo::COMP, 'apurado', 'DAS', 1, 100, new stdClass, 'teste', null, 'ha', null, '');
    $m->fatos->inserir('T1', Mundo::COMP, 'recibo_obrigacao', 'PGDAS-D', 1, null, new stdClass, 'teste', null, 'hr', null, '');

    $m->conferir($caso, 'E11');
    $m->conferir($caso, 'E10');

    $porCf = [];
    foreach ($m->conferencias->linhas as $l) {
        $porCf[$l['cf']] = $l['resultado'].'/'.var_export($l['esperado'], true);
    }
    expect($porCf)->toBe(['CF-05' => 'ok/100', 'CF-06' => 'ok/NULL', 'CF-12' => 'ok/NULL']);
});

it('CF-06 com a competência impressa diferente da do caso diverge e guarda esperada e impressa', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();
    $m->fatos->inserir('T1', Mundo::COMP, 'guia', 'DAS', 1, 100, (object) ['competencia_impressa' => '202512'], 'teste', null, 'hg', null, '');

    $divergiu = $m->conferir($caso, 'E11');

    $cf06 = array_values(array_filter($m->conferencias->linhas, static fn (array $l): bool => $l['cf'] === 'CF-06'));
    expect($divergiu)->toBeTrue()
        ->and($cf06[0]['resultado'])->toBe('divergente')
        ->and($cf06[0]['diferenca'])->toBe(['esperada' => '202601', 'impressa' => '202512']);
});

it('CF-12 exige o recibo do tributo declarado na entrada do E10 da regra do caso: SN PGDAS-D, LP DCTFWEB', function (string $regime, string $tributoGuia, string $tributoDoRecibo): void {
    $m = new Mundo;
    $caso = $m->abrirCaso(regime: $regime);
    $m->fatos->inserir('T1', Mundo::COMP, 'recibo_obrigacao', $tributoDoRecibo, 1, null, new stdClass, 'teste', null, 'hr', null, '');

    expect($m->conferir($caso, 'E10', $tributoGuia))->toBeFalse()
        ->and($m->conferencias->linhas[0])->toMatchArray(['cf' => 'CF-12', 'resultado' => 'ok']);
})->with([
    'Simples' => ['SN', 'DAS', 'PGDAS-D'],
    'Lucro Presumido' => ['LP', 'IRPJ', 'DCTFWEB'],
]);

it('CF-12 não repete conhecimento de domínio: o tributo vem da regra, seja qual for', function (): void {
    $regra = Mundo::regraPadrao();
    foreach ($regra['entregaveis'] as $i => $item) {
        if ($item['tipo'] === 'E10') {
            $regra['entregaveis'][$i]['entradas'] = [['tipo' => 'recibo_obrigacao', 'tributo' => 'ECF']];
        }
    }
    $m = new Mundo($regra);
    $caso = $m->abrirCaso();
    $m->fatos->inserir('T1', Mundo::COMP, 'recibo_obrigacao', 'PGDAS-D', 1, null, new stdClass, 'teste', null, 'h1', null, '');

    // Só há recibo de PGDAS-D, mas a regra exige ECF: divergente, dado ausente.
    expect($m->conferir($caso, 'E10'))->toBeTrue()
        ->and($m->conferencias->linhas[0]['diferenca'])->toBe(['erro' => 'fato ausente: recibo_obrigacao[ECF]']);
});

it('E10 sem entrada de recibo aplicável ao contexto é regra inválida, não um padrão silencioso', function (): void {
    $regra = Mundo::regraPadrao();
    foreach ($regra['entregaveis'] as $i => $item) {
        if ($item['tipo'] === 'E10') {
            $regra['entregaveis'][$i]['entradas'] = [['tipo' => 'recibo_obrigacao', 'tributo' => 'DCTFWEB', 'quando' => [['regime' => 'LP']]]];
        }
    }
    $m = new Mundo($regra);
    $caso = $m->abrirCaso(regime: 'SN');

    expect(fn () => $m->conferir($caso, 'E10'))->toThrow(RegraInvalida::class, 'CF-12');
});

it('CF-12 sem recibo é divergente de classe S (dado ausente)', function (): void {
    $m = new Mundo;
    $caso = $m->abrirCaso();

    expect($m->conferir($caso, 'E10'))->toBeTrue()
        ->and($m->conferencias->linhas[0])->toMatchArray(['cf' => 'CF-12', 'resultado' => 'divergente', 'classe' => 'S']);
});
