<?php

declare(strict_types=1);

use App\Dominio\Conferencias\HashConferencia;
use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\HashCanonico;
use App\Dominio\Fechamento\JsonCru;
use App\Dominio\Fechamento\NumeroJson;

/**
 * Decodifica o payload como objeto (nunca $assoc = true), para preservar a
 * diferença entre {} e [] — é assim que a API lerá o corpo cru (§6, design).
 */
function payloadDe(string $json): mixed
{
    return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
}

it('produz os hashes dos vetores obrigatórios do fato (Requisito 4.2)', function (string $payloadJson, ?int $valor, string $esperado): void {
    expect(HashFato::hash(payloadDe($payloadJson), $valor))->toBe($esperado);
})->with([
    'vetor 1: payload {} e valor' => [
        '{}', 10000000,
        '1e949949f87138fe966f349922ebff0167b25b1ad5fdc5f7432f7252355faa3d',
    ],
    'vetor 2: aliquota_bp, valor null' => [
        '{"aliquota_bp":600}', null,
        '5bafcc08855a4f461c99d302521e44641944b690fc4ab93f56375599289c1449',
    ],
    'vetor 3: parcelas, valor null' => [
        '{"parcelas":[{"socio":"S1","valor_centavos":360000},{"socio":"S2","valor_centavos":240000}]}', null,
        'b405b94732752a986eb16f76b252ffb975b339e8fbeb56b6012cfac2d9f192ad',
    ],
    'vetor 4: chaves fora de ordem e unicode' => [
        '{"descrição":"ção","b":1,"a":{"z":1,"y":2}}', 5,
        'a8b6cf1558cc659b04237ac8295a0f3556b60efb6b06a9b7be922431c745b0b0',
    ],
]);

it('preserva a diferença entre {} e [] (vetor 1 vs lista vazia)', function (): void {
    $comObjeto = HashFato::hash(payloadDe('{}'), 10000000);
    $comLista = HashFato::hash(payloadDe('[]'), 10000000);

    expect($comObjeto)->toBe('1e949949f87138fe966f349922ebff0167b25b1ad5fdc5f7432f7252355faa3d')
        ->and($comLista)->not->toBe($comObjeto);
});

it('não escapa barras nem unicode no JSON canônico', function (): void {
    $obj = payloadDe('{"a":"ç/b"}');
    expect(HashCanonico::codificar($obj))->toBe('{"a":"ç/b"}');
});

it('ordena as chaves recursivamente', function (): void {
    $obj = payloadDe('{"b":1,"a":{"z":1,"y":2}}');
    expect(HashCanonico::codificar($obj))->toBe('{"a":{"y":2,"z":1},"b":1}');
});

it('produz o entrada_hash da conferência (Requisito 7.4)', function (): void {
    $hash = HashConferencia::entradaHash(
        'CF-01',
        [
            ['fato_id' => '00000000-0000-0000-0000-000000000001', 'versao' => 1],
            ['fato_id' => '00000000-0000-0000-0000-000000000002', 'versao' => 1],
        ],
        '00000000-0000-0000-0000-0000000000aa',
    );

    expect($hash)->toBe('4e0ad4ed509cc1fa2e908b6ef9253d5927588b01a9001b8195c7185e75c878ca');
});

it('ordena fatos_usados por fato_id no entrada_hash', function (): void {
    $ordenado = HashConferencia::entradaHash('CF-01', [
        ['fato_id' => '00000000-0000-0000-0000-000000000001', 'versao' => 1],
        ['fato_id' => '00000000-0000-0000-0000-000000000002', 'versao' => 1],
    ], '00000000-0000-0000-0000-0000000000aa');

    $desordenado = HashConferencia::entradaHash('CF-01', [
        ['fato_id' => '00000000-0000-0000-0000-000000000002', 'versao' => 1],
        ['fato_id' => '00000000-0000-0000-0000-000000000001', 'versao' => 1],
    ], '00000000-0000-0000-0000-0000000000aa');

    expect($desordenado)->toBe($ordenado);
});

it('não escapa U+2028/U+2029 no JSON canônico (UTF-8 literal)', function (): void {
    $json = HashCanonico::codificar((object) ['a' => "x\u{2028}y\u{2029}z"]);
    expect($json)->toBe("{\"a\":\"x\u{2028}y\u{2029}z\"}")
        ->and($json)->not->toContain('\\u2028')
        ->and(bin2hex($json))->toContain('e280a8')->toContain('e280a9');
});

it('JsonCru preserva números não inteiros e inteiros além de int64, sem float', function (): void {
    $json = '{"b":0.1234567890123456789,"a":[1,1.0,1.50,1e2,-0,9223372036854775807,9223372036854775808,-9223372036854775808,123456789012345678901234567890]}';
    $v = JsonCru::decodificar($json);
    /** @var list<mixed> $a */
    $a = ((array) $v)['a'];
    expect(JsonCru::codificar($v, false))->toBe($json)
        ->and(JsonCru::codificar($v, true))->toBe('{"a":[1,1.0,1.50,1e2,-0,9223372036854775807,9223372036854775808,-9223372036854775808,123456789012345678901234567890],"b":0.1234567890123456789}')
        ->and($a[0])->toBe(1)
        ->and($a[5])->toBe(PHP_INT_MAX)
        ->and($a[7])->toBe(PHP_INT_MIN)
        ->and($a[1])->toBeInstanceOf(NumeroJson::class)
        ->and($a[6])->toBeInstanceOf(NumeroJson::class);
});

it('o hash do fato muda quando muda o dígito não representável em float', function (): void {
    $a = JsonCru::decodificar('{"x":0.1234567890123456789}');
    $b = JsonCru::decodificar('{"x":0.12345678901234568}');
    expect(HashFato::hash($a, null))->not->toBe(HashFato::hash($b, null));
});

it('JsonCru preserva {} vs [], aceita unicode/escapes e recusa JSON inválido', function (): void {
    $v = JsonCru::decodificar('{"o":{},"l":[],"s":"a\\u00e9\\n\u2028"}');
    expect(JsonCru::codificar($v, true))->toBe("{\"l\":[],\"o\":{},\"s\":\"aé\\n\u{2028}\"}");
    foreach (['', '{', '{"a":}', '[1,]', '01', '1.', '{"a":1} x', "{'a':1}", '{"a":1,}', '+1'] as $ruim) {
        expect(fn () => JsonCru::decodificar($ruim))->toThrow(JsonException::class);
    }
});
