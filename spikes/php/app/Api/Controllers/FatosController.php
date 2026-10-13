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
            'observado_em' => ['required', 'string', function (string $atributo, mixed $valor, \Closure $falhar): void {
                if (! is_string($valor) || ! self::dataHoraValida($valor)) {
                    $falhar('observado_em deve ser data-hora RFC 3339');
                }
            }],
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
        // Dinheiro é inteiro nativo (ou nulo): string numérica, float, booleano etc. => 422.
        $valor = $cru->valor_centavos ?? null;
        if ($valor !== null && ! is_int($valor)) {
            throw ErroDeNegocio::invalido('valor_nao_inteiro', 'valor_centavos deve ser inteiro ou nulo');
        }
        $payload = $cru->payload ?? new \stdClass;
        $fonte = $this->texto($cru, 'fonte');
        $origemRef = isset($cru->origem_ref) && is_string($cru->origem_ref) ? $cru->origem_ref : null;
        $observadoEm = $this->texto($cru, 'observado_em');

        $resultado = $this->publicar->publicar(
            $titularId, $competencia, $tipo, $tributo, $valor, $payload, $fonte, $origemRef, $observadoEm,
        );

        return new JsonResponse($resultado);
    }

    /** RFC 3339 (date-time do OpenAPI): fração e fuso `Z`/`±hh:mm` opcionais/obrigatórios conforme a RFC. */
    private static function dataHoraValida(string $v): bool
    {
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:[Zz]|[+-](\d{2}):(\d{2}))$/', $v, $m);
        if ($ok !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59
            && (int) ($m[7] ?? 0) <= 23 && (int) ($m[8] ?? 0) <= 59;
    }

    private function texto(object $o, string $campo): string
    {
        return isset($o->{$campo}) && is_string($o->{$campo}) ? $o->{$campo} : '';
    }
}
