<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Tarefa humana aberta (módulo trabalho), sem dado de outro módulo. */
final readonly class TarefaAberta
{
    public function __construct(
        public string $tarefaId,
        public string $entregavelId,
    ) {}
}
