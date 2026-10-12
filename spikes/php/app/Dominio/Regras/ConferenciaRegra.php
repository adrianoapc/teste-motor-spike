<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Conferência aplicável a um entregável, com condição. */
final readonly class ConferenciaRegra
{
    /** @param  list<array<string, scalar>>  $quando */
    public function __construct(
        public string $cf,
        public array $quando,
    ) {}
}
