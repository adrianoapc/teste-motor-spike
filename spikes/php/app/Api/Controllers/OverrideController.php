<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fechamento\RegistrarOverride;
use App\Aplicacao\Suporte\ErroDeNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class OverrideController
{
    public function __construct(private RegistrarOverride $override) {}

    public function registrar(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        // Validação de forma (422). A ordem 422→404→409 é garantida pelo use case,
        // que revalida corpo antes de tocar o banco.
        try {
            $cru = json_decode($request->getContent(), false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'JSON malformado');
        }
        if (! is_object($cru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido');
        }
        $ator = isset($cru->ator) && is_string($cru->ator) ? $cru->ator : '';
        $cf = isset($cru->cf) && is_string($cru->cf) ? $cru->cf : '';
        $motivo = isset($cru->motivo) && is_string($cru->motivo) ? $cru->motivo : '';

        $resultado = $this->override->registrar($titularId, $competencia, $tipo, $ator, $cf, $motivo);

        return new JsonResponse($resultado);
    }
}
