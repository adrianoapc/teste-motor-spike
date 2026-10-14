<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Api\ParametrosDeRota;
use App\Aplicacao\Fechamento\CasoConsultado;
use App\Aplicacao\Fechamento\ConsultarCaso;
use App\Aplicacao\Fechamento\EntregavelConsultado;
use App\Aplicacao\Fechamento\ExcecaoConsultada;
use App\Aplicacao\Fechamento\TarefaConsultada;
use Illuminate\Http\JsonResponse;

final readonly class CasoController
{
    public function __construct(private ConsultarCaso $consultar) {}

    public function mostrar(string $titularId, string $competencia): JsonResponse
    {
        ParametrosDeRota::validar($titularId, $competencia);

        return new JsonResponse(self::corpo($this->consultar->consultar($titularId, $competencia)));
    }

    /**
     * CasoResponse do contrato.
     *
     * @return array<string, mixed>
     */
    private static function corpo(CasoConsultado $c): array
    {
        return [
            'caso_id' => $c->casoId,
            'titular_id' => $c->titularId,
            'competencia' => $c->competencia,
            'estado' => $c->estado,
            'entregaveis' => array_map(static fn (EntregavelConsultado $e): array => [
                'entregavel_id' => $e->entregavelId,
                'tipo' => $e->tipo,
                'estado' => $e->estado,
                'executor' => $e->executor,
            ], $c->entregaveis),
            'tarefas_abertas' => array_map(static fn (TarefaConsultada $t): array => [
                'tarefa_id' => $t->tarefaId,
                'entregavel_tipo' => $t->entregavelTipo,
            ], $c->tarefasAbertas),
            'excecoes_abertas' => array_map(static fn (ExcecaoConsultada $x): array => [
                'excecao_id' => $x->excecaoId,
                'tipo' => $x->tipo,
                'classe' => $x->classe,
            ], $c->excecoesAbertas),
        ];
    }
}
