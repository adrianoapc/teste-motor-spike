<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/** Caso = empresa × competência (§1). */
final readonly class Caso
{
    public function __construct(
        public string $id,
        public string $titularId,
        public Competencia $competencia,
        public Empresa $empresa,
        public string $carteira,
        public string $regraEntregaveisVersaoId,
        public string $estado,
        public ?string $regraPrazosVersaoId = null,
    ) {}

    public function encerrado(): bool
    {
        return $this->estado === 'encerrado';
    }

    public function contexto(): Contexto
    {
        return Contexto::de($this->empresa->paraSnapshot(), $this->competencia);
    }
}
