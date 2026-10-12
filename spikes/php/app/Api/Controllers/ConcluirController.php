<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fechamento\ConcluirTarefa;
use App\Aplicacao\Suporte\ErroDeNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ConcluirController
{
    public function __construct(private ConcluirTarefa $concluir) {}

    public function concluir(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        $request->validate([
            'ator' => ['required', 'string', 'min:1'],
            'saidas' => ['required', 'array', 'min:1'],
        ]);

        // Corpo cru para preservar {} vs [] nos payloads das saídas.
        $cru = json_decode($request->getContent(), false, flags: JSON_THROW_ON_ERROR);
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
            if (isset($s->valor_centavos) && is_float($s->valor_centavos)) {
                throw ErroDeNegocio::invalido('valor_nao_inteiro', 'valor_centavos deve ser inteiro');
            }
            $saidas[] = [
                'tributo' => isset($s->tributo) && is_string($s->tributo) ? $s->tributo : '',
                'valor_centavos' => isset($s->valor_centavos) && is_int($s->valor_centavos) ? $s->valor_centavos : null,
                'payload' => $s->payload ?? new \stdClass,
            ];
        }

        $resultado = $this->concluir->concluir($titularId, $competencia, $tipo, $ator, $saidas);

        return new JsonResponse($resultado);
    }
}
