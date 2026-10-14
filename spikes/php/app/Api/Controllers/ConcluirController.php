<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\ConcluirTarefa;
use App\Aplicacao\Fechamento\ConcluirTarefaComando;
use App\Aplicacao\Fechamento\SaidaDaTarefa;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ConcluirController
{
    public function __construct(private ConcluirTarefa $concluir) {}

    public function concluir(Request $request, string $titularId, string $competencia, string $tipo): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia, $tipo);
        // Corpo cru para preservar {} vs [] nos payloads das saídas.
        try {
            // JsonCru: números do payload das saídas preservados (sem coerção para float binário).
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException $e) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido: '.$e->getMessage());
        }
        if (is_object($cru)) {
            CorpoEstrito::exigirChaves($cru, ['ator', 'saidas'], 'ConcluirRequest');
        }
        if (! is_object($cru) || ! isset($cru->ator) || ! is_string($cru->ator) || $cru->ator === '') {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido');
        }
        $ator = $cru->ator;

        $saidasCru = $cru->saidas ?? null;
        if (! is_array($saidasCru) || $saidasCru === []) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'saidas deve ser uma lista não vazia');
        }

        $saidas = [];
        foreach ($saidasCru as $s) {
            if (! is_object($s)) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'saída inválida');
            }
            CorpoEstrito::exigirChaves($s, ['tributo', 'valor_centavos', 'payload'], 'saidas[]');
            $valor = $s->valor_centavos ?? null;
            if ($valor !== null && ! is_int($valor)) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'valor_centavos deve ser inteiro int64 ou nulo');
            }
            $payload = property_exists($s, 'payload') ? $s->payload : new \stdClass;
            $erroPayload = JsonCru::erroDoPayload($payload);
            if ($erroPayload !== null) {
                throw ErroDeNegocio::invalido('corpo_invalido', $erroPayload);
            }
            $tributo = property_exists($s, 'tributo') ? $s->tributo : '';
            if (! is_string($tributo)) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'tributo deve ser texto');
            }
            $saidas[] = new SaidaDaTarefa($tributo, $valor, $payload);
        }

        $resultado = $this->concluir->concluir(new ConcluirTarefaComando($titularId, $competencia, $tipo, $ator, $saidas));

        return new JsonResponse([
            'entregavel_id' => $resultado->entregavelId,
            'tipo' => $resultado->tipo,
            'estado' => $resultado->estado,
        ]);
    }
}
