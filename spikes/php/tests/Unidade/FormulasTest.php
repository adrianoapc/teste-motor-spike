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

it('CF-07 SN devolve fato ausente com parcelas ausentes, não-lista ou entradas malformadas', function (string $json): void {
    $f = new FatosDoCaso([
        fatoF('a', 'apurado', 'DAS', 0),
        fatoF('d', 'divisao_socios', '', null, payloadJson($json)),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'DAS', 'SN');
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

it('CF-07 SN soma todas as parcelas sem olhar tributo', function (): void {
    $f = new FatosDoCaso([
        fatoF('a', 'apurado', 'DAS', 8),
        fatoF('d', 'divisao_socios', '', null, payloadJson('{"parcelas": [{"valor_centavos": 5}, {"valor_centavos": 3}]}')),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'DAS', 'SN');
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

it('CF-09 compara diferenças acima de int64 sem empate falso', function (): void {
    // INSS: |MAX - (-1)| = 2^63; FGTS: |MAX - (-2)| = 2^63 + 1 (maior). Antes o float igualava as duas.
    // IRRF (folha M-1): 0 vs guia 0 => dif 0, não interfere.
    $folhaAnterior = fatoF('fa', 'folha', '', null, payloadJson('{"irrf_centavos": 0}'));
    $f = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 9223372036854775807, "fgts_centavos": 9223372036854775807}')),
        fatoF('gi', 'guia', 'INSS', -1), fatoF('gf', 'guia', 'FGTS', -2), fatoF('gr', 'guia', 'IRRF', 0),
    ], $folhaAnterior);
    $r = (new Formulas(5000))->cf09($f);
    expect($r->fatoAusente)->toBeNull()->and($r->obtidoCentavos)->toBe(-2);

    $g = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 9223372036854775806, "fgts_centavos": 9223372036854775807}')),
        fatoF('gi', 'guia', 'INSS', 0), fatoF('gf', 'guia', 'FGTS', 0), fatoF('gr', 'guia', 'IRRF', 0),
    ], $folhaAnterior);
    $r = (new Formulas(5000))->cf09($g);
    expect($r->fatoAusente)->toBeNull()->and($r->esperadoCentavos)->toBe(PHP_INT_MAX)->and($r->obtidoCentavos)->toBe(0);
});

it('CF-09 confere três pares e grava o par de maior diferença (v4, IRRF da folha M-1)', function (): void {
    // INSS: 1000 vs 1000 (dif 0); FGTS: 500 vs 480 (dif 20); IRRF: 300 vs 250 (dif 50) <- maior
    $folhaAnterior = fatoF('fa', 'folha', '', null, payloadJson('{"irrf_centavos": 300}'));
    $f = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 1000, "fgts_centavos": 500}')),
        fatoF('gi', 'guia', 'INSS', 1000), fatoF('gf', 'guia', 'FGTS', 480), fatoF('gr', 'guia', 'IRRF', 250),
    ], $folhaAnterior);
    $r = (new Formulas(5000))->cf09($f);
    expect($r->fatoAusente)->toBeNull()
        ->and($r->esperadoCentavos)->toBe(300)   // IRRF
        ->and($r->obtidoCentavos)->toBe(250)
        ->and($r->fatosUsados)->toHaveCount(5);   // folha, folha M-1, 3 guias
});

it('CF-09 empate entre três pares desempata INSS > FGTS > IRRF', function (): void {
    // Todas as diferenças = 10. Empate → INSS primeiro.
    $folhaAnterior = fatoF('fa', 'folha', '', null, payloadJson('{"irrf_centavos": 310}'));
    $f = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 1010, "fgts_centavos": 510}')),
        fatoF('gi', 'guia', 'INSS', 1000), fatoF('gf', 'guia', 'FGTS', 500), fatoF('gr', 'guia', 'IRRF', 300),
    ], $folhaAnterior);
    $r = (new Formulas(5000))->cf09($f);
    expect($r->fatoAusente)->toBeNull()
        ->and($r->esperadoCentavos)->toBe(1010)   // INSS
        ->and($r->obtidoCentavos)->toBe(1000);
});

it('CF-09 sem folha do mês anterior é dado ausente (classe S)', function (): void {
    $f = new FatosDoCaso([
        fatoF('f', 'folha', '', null, payloadJson('{"inss_centavos": 1000, "fgts_centavos": 500}')),
        fatoF('gi', 'guia', 'INSS', 1000), fatoF('gf', 'guia', 'FGTS', 500), fatoF('gr', 'guia', 'IRRF', 300),
    ], null); // sem folha M-1
    $r = (new Formulas(5000))->cf09($f);
    expect($r->fatoAusente)->toContain('folha[-1]');
});

it('CF-07 LP confere tributo a tributo e grava o par de maior diferença', function (): void {
    // IRPJ: apurado 160000, parcelas 96000+64000=160000 (dif 0)
    // CSLL: apurado 144000, parcelas 86400+57600=144000 (dif 0)
    // PIS:  apurado 13000, parcelas 7780+5181=12961 (dif 39)  <- maior
    // COFINS: apurado 60000, parcelas 30000+30000=60000 (dif 0)
    $f = new FatosDoCaso([
        fatoF('ir', 'apurado', 'IRPJ', 160000),
        fatoF('cs', 'apurado', 'CSLL', 144000),
        fatoF('pi', 'apurado', 'PIS', 13000),
        fatoF('co', 'apurado', 'COFINS', 60000),
        fatoF('d', 'divisao_socios', '', null, payloadJson(
            '{"parcelas": ['.
            '{"tributo": "IRPJ", "valor_centavos": 96000}, {"tributo": "IRPJ", "valor_centavos": 64000},'.
            '{"tributo": "CSLL", "valor_centavos": 86400}, {"tributo": "CSLL", "valor_centavos": 57600},'.
            '{"tributo": "PIS", "valor_centavos": 7780}, {"tributo": "PIS", "valor_centavos": 5181},'.
            '{"tributo": "COFINS", "valor_centavos": 30000}, {"tributo": "COFINS", "valor_centavos": 30000}'.
            ']}'
        )),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'IRPJ', 'LP');
    expect($r->fatoAusente)->toBeNull()
        ->and($r->esperadoCentavos)->toBe(13000)   // PIS, maior diferença
        ->and($r->obtidoCentavos)->toBe(12961)
        ->and($r->fatosUsados)->toHaveCount(5);     // 4 apurado + divisao_socios
});

it('CF-07 LP desempata pelo menor tributo em ordem de code point', function (): void {
    // CSLL e IRPJ têm a MESMA diferença (100). Empate → menor por code point: CSLL < IRPJ.
    $f = new FatosDoCaso([
        fatoF('ir', 'apurado', 'IRPJ', 1000),
        fatoF('cs', 'apurado', 'CSLL', 2000),
        fatoF('d', 'divisao_socios', '', null, payloadJson(
            '{"parcelas": [{"tributo": "IRPJ", "valor_centavos": 900}, {"tributo": "CSLL", "valor_centavos": 1900}]}'
        )),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'IRPJ', 'LP');
    expect($r->fatoAusente)->toBeNull()
        ->and($r->esperadoCentavos)->toBe(2000)   // CSLL
        ->and($r->obtidoCentavos)->toBe(1900);
});

it('CF-07 LP: parcela sem tributo é operando inválido (classe S)', function (): void {
    $f = new FatosDoCaso([
        fatoF('ir', 'apurado', 'IRPJ', 100000),
        fatoF('d', 'divisao_socios', '', null, payloadJson(
            '{"parcelas": [{"tributo": "IRPJ", "valor_centavos": 100000}, {"valor_centavos": 60000}]}'
        )),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'IRPJ', 'LP');
    expect($r->fatoAusente)->not->toBeNull();
});

it('CF-07 LP: tributo nas parcelas sem apurado no caso é dado ausente (classe S)', function (): void {
    // ISS aparece nas parcelas mas não há apurado[ISS] no caso.
    $f = new FatosDoCaso([
        fatoF('ir', 'apurado', 'IRPJ', 160000),
        fatoF('d', 'divisao_socios', '', null, payloadJson(
            '{"parcelas": [{"tributo": "IRPJ", "valor_centavos": 160000}, {"tributo": "ISS", "valor_centavos": 5000}]}'
        )),
    ]);
    $r = (new Formulas(5000))->cf07($f, 'IRPJ', 'LP');
    expect($r->fatoAusente)->not->toBeNull()
        ->and($r->fatoAusente)->toContain('apurado[ISS]');
});
