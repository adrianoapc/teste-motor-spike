<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Conferência vigente (maior `em`) de uma CF num entregável. */
final readonly class ConferenciaVigente
{
    public function __construct(
        public string $id,
        public string $resultado,
        public string $severidade,
        public bool $temOverride,
    ) {}
}
