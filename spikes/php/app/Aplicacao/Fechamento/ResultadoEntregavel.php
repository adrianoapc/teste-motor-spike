<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Dominio\Fechamento\Entregavel;

/** Saída de ConcluirTarefa e RegistrarOverride: o entregável como ficou. */
final readonly class ResultadoEntregavel
{
    public function __construct(
        public string $entregavelId,
        public string $tipo,
        public string $estado,
    ) {}

    public static function de(Entregavel $entregavel): self
    {
        return new self($entregavel->id, $entregavel->tipo, $entregavel->estado->value);
    }
}
