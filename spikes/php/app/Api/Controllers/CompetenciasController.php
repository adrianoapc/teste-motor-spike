<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Aplicacao\Fechamento\AbrirCompetencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CompetenciasController
{
    public function __construct(private AbrirCompetencias $abrir) {}

    public function abrir(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $dados */
        $dados = $request->validate([
            'competencia' => ['required', 'string', 'regex:/^[0-9]{4}(0[1-9]|1[0-2])$/'],
            'ator' => ['required', 'string', 'min:1'],
            'empresas' => ['required', 'array', 'min:1'],
            'empresas.*.titular_id' => ['required', 'string', 'min:1', 'max:64'],
            'empresas.*.regime' => ['required', 'string', 'in:SN,LP'],
            'empresas.*.tem_folha' => ['required', self::booleanoNativo()],
            'empresas.*.tem_prolabore' => ['required', self::booleanoNativo()],
            'empresas.*.tem_taxa_municipal' => ['required', self::booleanoNativo()],
            'empresas.*.filial' => ['required', self::booleanoNativo()],
            'empresas.*.carteira' => ['required', 'string', 'min:1'],
            'empresas.*.municipio' => ['required', 'string', 'min:1'],
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
