<?php

declare(strict_types=1);

// Regra malformada falha alto (RegraInvalida) em vez de virar tolerância 0/0 ou pró-labore 0%.

use App\Dominio\Regras\Parametros;
use App\Dominio\Regras\RegraInvalida;
use App\Dominio\Regras\Tolerancia;

it('lê a tolerância bem formada', function (): void {
    $t = Tolerancia::de('v1', ['padrao' => ['ok_ate_centavos' => 0, 'alerta_ate_centavos' => 100]]);

    expect($t->versaoId)->toBe('v1')
        ->and($t->okAteCentavos)->toBe(0)
        ->and($t->alertaAteCentavos)->toBe(100);
});

it('tolerância malformada é RegraInvalida com a regra e a versão na mensagem', function (array $conteudo, string $trecho): void {
    /** @var array<string, mixed> $conteudo */
    $erro = null;
    try {
        Tolerancia::de('v9', $conteudo);
    } catch (RegraInvalida $e) {
        $erro = $e->getMessage();
    }

    expect($erro)->toContain('fiscal.tolerancia')->toContain('v9')->toContain($trecho);
})->with([
    'sem padrao' => [[], "'padrao' ausente"],
    'padrao não é objeto' => [['padrao' => 5], "'padrao' ausente"],
    'sem ok_ate' => [['padrao' => ['alerta_ate_centavos' => 100]], 'ok_ate_centavos'],
    'ok_ate em texto' => [['padrao' => ['ok_ate_centavos' => '0', 'alerta_ate_centavos' => 100]], 'ok_ate_centavos'],
    'ok_ate decimal' => [['padrao' => ['ok_ate_centavos' => 0.5, 'alerta_ate_centavos' => 100]], 'ok_ate_centavos'],
    'sem alerta_ate' => [['padrao' => ['ok_ate_centavos' => 0]], 'alerta_ate_centavos'],
    'alerta_ate nulo' => [['padrao' => ['ok_ate_centavos' => 0, 'alerta_ate_centavos' => null]], 'alerta_ate_centavos'],
    'ok_ate negativo' => [['padrao' => ['ok_ate_centavos' => -1, 'alerta_ate_centavos' => 100]], 'exige 0 <='],
    'alerta menor que ok' => [['padrao' => ['ok_ate_centavos' => 50, 'alerta_ate_centavos' => 10]], 'exige 0 <='],
]);

it('lê os parâmetros bem formados, inclusive 0%', function (): void {
    expect(Parametros::de('p1', ['prolabore_percentual_bp' => 2800])->prolaborePercentualBp)->toBe(2800)
        ->and(Parametros::de('p1', ['prolabore_percentual_bp' => 0])->prolaborePercentualBp)->toBe(0)
        ->and(Parametros::de('p1', ['prolabore_percentual_bp' => 10000])->prolaborePercentualBp)->toBe(10000);
});

it('parâmetros malformados são RegraInvalida: nunca viram pró-labore 0%', function (array $conteudo): void {
    /** @var array<string, mixed> $conteudo */
    expect(fn () => Parametros::de('p9', $conteudo))->toThrow(RegraInvalida::class, 'fiscal.parametros');
})->with([
    'sem a chave' => [[]],
    'em texto' => [['prolabore_percentual_bp' => '2800']],
    'decimal' => [['prolabore_percentual_bp' => 28.5]],
    'nulo' => [['prolabore_percentual_bp' => null]],
    'negativo' => [['prolabore_percentual_bp' => -1]],
    'acima de 100%' => [['prolabore_percentual_bp' => 10001]],
]);
