<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

/** Entrada de RegistrarOverride (§8.1). */
final readonly class RegistrarOverrideComando
{
    public function __construct(
        public string $titularId,
        public string $competencia,
        public string $tipo,
        public string $ator,
        public string $cf,
        public string $motivo,
        public string $motivoCodigo,
    ) {}
}
