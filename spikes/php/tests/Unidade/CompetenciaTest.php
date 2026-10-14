<?php

declare(strict_types=1);

use App\Dominio\Fechamento\Competencia;

it('soma meses dentro do ano', function (): void {
    expect((new Competencia('202601'))->somar(1)->valor)->toBe('202602')
        ->and((new Competencia('202603'))->somar(5)->valor)->toBe('202608');
});

it('vira o ano ao subtrair', function (): void {
    // §7.1: competencia_relativa -1 sobre 202601 dá 202512.
    expect((new Competencia('202601'))->somar(-1)->valor)->toBe('202512')
        ->and((new Competencia('202601'))->somar(-2)->valor)->toBe('202511')
        ->and((new Competencia('202609'))->somar(-1)->valor)->toBe('202608')
        ->and((new Competencia('202609'))->somar(-2)->valor)->toBe('202607');
});

it('vira o ano ao somar', function (): void {
    expect((new Competencia('202611'))->somar(2)->valor)->toBe('202701')
        ->and((new Competencia('202612'))->somar(1)->valor)->toBe('202701');
});

it('calcula o mês do trimestre', function (): void {
    // ((mes - 1) % 3) + 1
    expect((new Competencia('202601'))->mesDoTrimestre())->toBe(1)
        ->and((new Competencia('202602'))->mesDoTrimestre())->toBe(2)
        ->and((new Competencia('202603'))->mesDoTrimestre())->toBe(3)
        ->and((new Competencia('202609'))->mesDoTrimestre())->toBe(3)
        ->and((new Competencia('202612'))->mesDoTrimestre())->toBe(3)
        ->and((new Competencia('202610'))->mesDoTrimestre())->toBe(1);
});

it('recusa competência inválida', function (string $valor): void {
    expect(fn () => new Competencia($valor))->toThrow(InvalidArgumentException::class);
})->with(['202600', '202613', '20260', '2026011', 'abcdef']);
