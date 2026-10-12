<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fatos\PublicarFato;
use App\Aplicacao\Suporte\ErroDeNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class FatosController
{
    public function __construct(private PublicarFato $publicar) {}

    public function publicar(Request $request): JsonResponse
    {
        // Validação pela representação associativa (não toca no payload).
        $request->validate([
            'titular_id' => ['required', 'string', 'min:1', 'max:64'],
            'competencia' => ['required', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/'],
            'tipo' => ['required', 'string', 'min:1'],
            'tributo' => ['sometimes', 'string'],
            'valor_centavos' => ['sometimes', 'nullable', 'integer'],
            'fonte' => ['required', 'string', 'min:1'],
            'origem_ref' => ['sometimes', 'nullable', 'string'],
            'observado_em' => ['required', 'string'],
        ]);

        // Corpo CRU, decodificado com objetos, para preservar {} vs [] no payload (§6, design).
        $cru = json_decode($request->getContent(), false, flags: JSON_THROW_ON_ERROR);
        if (! is_object($cru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo deve ser um objeto JSON');
        }

        $titularId = $this->texto($cru, 'titular_id');
        $competencia = $this->texto($cru, 'competencia');
        $tipo = $this->texto($cru, 'tipo');
        $tributo = isset($cru->tributo) && is_string($cru->tributo) ? $cru->tributo : '';
        $valor = isset($cru->valor_centavos) && is_int($cru->valor_centavos) ? $cru->valor_centavos : null;
        $payload = $cru->payload ?? new \stdClass;
        $fonte = $this->texto($cru, 'fonte');
        $origemRef = isset($cru->origem_ref) && is_string($cru->origem_ref) ? $cru->origem_ref : null;
        $observadoEm = $this->texto($cru, 'observado_em');

        // Dinheiro deve ser inteiro: recusa float (design).
        if (isset($cru->valor_centavos) && (is_float($cru->valor_centavos))) {
            throw ErroDeNegocio::invalido('valor_nao_inteiro', 'valor_centavos deve ser inteiro');
        }

        $resultado = $this->publicar->publicar(
            $titularId, $competencia, $tipo, $tributo, $valor, $payload, $fonte, $origemRef, $observadoEm,
        );

        return new JsonResponse($resultado);
    }

    private function texto(object $o, string $campo): string
    {
        return isset($o->{$campo}) && is_string($o->{$campo}) ? $o->{$campo} : '';
    }
}
