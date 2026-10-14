<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/** Caso = empresa × competência (§1). */
final readonly class Caso
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public string $id,
        public string $titularId,
        public Competencia $competencia,
        public array $snapshot,
        public string $carteira,
        public string $regraEntregaveisVersaoId,
        public string $estado,
    ) {}

    public function aberto(): bool
    {
        return $this->estado === 'aberto';
    }

    public function encerrado(): bool
    {
        return $this->estado === 'encerrado';
    }

    public function contexto(): Contexto
    {
        return Contexto::de($this->snapshot, $this->competencia);
    }
}
