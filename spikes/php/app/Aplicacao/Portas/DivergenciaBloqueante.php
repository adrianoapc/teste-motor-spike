<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Conferência vigente divergente de severidade B e sem override. */
final readonly class DivergenciaBloqueante
{
    public function __construct(
        public string $id,
        public string $cf,
        public string $classe,
    ) {}
}
