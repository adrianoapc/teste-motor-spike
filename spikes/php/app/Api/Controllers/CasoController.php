<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\ConsultarCaso;
use Illuminate\Http\JsonResponse;

final readonly class CasoController
{
    public function __construct(private ConsultarCaso $consultar) {}

    public function mostrar(string $titularId, string $competencia): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia);

        return new JsonResponse($this->consultar->consultar($titularId, $competencia));
    }
}
