<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Dominio\Fechamento\Empresa;

/** Entrada de AbrirCompetencias (§5). */
final readonly class AbrirCompetenciasComando
{
    /** @param  list<Empresa>  $empresas */
    public function __construct(
        public string $competencia,
        public string $ator,
        public array $empresas,
    ) {}
}
