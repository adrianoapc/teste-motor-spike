<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/** Entregável de um caso, com estado atual (§1). */
final readonly class Entregavel
{
    public function __construct(
        public string $id,
        public string $casoId,
        public string $tipo,
        public EstadoEntregavel $estado,
        public string $executor,
        public string $area,
        public int $version,
    ) {}

    public function sistema(): bool
    {
        return $this->executor === 'sistema';
    }

    public function humano(): bool
    {
        return $this->executor === 'humano';
    }
}
