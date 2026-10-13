<?php

declare(strict_types=1);

use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\HashCanonico;
use App\Dominio\Fechamento\JsonCru;

/*
 * Vetores compartilhados do hash do fato (semântica §6). Cópia de contrato/vetores-hash.json do PR #7
 * (branch docs/payload-numeros-inteiros): tests/fixtures/vetores-hash.json. Trocar pela leitura direta de
 * contrato/vetores-hash.json quando o PR #7 entrar na branch.
 */
/** @return list<array{nome: string, payload: string, valor_centavos: int|null, esperado: string, canonico?: string, sha256?: string}> */
function vetoresDoHash(): array
{
    $json = file_get_contents(__DIR__.'/../fixtures/vetores-hash.json');
    assert(is_string($json));
    /** @var array{fatos: list<array{nome: string, payload: string, valor_centavos: int|null, esperado: string, canonico?: string, sha256?: string}>} $d */
    $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $d['fatos'];
}

it('hash e JSON canônico dos vetores aceitos batem exatamente', function (): void {
    $aceitos = array_values(array_filter(vetoresDoHash(), static fn (array $f): bool => $f['esperado'] === 'aceito'));
    expect($aceitos)->toHaveCount(12);
    foreach ($aceitos as $f) {
        $payload = JsonCru::decodificar($f['payload']);
        expect(JsonCru::somenteInteiros($payload))->toBeTrue("{$f['nome']}: deveria ser aceito");
        $valor = $f['valor_centavos'];
        $obj = new stdClass;
        $obj->payload = $payload;
        $obj->valor_centavos = $valor;
        expect(HashCanonico::codificar($obj))->toBe($f['canonico'] ?? '')
            ->and(HashFato::hash($payload, is_int($valor) ? $valor : null))->toBe($f['sha256'] ?? '');
    }
});

it('os vetores rejeitados não passam em somenteInteiros', function (): void {
    $rejeitados = array_values(array_filter(vetoresDoHash(), static fn (array $f): bool => $f['esperado'] === 'rejeitado'));
    expect($rejeitados)->toHaveCount(12);
    foreach ($rejeitados as $f) {
        expect(JsonCru::somenteInteiros(JsonCru::decodificar($f['payload'])))->toBeFalse();
    }
});
