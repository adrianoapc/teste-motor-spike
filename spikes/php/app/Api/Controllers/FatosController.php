<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Aplicacao\Fatos\PublicarFato;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class FatosController
{
    public function __construct(private PublicarFato $publicar) {}

    public function publicar(Request $request): JsonResponse
    {
        // Passo 0 (§6/§8): o corpo é validado por inteiro, ANTES de qualquer gravação. JsonCru decodifica com
        // objetos ({} vs []), sem float, recusando chave duplicada, substituto isolado e U+0000.
        try {
            $cru = JsonCru::decodificar($request->getContent());
        } catch (\JsonException $e) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo inválido: '.$e->getMessage());
        }
        if (! is_object($cru)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'corpo deve ser um objeto JSON');
        }
        CorpoEstrito::exigirChaves($cru, ['titular_id', 'competencia', 'tipo', 'tributo', 'valor_centavos', 'payload', 'fonte', 'origem_ref', 'observado_em'], 'FatoRequest');

        // valor_centavos: só int64 nativo ou null (ausente = null): 1.0, 1e2, -0, "600", true, 2^63 => 422.
        $valor = $cru->valor_centavos ?? null;
        if ($valor !== null && ! is_int($valor)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'valor_centavos deve ser inteiro int64 ou nulo');
        }
        // payload: objeto JSON (§6); default {} só se a chave está ausente.
        $payload = property_exists($cru, 'payload') ? $cru->payload : new \stdClass;
        $erroPayload = JsonCru::erroDoPayload($payload);
        if ($erroPayload !== null) {
            throw ErroDeNegocio::invalido('corpo_invalido', $erroPayload);
        }

        // Campos de texto (sem TrimStrings/ConvertEmptyStringsToNull: o valor validado é o gravado).
        $request->validate([
            'titular_id' => ['present', 'string', 'min:1', 'max:64'],
            'competencia' => ['present', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/D'],
            'tipo' => ['present', 'string', 'min:1'],
            'tributo' => ['sometimes', 'string'],
            'fonte' => ['present', 'string', 'min:1'],
            'origem_ref' => ['sometimes', 'nullable', 'string'],
            'observado_em' => ['present', 'string', function (string $atributo, mixed $v, \Closure $falhar): void {
                if (! is_string($v) || ! self::dataHoraValida($v)) {
                    $falhar('observado_em deve ser data-hora RFC 3339');
                }
            }],
        ]);

        $titularId = $this->texto($cru, 'titular_id');
        $competencia = $this->texto($cru, 'competencia');
        $tipo = $this->texto($cru, 'tipo');
        $tributo = isset($cru->tributo) && is_string($cru->tributo) ? $cru->tributo : '';
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
        $ok = preg_match('/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,9})?(?:[Zz]|[+-](\d{2}):(\d{2}))$/D', $v, $m);
        if ($ok !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59
            && (int) ($m[7] ?? 0) <= 15 && (int) ($m[8] ?? 0) <= 59;
    }

    private function texto(object $o, string $campo): string
    {
        return isset($o->{$campo}) && is_string($o->{$campo}) ? $o->{$campo} : '';
    }
}
