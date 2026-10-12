<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Dependência de um entregável para outro, com estado mínimo e condição. */
final readonly class DependenciaRegra
{
    /** @param  list<array<string, scalar>>  $quando */
    public function __construct(
        public string $tipo,
        public string $estadoMinimo,
        public array $quando,
    ) {}
}
