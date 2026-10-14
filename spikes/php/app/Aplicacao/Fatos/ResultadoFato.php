<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

/** Saída de PublicarFato. `efeito`: novo | nova_versao | sem_mudanca. */
final readonly class ResultadoFato
{
    public function __construct(
        public string $fatoId,
        public int $versao,
        public string $efeito,
    ) {}
}
