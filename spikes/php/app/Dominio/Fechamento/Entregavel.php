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

    /** O mesmo entregável no estado novo (o banco incrementa `version` a cada transição). */
    public function com(EstadoEntregavel $novo): self
    {
        return new self($this->id, $this->casoId, $this->tipo, $novo, $this->executor, $this->area, $this->version + 1);
    }

    public function sistema(): bool
    {
        return $this->executor === 'sistema';
    }

    public function humano(): bool
    {
        return $this->executor === 'humano';
    }
}
