<?php

declare(strict_types=1);

use App\Dominio\Fatos\ContratoTipoFato;
use App\Dominio\Fatos\HashFatoV2;
use App\Dominio\Fechamento\JsonCru;

/*
 * Vetores do hash v2 (semântica v5, §6.3). Cópia de contrato/vetores-hash-v2.json em
 * tests/fixtures/ (mesma convenção de vetores-hash.json). Cada caso traz o `contrato`
 * (só campos_hash e ordenar_arrays), o `payload` cru, o `canonico` (entrada exata do
 * sha256) e o `sha256`. `iguais`: pares que têm de dar o mesmo hash.
 */

/**
 * @param  array{campos_hash: list<string>, ordenar_arrays: array<string, list<string>>}  $contrato
 */
function contratoDoVetor(array $contrato): ContratoTipoFato
{
    return new ContratoTipoFato(
        tipo: 'vetor',
        versao: 1,
        esquema: (object) ['type' => 'object'],
        qualificador: ['usa' => false, 'valores' => []],
        valorCentavos: 'opcional',
        camposHash: $contrato['campos_hash'],
        ordenarArrays: $contrato['ordenar_arrays'],
        fontesPermitidas: [],
    );
}

/** @return array{casos: list<array<string, mixed>>, iguais: list<array<string, mixed>>} */
function vetoresV2(): array
{
    $json = file_get_contents(__DIR__.'/../fixtures/vetores-hash-v2.json');
    assert(is_string($json));
    /** @var array{casos: list<array<string, mixed>>, iguais: list<array<string, mixed>>} $d */
    $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $d;
}

it('o canônico e o sha256 de cada caso de vetores-hash-v2.json batem exatamente', function (): void {
    $casos = vetoresV2()['casos'];
    expect($casos)->not->toBeEmpty();
    foreach ($casos as $caso) {
        /** @var array{nome: string, contrato: array{campos_hash: list<string>, ordenar_arrays: array<string, list<string>>}, payload: string, valor_centavos: int|null, canonico: string, sha256: string} $caso */
        $contrato = contratoDoVetor($caso['contrato']);
        $payload = JsonCru::decodificar($caso['payload']);
        $valor = $caso['valor_centavos'];

        expect(HashFatoV2::entrada($payload, $valor, $contrato))->toBe($caso['canonico'], $caso['nome'])
            ->and(HashFatoV2::hash($payload, $valor, $contrato))->toBe($caso['sha256'], $caso['nome']);
    }
});

it('cada par de "iguais" produz o mesmo hash v2', function (): void {
    $pares = vetoresV2()['iguais'];
    expect($pares)->not->toBeEmpty();
    foreach ($pares as $par) {
        /** @var array{nome: string, contrato: array{campos_hash: list<string>, ordenar_arrays: array<string, list<string>>}, payload_a: string, payload_b: string, valor_centavos: int|null, sha256: string} $par */
        $contrato = contratoDoVetor($par['contrato']);
        $valor = $par['valor_centavos'];
        $a = JsonCru::decodificar($par['payload_a']);
        $b = JsonCru::decodificar($par['payload_b']);

        $hashA = HashFatoV2::hash($a, $valor, $contrato);
        $hashB = HashFatoV2::hash($b, $valor, $contrato);

        expect($hashA)->toBe($par['sha256'], $par['nome'])
            ->and($hashB)->toBe($par['sha256'], $par['nome']);
    }
});
