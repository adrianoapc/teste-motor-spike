<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Saída de AbrirCompetencias: casos criados agora e casos que já existiam (idempotência). */
final readonly class ResultadoAbertura
{
    /**
     * @param  list<CasoDaEmpresa>  $criados
     * @param  list<CasoDaEmpresa>  $existentes
     */
    public function __construct(
        public array $criados,
        public array $existentes,
    ) {}
}
