<?php

declare(strict_types=1);

use App\Dominio\Fechamento\Dinheiro;

it('arredonda meio para o par — vetores obrigatórios (Requisito 7.2)', function (int $num, int $den, int $esperado): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar($num, 1, $den))->toBe($esperado);
})->with([
    'CF-04 base' => [60000000000, 10000, 6000000],
    '5/2 par mantém' => [5, 2, 2],
    '7/2 ímpar sobe' => [7, 2, 4],
    '15/10 ímpar sobe' => [15, 10, 2],
    '25/10 par mantém' => [25, 10, 2],
    '26/10 passa do meio' => [26, 10, 3],
]);

it('arredonda sem resto', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(6000000, 1, 2))->toBe(3000000)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(0, 1, 10))->toBe(0);
});

it('arredonda abaixo do meio para baixo', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(14, 1, 10))->toBe(1)   // 1.4 → 1
        ->and(Dinheiro::multiplicarDividirArredMeioPar(24, 1, 10))->toBe(2) // 2.4 → 2
        ->and(Dinheiro::multiplicarDividirArredMeioPar(27, 1, 10))->toBe(3); // 2.7 → 3
});

it('arredonda negativos simetricamente', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(-5, 1, 2))->toBe(-2)   // -2.5 → par -2
        ->and(Dinheiro::multiplicarDividirArredMeioPar(-7, 1, 2))->toBe(-4) // -3.5 → par -4
        ->and(Dinheiro::multiplicarDividirArredMeioPar(-26, 1, 10))->toBe(-3);
});

it('recusa denominador fora da faixa', function (): void {
    expect(fn () => Dinheiro::multiplicarDividirArredMeioPar(10, 1, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Dinheiro::multiplicarDividirArredMeioPar(10, 1, 1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Dinheiro::multiplicarDividirArredMeioPar(10, 1, 3037000500))->toThrow(InvalidArgumentException::class);
});

it('multiplica e divide com os sinais e o meio-para-o-par esperados quando não há overflow', function (int $a, int $b, int $den, int $esperado): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar($a, $b, $den))->toBe($esperado);
})->with([
    'alíquota 6%' => [60000000, 1000, 10000, 6000000],
    '25/10 par mantém' => [5, 5, 10, 2],
    '35/10 ímpar sobe' => [7, 5, 10, 4],
    'negativo × positivo' => [-7, 5, 10, -4],
    'positivo × negativo' => [7, -5, 10, -4],
    'negativo × negativo' => [-7, -5, 10, 4],
    'produto grande' => [123456789, 987, 10000, 12185185],
    '2,5 → 2' => [25, 1, 10, 2],
    '3,5 → 4' => [35, 1, 10, 4],
]);

it('multiplica e divide sem estourar int64 no produto', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 10000, 10000))->toBe(PHP_INT_MAX)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(-PHP_INT_MAX, 10000, 10000))->toBe(-PHP_INT_MAX)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 0, 10000))->toBe(0)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, 20000, 10000))->toBeNull()
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, PHP_INT_MAX, 10000))->toBeNull();
});

it('preserva PHP_INT_MIN quando o resultado exato é PHP_INT_MIN', function (): void {
    expect(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MIN, 10000, 10000))->toBe(PHP_INT_MIN)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(10000, PHP_INT_MIN, 10000))->toBe(PHP_INT_MIN)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MIN, 5000, 10000))->toBe(-4611686018427387904)
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MIN, -10000, 10000))->toBeNull()
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MIN, 20000, 10000))->toBeNull()
        ->and(Dinheiro::multiplicarDividirArredMeioPar(PHP_INT_MAX, -10000, 10000))->toBe(-PHP_INT_MAX);
});

it('calcula a diferença absoluta exata, inclusive acima de int64', function (): void {
    expect(Dinheiro::diferencaExata(5, 9))->toBe(4)
        ->and(Dinheiro::diferencaExata(-5, 9))->toBe(14)
        ->and(Dinheiro::diferencaExata(PHP_INT_MAX, 0))->toBe(PHP_INT_MAX)
        ->and(Dinheiro::diferencaExata(0, PHP_INT_MIN))->toBe('9223372036854775808')
        ->and(Dinheiro::diferencaExata(PHP_INT_MAX, -1))->toBe('9223372036854775808')
        ->and(Dinheiro::diferencaExata(PHP_INT_MAX, PHP_INT_MIN))->toBe('18446744073709551615')
        ->and(Dinheiro::diferencaExata(PHP_INT_MAX, PHP_INT_MIN + 1))->toBe('18446744073709551614')
        ->and(Dinheiro::diferencaExata(PHP_INT_MIN, PHP_INT_MIN + 1))->toBe(1)
        ->and(Dinheiro::diferencaExata(PHP_INT_MAX - 1, PHP_INT_MAX))->toBe(1);
});

it('compara diferenças exatas sem perder precisão', function (): void {
    expect(Dinheiro::compararDiferencas('18446744073709551615', '18446744073709551614'))->toBe(1)
        ->and(Dinheiro::compararDiferencas('18446744073709551614', '18446744073709551615'))->toBe(-1)
        ->and(Dinheiro::compararDiferencas(PHP_INT_MAX, '9223372036854775808'))->toBe(-1)
        ->and(Dinheiro::compararDiferencas(7, 7))->toBe(0)
        ->and(Dinheiro::compararDiferencas(10, 9))->toBe(1);
});
