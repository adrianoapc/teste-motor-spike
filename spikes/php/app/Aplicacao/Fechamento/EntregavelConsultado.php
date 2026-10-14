<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

final readonly class EntregavelConsultado
{
    public function __construct(
        public string $entregavelId,
        public string $tipo,
        public string $estado,
        public string $executor,
    ) {}
}
