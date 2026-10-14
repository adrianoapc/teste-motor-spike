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
        1,
    );

    expect($hash)->toBe('4e0ad4ed509cc1fa2e908b6ef9253d5927588b01a9001b8195c7185e75c878ca');
});

it('ordena fatos_usados por fato_id no entrada_hash', function (): void {
    $ordenado = HashConferencia::entradaHash('CF-01', [
        ['fato_id' => '00000000-0000-0000-0000-000000000001', 'versao' => 1],
        ['fato_id' => '00000000-0000-0000-0000-000000000002', 'versao' => 1],
    ], '00000000-0000-0000-0000-0000000000aa', 2);

    $desordenado = HashConferencia::entradaHash('CF-01', [
        ['fato_id' => '00000000-0000-0000-0000-000000000002', 'versao' => 1],
        ['fato_id' => '00000000-0000-0000-0000-000000000001', 'versao' => 1],
    ], '00000000-0000-0000-0000-0000000000aa', 2);

    expect($desordenado)->toBe($ordenado);
});

it('não escapa U+2028/U+2029 no JSON canônico (UTF-8 literal)', function (): void {
    $json = HashCanonico::codificar((object) ['a' => "x\u{2028}y\u{2029}z"]);
    expect($json)->toBe("{\"a\":\"x\u{2028}y\u{2029}z\"}")
        ->and($json)->not->toContain('\\u2028')
        ->and(bin2hex($json))->toContain('e280a8')->toContain('e280a9');
});

it('JsonCru decodifica sem float: int64 nativo, o resto vira NumeroJson e é rejeitado por somenteInteiros', function (): void {
    $v = JsonCru::decodificar('{"a":[1,1.0,1e2,-0,9223372036854775807,9223372036854775808,-9223372036854775808]}');
    /** @var list<mixed> $a */
    $a = ((array) $v)['a'];
    expect($a[0])->toBe(1)
        ->and($a[4])->toBe(PHP_INT_MAX)
        ->and($a[6])->toBe(PHP_INT_MIN)
        ->and($a[1])->toBeInstanceOf(NumeroJson::class)
        ->and($a[5])->toBeInstanceOf(NumeroJson::class)
        ->and(JsonCru::somenteInteiros($v))->toBeFalse()
        ->and(JsonCru::somenteInteiros(JsonCru::decodificar('{"a":[1,{"b":-5}],"c":"1.5","d":null,"e":true}')))->toBeTrue();
});

it('JsonCru preserva {} vs [], aceita unicode/escapes e recusa JSON inválido', function (): void {
    $v = JsonCru::decodificar('{"o":{},"l":[],"s":"a\\u00e9\\n\u2028"}');
    expect(JsonCru::codificar($v, true))->toBe("{\"l\":[],\"o\":{},\"s\":\"aé\\n\u{2028}\"}");
    foreach (['', '{', '{"a":}', '[1,]', '01', '1.', '{"a":1} x', "{'a':1}", '{"a":1,}', '+1'] as $ruim) {
        expect(fn () => JsonCru::decodificar($ruim))->toThrow(JsonException::class);
    }
});

/*
 * Vetores compartilhados do entrada_hash (semântica §9.2). Cópia de contrato/vetores-entrada-hash.json de
 * origin/docs/metricas-pos-merge (git show origin/docs/metricas-pos-merge:contrato/vetores-entrada-hash.json):
 * tests/fixtures/vetores-entrada-hash.json.
 */
it('entrada_hash bate com os 9 vetores compartilhados (canônico e sha256), com fatos fora de ordem e semantica 1/2', function (): void {
    $json = file_get_contents(__DIR__.'/../fixtures/vetores-entrada-hash.json');
    assert(is_string($json));
    /** @var array{vetores: list<array{nome: string, cf: string, fatos_usados_entrada: list<array{fato_id: string, versao: int}>, regra_versao_id: string, semantica_calculo: int, canonico: string, sha256: string}>} $d */
    $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    expect($d['vetores'])->toHaveCount(9);
    foreach ($d['vetores'] as $v) {
        expect(HashConferencia::entradaHash($v['cf'], $v['fatos_usados_entrada'], $v['regra_versao_id'], $v['semantica_calculo']))
            ->toBe($v['sha256'], $v['nome']);
        // Canônico: a chave só existe quando semantica_calculo > 1.
        expect(str_contains($v['canonico'], 'semantica_calculo'))->toBe($v['semantica_calculo'] > 1, $v['nome']);
    }
});

it('a constante de semântica do cálculo é 2 (§9.2)', function (): void {
    expect(HashConferencia::SEMANTICA_CALCULO)->toBe(2);
});
