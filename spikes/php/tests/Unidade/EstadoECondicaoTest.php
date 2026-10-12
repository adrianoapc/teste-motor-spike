<?php

declare(strict_types=1);

use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Condicao;
use App\Dominio\Fechamento\Contexto;
use App\Dominio\Fechamento\EstadoEntregavel;

it('ordena os estados do caminho principal', function (): void {
    expect(EstadoEntregavel::Validado->maiorOuIgual(EstadoEntregavel::Pronto))->toBeTrue()
        ->and(EstadoEntregavel::Disponibilizado->maiorOuIgual(EstadoEntregavel::Validado))->toBeTrue()
        ->and(EstadoEntregavel::Pronto->maiorOuIgual(EstadoEntregavel::Validado))->toBeFalse()
        ->and(EstadoEntregavel::Validado->maiorOuIgual(EstadoEntregavel::Validado))->toBeTrue();
});

it('estado fora do caminho nunca é maior ou igual', function (): void {
    expect(EstadoEntregavel::Divergente->maiorOuIgual(EstadoEntregavel::AguardandoInsumo))->toBeFalse()
        ->and(EstadoEntregavel::Validado->maiorOuIgual(EstadoEntregavel::Invalidado))->toBeFalse()
        ->and(EstadoEntregavel::Invalidado->maiorOuIgual(EstadoEntregavel::Invalidado))->toBeFalse();
});

it('não tem o estado pago (aposentado na v3)', function (): void {
    expect(EstadoEntregavel::tryFrom('pago'))->toBeNull();
});

it('condição vazia é sempre verdadeira', function (): void {
    $ctx = Contexto::de(['regime' => 'SN'], new Competencia('202609'));
    expect(Condicao::casa([], $ctx))->toBeTrue();
});

it('condição é OU de objetos, cada objeto é E de pares', function (): void {
    $ctx = Contexto::de(['tem_folha' => true, 'tem_prolabore' => false], new Competencia('202609'));

    // tem_folha OU tem_prolabore → verdadeiro (tem_folha casa).
    expect(Condicao::casa([['tem_folha' => true], ['tem_prolabore' => true]], $ctx))->toBeTrue();
    // E dentro de um objeto: tem_folha E tem_prolabore → falso.
    expect(Condicao::casa([['tem_folha' => true, 'tem_prolabore' => true]], $ctx))->toBeFalse();
    // nenhum casa.
    expect(Condicao::casa([['regime' => 'LP']], $ctx))->toBeFalse();
});

it('casa pelo mes_do_trimestre derivado', function (): void {
    $ctx = Contexto::de(['regime' => 'LP'], new Competencia('202609')); // mês 9 → trimestre 3
    expect(Condicao::casa([['regime' => 'LP', 'mes_do_trimestre' => 3]], $ctx))->toBeTrue()
        ->and(Condicao::casa([['regime' => 'LP', 'mes_do_trimestre' => 1]], $ctx))->toBeFalse();
});

it('distingue booleano de ausência', function (): void {
    $ctx = Contexto::de(['tem_taxa_municipal' => false], new Competencia('202609'));
    expect(Condicao::casa([['tem_taxa_municipal' => true]], $ctx))->toBeFalse()
        ->and(Condicao::casa([['tem_taxa_municipal' => false]], $ctx))->toBeTrue();
});
