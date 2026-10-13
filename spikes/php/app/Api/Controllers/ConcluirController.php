<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\ConcluirTarefa;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ConcluirController
{
    public function __construct(private ConcluirTarefa $concluir) {}

    public function concluir(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia, $tipo);
        $request->validate([
            'ator' => ['required', 'string', 'min:1'],
            'saidas' => ['required', 'array', 'min:1'],
        ]);

        // Corpo cru para preservar {} vs [] nos payloads das saídas.
        try {
            // JsonCru: números do payload das saídas preservados (sem coerção para float binário).
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'JSON malformado');
        }
        if (is_object($cru)) {
            CorpoEstrito::exigirChaves($cru, ['ator', 'saidas'], 'ConcluirRequest');
        }
        if (! is_object($cru) || ! isset($cru->ator) || ! is_string($cru->ator)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido');
        }
        $ator = $cru->ator;

        $saidasCru = $cru->saidas ?? [];
        if (! is_array($saidasCru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'saidas deve ser lista');
        }

        $saidas = [];
        foreach ($saidasCru as $s) {
            if (! is_object($s)) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'saída inválida');
            }
            CorpoEstrito::exigirChaves($s, ['tributo', 'valor_centavos', 'payload'], 'saidas[]');
            $valor = $s->valor_centavos ?? null;
            if ($valor !== null && ! is_int($valor)) {
                throw ErroDeNegocio::invalido('valor_nao_inteiro', 'valor_centavos deve ser inteiro ou nulo');
            }
            $payload = property_exists($s, 'payload') ? $s->payload : new \stdClass;
            if (! is_object($payload)) {
                throw ErroDeNegocio::invalido('payload_invalido', 'payload deve ser um objeto');
            }
            if (! JsonCru::somenteInteiros($payload)) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'números do payload devem ser inteiros int64 (sem fração, expoente, -0 ou fora da faixa)');
            }
            $tributo = property_exists($s, 'tributo') ? $s->tributo : '';
            if (! is_string($tributo)) {
                throw ErroDeNegocio::invalido('tributo_invalido', 'tributo deve ser texto');
            }
            $saidas[] = [
                'tributo' => $tributo,
                'valor_centavos' => $valor,
                'payload' => $payload,
            ];
        }

        $resultado = $this->concluir->concluir($titularId, $competencia, $tipo, $ator, $saidas);

        return new JsonResponse($resultado);
    }
}
