<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fechamento\AplicarCalendario;
use Illuminate\Http\JsonResponse;

/**
 * AplicarCalendario (§18.3): POST /admin/calendario/aplicar, corpo {}. Resposta 200 {"alterados": n}.
 */
final readonly class CalendarioController
{
    public function __construct(private AplicarCalendario $aplicar) {}

    public function aplicar(): JsonResponse
    {
        return new JsonResponse(['alterados' => $this->aplicar->aplicar()]);
    }
}
