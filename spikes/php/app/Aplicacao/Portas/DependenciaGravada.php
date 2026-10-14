<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fechamento\EstadoEntregavel;

/** Dependência já gravada de um entregável do caso. */
final readonly class DependenciaGravada
{
    public function __construct(
        public string $dependeDeId,
        public EstadoEntregavel $estadoMinimo,
    ) {}
}
