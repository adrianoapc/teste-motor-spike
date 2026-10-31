<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\RegistrarImpedimento;
use App\Aplicacao\Fechamento\RegistrarImpedimentoComando;
use App\Aplicacao\Fechamento\ResolverImpedimento;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Impedimento externo (§18.5): abrir e resolver. A ordem de validação 422→404→409 é garantida
 * pelo use case, que revalida corpo antes de tocar o banco.
 */
final readonly class ImpedimentoController
{
    public function __construct(
        private RegistrarImpedimento $registrar,
        private ResolverImpedimento $resolver,
    ) {}

    public function registrar(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia, $tipo);
        $cru = $this->corpo($request);
        CorpoEstrito::exigirChaves($cru, ['ator', 'motivo_codigo', 'texto'], 'ImpedimentoRequest');
        $ator = isset($cru->ator) && is_string($cru->ator) ? $cru->ator : '';
        $motivoCodigo = isset($cru->motivo_codigo) && is_string($cru->motivo_codigo) ? $cru->motivo_codigo : '';
        $texto = isset($cru->texto) && is_string($cru->texto) ? $cru->texto : null;

        $r = $this->registrar->registrar(new RegistrarImpedimentoComando($titularId, $competencia, $tipo, $ator, $motivoCodigo, $texto));

        return new JsonResponse($r);
    }

    public function resolver(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia, $tipo);
        $cru = $this->corpo($request);
        CorpoEstrito::exigirChaves($cru, ['ator'], 'ResolverImpedimentoRequest');
        $ator = isset($cru->ator) && is_string($cru->ator) ? $cru->ator : '';

        $r = $this->resolver->resolver($titularId, $competencia, $tipo, $ator);

        return new JsonResponse($r);
    }

    private function corpo(Request $request): object
    {
        try {
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException $e) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido: '.$e->getMessage());
        }
        if (! is_object($cru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido');
        }

        return $cru;
    }
}
