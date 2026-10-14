<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Entrada esperada de um entregável (§7.1). */
final readonly class EntradaRegra
{
    /** @param  list<array<string, scalar>>  $quando */
    public function __construct(
        public string $tipo,
        public string $tributo,
        public int $competenciaRelativa,
        public array $quando,
    ) {}
}
