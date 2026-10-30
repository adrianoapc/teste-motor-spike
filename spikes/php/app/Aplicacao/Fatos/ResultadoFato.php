<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

/** Saída de PublicarFato. `efeito`: novo | nova_versao | sem_mudanca | obsoleto | conflito_fonte. */
final readonly class ResultadoFato
{
    public function __construct(
        public string $fatoId,
        public int $versao,
        public string $efeito,
        public bool $repetido = false,
    ) {}
}
