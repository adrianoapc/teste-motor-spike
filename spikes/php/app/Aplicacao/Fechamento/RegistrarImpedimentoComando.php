<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Entrada de RegistrarImpedimento (§18.5). */
final readonly class RegistrarImpedimentoComando
{
    public function __construct(
        public string $titularId,
        public string $competencia,
        public string $tipo,
        public string $ator,
        public string $motivoCodigo,
        public ?string $texto,
    ) {}
}
