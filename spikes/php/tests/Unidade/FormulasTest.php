<?php

declare(strict_types=1);

use App\Aplicacao\Conferencias\FatosDoCaso;
use App\Aplicacao\Conferencias\Formulas;
use App\Dominio\Fatos\Fato;

function fatoF(string $id, string $tipo, string $tributo, ?int $valor, mixed $payload = null): Fato
{
    return new Fato($id, 'T', '202609', $tipo, $tributo, 1, $valor, $payload ?? new stdClass, 'h');
}

function payloadJson(string $json): mixed
{
    return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
}

it('CF-07 devolve fato ausente com parcelas ausentes, não-lista ou entradas malformadas', function (string $json): void {
    $f = new FatosDoCaso([
        fatoF('a', 'apurado', 'DAS', 0),
        fatoF('d', 'divisao_socios', '', null, payloadJson($json)),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'DAS');
    expect($r->fatoAusente)->not->toBeNull()
        ->and($r->fatosUsados)->toHaveCount(2);
})->with([
    'sem parcelas' => ['{}'],
    'parcelas objeto' => ['{"parcelas": {"a": 1}}'],
    'parcelas escalar' => ['{"parcelas": 3}'],
    'entrada escalar' => ['{"parcelas": [1]}'],
    'sem valor' => ['{"parcelas": [{"x": 1}]}'],
    'valor string' => ['{"parcelas": [{"valor_centavos": "5"}]}'],
    'uma boa e uma ruim' => ['{"parcelas": [{"valor_centavos": 5}, {"valor_centavos": null}]}'],
]);

it('CF-07 soma parcelas válidas', function (): void {
    $f = new FatosDoCaso([
        fatoF('a', 'apurado', 'DAS', 8),
        fatoF('d', 'divisao_socios', '', null, payloadJson('{"parcelas": [{"valor_centavos": 5}, {"valor_centavos": 3}]}')),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'DAS');
    expect($r->fatoAusente)->toBeNull()->and($r->obtidoCentavos)->toBe(8)->and($r->esperadoCentavos)->toBe(8);
});

it('CF-06 sem competencia_impressa string é fato ausente (classe S), não texto vazio', function (string $json): void {
    $f = new FatosDoCaso([fatoF('g', 'guia', 'DAS', 1, payloadJson($json))]);
    $r = (new Formulas(5000))->cf06($f, 'DAS', '202609');
    expect($r->fatoAusente)->not->toBeNull()->and($r->comparacaoTexto)->toBeFalse()->and($r->fatosUsados)->toHaveCount(1);
})->with(['ausente' => ['{}'], 'numero' => ['{"competencia_impressa": 202609}'], 'nulo' => ['{"competencia_impressa": null}']]);

it('CF-06 compara quando o campo é texto', function (): void {
    $f = new FatosDoCaso([fatoF('g', 'guia', 'DAS', 1, payloadJson('{"competencia_impressa": "202608"}'))]);
    $r = (new Formulas(5000))->cf06($f, 'DAS', '202609');
    expect($r->fatoAusente)->toBeNull()->and($r->textoIgual)->toBeFalse();
});

it('CF-04 e CF-08 não estouram com valores int64 grandes', function (): void {
    $f = new FatosDoCaso([
        fatoF('f', 'faturamento_mes', '', PHP_INT_MAX),
        fatoF('al', 'aliquota_mes', '', null, payloadJson('{"aliquota_bp": 600}')),
        fatoF('d', 'apurado', 'DAS', 1),
        fatoF('p', 'prolabore', '', 1),
    ]);
    $formulas = new Formulas(2000);
    expect($formulas->cf04($f)->esperadoCentavos)->toBe(553402322211286548)
        ->and($formulas->cf08($f)->esperadoCentavos)->toBe(1844674407370955161);

    // Resultado que não cabe em int64 vira fato ausente (classe S), nunca TypeError.
    $g = new FatosDoCaso([
        fatoF('f', 'faturamento_mes', '', PHP_INT_MAX),
        fatoF('al', 'aliquota_mes', '', null, payloadJson('{"aliquota_bp": 20000}')),
        fatoF('d', 'apurado', 'DAS', 1),
    ]);
    expect($formulas->cf04($g)->fatoAusente)->not->toBeNull();
});

it('CF-09 com diferença fora de int64 vira fato ausente e diferenças adjacentes não empatam', function (): void {
    $folha = fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 9223372036854775807, "fgts_centavos": 9223372036854775806}'));
    $f = new FatosDoCaso([$folha, fatoF('gi', 'guia', 'INSS', -1), fatoF('gf', 'guia', 'FGTS', -1)]);
    expect((new Formulas(5000))->cf09($f)->fatoAusente)->not->toBeNull();

    // Diferenças que cabem: INSS = MAX-1 vs FGTS = MAX (adjacentes) escolhe FGTS, sem float.
    $g = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 9223372036854775806, "fgts_centavos": 9223372036854775807}')),
        fatoF('gi', 'guia', 'INSS', 0), fatoF('gf', 'guia', 'FGTS', 0),
    ]);
    $r = (new Formulas(5000))->cf09($g);
    expect($r->fatoAusente)->toBeNull()->and($r->esperadoCentavos)->toBe(PHP_INT_MAX)->and($r->obtidoCentavos)->toBe(0);
});
