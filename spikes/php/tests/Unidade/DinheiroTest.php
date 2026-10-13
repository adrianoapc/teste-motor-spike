<?php

declare(strict_types=1);

use App\Dominio\Fechamento\Dinheiro;

it('arredonda meio para o par — vetores obrigatórios (Requisito 7.2)', function (int $num, int $den, int $esperado): void {
    expect(Dinheiro::arredMeioPar($num, $den))->toBe($esperado);
})->with([
    'CF-04 base' => [60000000000, 10000, 6000000],
    '5/2 par mantém' => [5, 2, 2],
    '7/2 ímpar sobe' => [7, 2, 4],
    '15/10 ímpar sobe' => [15, 10, 2],
    '25/10 par mantém' => [25, 10, 2],
    '26/10 passa do meio' => [26, 10, 3],
]);

it('arredonda sem resto', function (): void {
    expect(Dinheiro::arredMeioPar(6000000, 1))->toBe(6000000)
        ->and(Dinheiro::arredMeioPar(0, 10))->toBe(0);
});

it('arredonda abaixo do meio para baixo', function (): void {
    expect(Dinheiro::arredMeioPar(14, 10))->toBe(1)   // 1.4 → 1
        ->and(Dinheiro::arredMeioPar(24, 10))->toBe(2) // 2.4 → 2
        ->and(Dinheiro::arredMeioPar(27, 10))->toBe(3); // 2.7 → 3
});

it('arredonda negativos simetricamente', function (): void {
    expect(Dinheiro::arredMeioPar(-5, 2))->toBe(-2)   // -2.5 → par -2
        ->and(Dinheiro::arredMeioPar(-7, 2))->toBe(-4) // -3.5 → par -4
        ->and(Dinheiro::arredMeioPar(-26, 10))->toBe(-3);
});

it('recusa denominador zero', function (): void {
    expect(fn () => Dinheiro::arredMeioPar(10, 0))->toThrow(InvalidArgumentException::class);
});

it('multiplica e divide igual a arredMeioPar quando não há overflow', function (): void {
    foreach ([[60000000, 1000, 10000], [5, 5, 10], [7, 5, 10], [-7, 5, 10], [7, -5, 10], [-7, -5, 10], [123456789, 987, 10000], [25, 1, 10], [35, 1, 10]] as [$a, $b, $d]) {
        expect(Dinheiro::multiplicarDividirArredMeioPar($a, $b, $d))->toBe(Dinheiro::arredMeioPar($a * $b, $d));
    }
});

it('multiplica e divide sem estourar int64 no produto', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 10000, 10000))->toBe(PHP_INT_MAX)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(-PHP_INT_MAX, 10000, 10000))->toBe(-PHP_INT_MAX)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 0, 10000))->toBe(0)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 20000, 10000))->toBeNull()
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, PHP_INT_MAX, 10000))->toBeNull();
});
