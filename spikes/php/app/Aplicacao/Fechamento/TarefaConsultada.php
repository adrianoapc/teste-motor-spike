<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

final readonly class TarefaConsultada
{
    public function __construct(
        public string $tarefaId,
        public string $entregavelTipo,
    ) {}
}
