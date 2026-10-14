<?php

declare(strict_types=1);

namespace App\Aplicacao\Fatos;

/** Entrada de PublicarFato (§6). */
final readonly class PublicarFatoComando
{
    /** @param  mixed  $payload  objeto/array preservado como veio (hash canônico) */
    public function __construct(
        public string $titularId,
        public string $competencia,
        public string $tipo,
        public string $tributo,
        public ?int $valorCentavos,
        public mixed $payload,
        public string $fonte,
        public ?string $origemRef,
        public string $observadoEm,
    ) {}
}
