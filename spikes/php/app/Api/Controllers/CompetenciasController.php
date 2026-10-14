<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\CorpoEstrito;
use App\Aplicacao\Fechamento\AbrirCompetencias;
use App\Aplicacao\Suporte\ErroDeNegocio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CompetenciasController
{
    public function __construct(private AbrirCompetencias $abrir) {}

    public function abrir(Request $request): JsonResponse
    {
        // additionalProperties:false do contrato (AbrirCompetenciasRequest e Empresa).
        // `empresas` deve ser lista JSON: `$request->json()->all()` mistura objeto e lista, então inspeciona o JSON cru.
        $cru = json_decode($request->getContent(), false);
        if (is_object($cru) && property_exists($cru, 'empresas') && ! is_array($cru->empresas)) {
            throw ErroDeNegocio::invalido('corpo_invalido', 'empresas deve ser uma lista');
        }
        $bruto = $request->json()->all();
        CorpoEstrito::exigirChaves($bruto, ['competencia', 'ator', 'empresas'], 'AbrirCompetenciasRequest');
        if (is_array($bruto['empresas'] ?? null)) {
            // maxItems do contrato, antes da validação por curinga (que estouraria a memória com listas enormes).
            if (count($bruto['empresas']) > 10000) {
                throw ErroDeNegocio::invalido('corpo_invalido', 'empresas excede o máximo de 10000');
            }
            foreach ($bruto['empresas'] as $empresaBruta) {
                if (is_array($empresaBruta)) {
                    CorpoEstrito::exigirChaves($empresaBruta, ['titular_id', 'regime', 'tem_folha', 'tem_prolabore', 'tem_taxa_municipal', 'filial', 'carteira', 'municipio'], 'Empresa');
                }
            }
        }

        /** @var array<string, mixed> $dados */
        $dados = $request->validate([
            'competencia' => ['present', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/D'],
            'ator' => ['present', 'string', 'min:1'],
            'empresas' => ['required', 'array', 'min:1', 'max:10000'],
            'empresas.*.titular_id' => ['present', 'string', 'min:1', 'max:64'],
            'empresas.*.regime' => ['required', 'string', 'in:SN,LP'],
            'empresas.*.tem_folha' => ['required', self::booleanoNativo()],
            'empresas.*.tem_prolabore' => ['required', self::booleanoNativo()],
            'empresas.*.tem_taxa_municipal' => ['required', self::booleanoNativo()],
            'empresas.*.filial' => ['required', self::booleanoNativo()],
            'empresas.*.carteira' => ['present', 'string', 'min:1'],
            'empresas.*.municipio' => ['present', 'string', 'min:1'],
        ]);

        /** @var string $competencia */
        $competencia = $dados['competencia'];
        /** @var string $ator */
        $ator = $dados['ator'];
        /** @var list<array<string, mixed>> $empresas */
        $empresas = $dados['empresas'];

        $resultado = $this->abrir->abrir($competencia, $ator, $empresas);

        return new JsonResponse($resultado);
    }

    /** Booleano nativo do JSON: "1", "0", 1 e 0 (aceitos pela regra `boolean` do Laravel) não passam. */
    private static function booleanoNativo(): \Closure
    {
        return static function (string $atributo, mixed $valor, \Closure $falhar): void {
            if (! is_bool($valor)) {
                $falhar("{$atributo} deve ser booleano");
            }
        };
    }
}
