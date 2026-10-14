<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\RegistrarOverride;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class OverrideController
{
    public function __construct(private RegistrarOverride $override) {}

    public function registrar(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia, $tipo);
        // Validação de forma (422). A ordem 422→404→409 é garantida pelo use case,
        // que revalida corpo antes de tocar o banco.
        try {
            // JsonCru: recusa JSON inválido, chave duplicada e U+0000 em qualquer texto (422, nunca 500).
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException $e) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido: '.$e->getMessage());
        }
        if (! is_object($cru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido');
        }
        CorpoEstrito::exigirChaves($cru, ['ator', 'cf', 'motivo'], 'OverrideRequest');
        $ator = isset($cru->ator) && is_string($cru->ator) ? $cru->ator : '';
        $cf = isset($cru->cf) && is_string($cru->cf) ? $cru->cf : '';
        $motivo = isset($cru->motivo) && is_string($cru->motivo) ? $cru->motivo : '';

        $resultado = $this->override->registrar($titularId, $competencia, $tipo, $ator, $cf, $motivo);

        return new JsonResponse($resultado);
    }
}
