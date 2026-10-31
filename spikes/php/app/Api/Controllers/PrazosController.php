<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fechamento\VerificarPrazos;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * VerificarPrazos (§18.4): POST /admin/prazos/verificar, corpo {"data_ref": "AAAA-MM-DD"}.
 * data_ref ausente ou inválido → 422 corpo_invalido. Resposta 200 {"abertas": n}.
 */
final readonly class PrazosController
{
    public function __construct(private VerificarPrazos $verificar) {}

    public function verificar(Request $request): JsonResponse
    {
        try {
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException $e) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido: '.$e->getMessage());
        }
        $dataRef = is_object($cru) && isset($cru->data_ref) && is_string($cru->data_ref) ? $cru->data_ref : null;
        if ($dataRef === null || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataRef) !== 1 || ! self::dataValida($dataRef)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'data_ref deve ser uma data AAAA-MM-DD');
        }

        return new JsonResponse(['abertas' => $this->verificar->verificar($dataRef)]);
    }

    private static function dataValida(string $iso): bool
    {
        [$a, $m, $d] = array_map('intval', explode('-', $iso));

        return checkdate($m, $d, $a);
    }
}
