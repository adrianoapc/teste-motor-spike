<?php

declare(strict_types=1);

namespace Tests\Unidade;

use App\Dominio\Fatos\ContratoTipoFato;
use App\Dominio\Fatos\ValidadorContrato;

/**
 * Cobre as duas correções de review P2 do ValidadorContrato:
 *  - um `required` com vários campos ausentes gera UMA violação por campo (§6.2);
 *  - o prefixo do item (`saidas[i]`) alcança tributo/valor_centavos, não só o payload (§8 passo 3a).
 */
/** Contrato de divisao_socios: item de parcelas exige socio E valor_centavos. */
function contratoDivisaoSocios(): ContratoTipoFato
{
    $esquema = (object) [
        'type' => 'object',
        'properties' => (object) [
            'parcelas' => (object) [
                'type' => 'array',
                'minItems' => 1,
                'items' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'socio' => (object) ['type' => 'string', 'minLength' => 1],
                        'tributo' => (object) ['type' => 'string', 'minLength' => 1],
                        'valor_centavos' => (object) ['type' => 'integer'],
                    ],
                    'required' => ['socio', 'valor_centavos'],
                    'additionalProperties' => true,
                ],
            ],
        ],
        'required' => ['parcelas'],
        'additionalProperties' => true,
    ];

    return new ContratoTipoFato(
        tipo: 'divisao_socios',
        versao: 1,
        esquema: $esquema,
        qualificador: ['usa' => false, 'valores' => []],
        valorCentavos: 'proibido',
        camposHash: ['parcelas'],
        ordenarArrays: ['parcelas' => ['socio', 'tributo']],
        fontesPermitidas: ['calculo'],
    );
}

/** Contrato que usa tributo de uma lista e exige valor_centavos (como `apurado`). */
function contratoComQualificadorEValor(): ContratoTipoFato
{
    return new ContratoTipoFato(
        tipo: 'apurado',
        versao: 1,
        esquema: (object) ['type' => 'object', 'additionalProperties' => true],
        qualificador: ['usa' => true, 'valores' => ['IRPJ', 'CSLL', 'DAS']],
        valorCentavos: 'obrigatorio',
        camposHash: [],
        ordenarArrays: [],
        fontesPermitidas: ['tarefa'],
    );
}

test('required com dois campos ausentes vira uma violacao por campo', function () {
    $payload = (object) ['parcelas' => [(object) []]];

    $violacoes = (new ValidadorContrato)->validar(contratoDivisaoSocios(), '', null, $payload);

    $campos = array_map(static fn ($v) => $v->campo, $violacoes);
    $regras = array_values(array_unique(array_map(static fn ($v) => $v->regra, $violacoes)));

    expect($campos)->toContain('payload.parcelas[0].socio')
        ->and($campos)->toContain('payload.parcelas[0].valor_centavos')
        ->and($regras)->toBe(['obrigatorio']);
});

test('prefixo do item alcanca tributo e valor_centavos no /concluir', function () {
    // Saída 0 de uma tarefa: tributo fora da lista e valor_centavos ausente (obrigatório).
    $violacoes = (new ValidadorContrato('saidas[0]'))->validar(
        contratoComQualificadorEValor(), 'NAO_EXISTE', null, (object) [],
    );

    $mapa = [];
    foreach ($violacoes as $v) {
        $mapa[$v->campo] = $v->regra;
    }

    expect($mapa)->toHaveKey('saidas[0].tributo')
        ->and($mapa['saidas[0].tributo'])->toBe('fora_da_lista')
        ->and($mapa)->toHaveKey('saidas[0].valor_centavos')
        ->and($mapa['saidas[0].valor_centavos'])->toBe('obrigatorio');
});

test('sem prefixo os campos escalares ficam nus no /fatos', function () {
    $violacoes = (new ValidadorContrato)->validar(
        contratoComQualificadorEValor(), '', null, (object) [],
    );

    $campos = array_map(static fn ($v) => $v->campo, $violacoes);

    expect($campos)->toContain('tributo')
        ->and($campos)->toContain('valor_centavos');
});
