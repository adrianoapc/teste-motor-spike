<?php

declare(strict_types=1);

use App\Dominio\Fechamento\Empresa;

function empresaBase(bool $sujeitoFatorR = false): Empresa
{
    return new Empresa(
        titularId: 'T001',
        regime: 'SN',
        temFolha: true,
        temProlabore: true,
        temTaxaMunicipal: false,
        filial: false,
        carteira: 'C1',
        municipio: 'M1',
        sujeitoFatorR: $sujeitoFatorR,
    );
}

it('sujeito_fator_r tem padrão false (v4, M1)', function (): void {
    $e = new Empresa('T001', 'SN', true, true, false, false, 'C1', 'M1');
    expect($e->sujeitoFatorR)->toBeFalse();
});

it('grava sujeito_fator_r no snapshot', function (): void {
    expect(empresaBase(true)->paraSnapshot()['sujeito_fator_r'])->toBeTrue()
        ->and(empresaBase(false)->paraSnapshot()['sujeito_fator_r'])->toBeFalse();
});

it('lê sujeito_fator_r do snapshot, ausente = false', function (): void {
    $comCampo = empresaBase(true)->paraSnapshot();
    expect(Empresa::deSnapshot($comCampo)->sujeitoFatorR)->toBeTrue();

    $semCampo = empresaBase(false)->paraSnapshot();
    unset($semCampo['sujeito_fator_r']); // snapshot legado, de antes da M1
    expect(Empresa::deSnapshot($semCampo)->sujeitoFatorR)->toBeFalse();

    $comNull = empresaBase(false)->paraSnapshot();
    $comNull['sujeito_fator_r'] = null;
    expect(Empresa::deSnapshot($comNull)->sujeitoFatorR)->toBeFalse();
});

it('round-trip de snapshot preserva sujeito_fator_r', function (): void {
    $original = empresaBase(true);
    $reconstruida = Empresa::deSnapshot($original->paraSnapshot());
    expect($reconstruida->sujeitoFatorR)->toBe($original->sujeitoFatorR)
        ->and($reconstruida->regime)->toBe($original->regime)
        ->and($reconstruida->titularId)->toBe($original->titularId);
});
