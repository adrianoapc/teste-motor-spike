<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Entrada de ConcluirTarefa (§8). */
final readonly class ConcluirTarefaComando
{
    /** @param  list<SaidaDaTarefa>  $saidas */
    public function __construct(
        public string $titularId,
        public string $competencia,
        public string $tipo,
        public string $ator,
        public array $saidas,
    ) {}
}
