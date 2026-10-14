<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Saída de ConsultarCaso (contrato CasoResponse). */
final readonly class CasoConsultado
{
    /**
     * @param  list<EntregavelConsultado>  $entregaveis
     * @param  list<TarefaConsultada>  $tarefasAbertas
     * @param  list<ExcecaoConsultada>  $excecoesAbertas
     */
    public function __construct(
        public string $casoId,
        public string $titularId,
        public string $competencia,
        public string $estado,
        public array $entregaveis,
        public array $tarefasAbertas,
        public array $excecoesAbertas,
    ) {}
}
