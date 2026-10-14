<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Par (titular, caso) devolvido por AbrirCompetencias. */
final readonly class CasoDaEmpresa
{
    public function __construct(
        public string $titularId,
        public string $casoId,
    ) {}
}
