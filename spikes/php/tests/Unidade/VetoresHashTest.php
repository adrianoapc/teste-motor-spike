<?php

declare(strict_types=1);

use App\Dominio\Fatos\HashFato;
use App\Dominio\Fechamento\HashCanonico;
use App\Dominio\Fechamento\JsonCru;

/*
 * Vetores compartilhados do hash do fato (semântica §6). Cópia de contrato/vetores-hash.json do PR #7
 * (branch docs/payload-numeros-inteiros, commit 749776f): tests/fixtures/vetores-hash.json. Trocar pela leitura
 * direta de contrato/vetores-hash.json quando o PR #7 entrar na branch.
 */
/** @return list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, canonico?: string, sha256?: string}> */
function vetoresDoHash(): array
{
    $json = file_get_contents(__DIR__.'/../fixtures/vetores-hash.json');
    assert(is_string($json));
    /** @var array{fatos: list<array{nome: string, esperado: string, payload?: string, valor_centavos?: int|null, valor_centavos_bruto?: string, campos_omitidos?: list<string>, canonico?: string, sha256?: string}>} $d */
    $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    return $d['fatos'];
}

it('hash e JSON canônico dos 22 vetores aceitos batem exatamente', function (): void {
    $aceitos = array_values(array_filter(vetoresDoHash(), static fn (array $f): bool => $f['esperado'] === 'aceito'));
    expect($aceitos)->toHaveCount(22);
    foreach ($aceitos as $f) {
        $payload = array_key_exists('payload', $f) ? JsonCru::decodificar($f['payload']) : new stdClass;
        expect(JsonCru::erroDoPayload($payload))->toBeNull($f['nome'].': deveria ser aceito');
        $valor = $f['valor_centavos'] ?? null;
        $obj = new stdClass;
        $obj->payload = $payload;
        $obj->valor_centavos = $valor;
        expect(HashCanonico::codificar($obj))->toBe($f['canonico'] ?? '', $f['nome'])
            ->and(HashFato::hash($payload, $valor))->toBe($f['sha256'] ?? '', $f['nome']);
    }
});

it('os 27 vetores rejeitados (21 de payload e 6 de valor_centavos) são recusados pelas camadas de validação', function (): void {
    $rejeitados = array_values(array_filter(vetoresDoHash(), static fn (array $f): bool => $f['esperado'] === 'rejeitado'));
    expect($rejeitados)->toHaveCount(27);
    $deValor = 0;
    foreach ($rejeitados as $f) {
        if (isset($f['valor_centavos_bruto'])) {
            $deValor++;
            $v = JsonCru::decodificar($f['valor_centavos_bruto']);
            expect(is_int($v))->toBeFalse($f['nome']);

            continue;
        }
        try {
            $erro = JsonCru::erroDoPayload(JsonCru::decodificar($f['payload'] ?? '{}'));
        } catch (JsonException) {
            $erro = 'json';
        }
        expect($erro)->not->toBeNull($f['nome']);
    }
    expect($deValor)->toBe(6);
});

it('chaves numéricas e ordem por code point são ordenadas como strings de bytes', function (): void {
    $v = JsonCru::decodificar('{"9":1,"10":2,"1":3,"～":4,"😀":5}');
    expect(JsonCru::codificar($v, true))->toBe('{"1":3,"10":2,"9":1,"～":4,"😀":5}');
});

it('JsonCru recusa U+0000, chave com U+0000 e substituto isolado sem lançar Error', function (): void {
    foreach (['{"a":"\\u0000"}', '{"\\u0000x":1}', '{"\\ud800":1}', '{"a":1,"\\u0061":2}'] as $ruim) {
        expect(fn () => JsonCru::decodificar($ruim))->toThrow(JsonException::class);
    }
});
