<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class SaudeController
{
    public function health(): JsonResponse
    {
        // Consulta o banco antes de responder (Requisito 1.3).
        DB::connection('pgsql')->select('SELECT 1');

        return new JsonResponse(['status' => 'ok']);
    }
}
