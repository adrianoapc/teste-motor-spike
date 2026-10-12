<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Estado da fila. Nesta fase o processamento é síncrono dentro da requisição
 * (design: decisões fixas), então pendentes/com_erro refletem eventos.fila,
 * que fica vazia. A consulta real mantém o contrato honesto se um dia a fila
 * for usada (Requisito 10.1).
 */
final class FilaController
{
    public function fila(): JsonResponse
    {
        $linha = DB::connection('pgsql')->selectOne(
            <<<'SQL'
            SELECT
                count(*) FILTER (WHERE concluido_em IS NULL AND ultimo_erro IS NULL)::int AS pendentes,
                count(*) FILTER (WHERE concluido_em IS NULL AND ultimo_erro IS NOT NULL)::int AS com_erro
            FROM eventos.fila
            SQL
        );

        /** @var array{pendentes?: int, com_erro?: int} $row */
        $row = $linha === null ? [] : (array) $linha;

        return new JsonResponse([
            'pendentes' => (int) ($row['pendentes'] ?? 0),
            'com_erro' => (int) ($row['com_erro'] ?? 0),
        ]);
    }
}
