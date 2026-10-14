<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Exceção aberta (módulo trabalho). */
final readonly class ExcecaoAberta
{
    public function __construct(
        public string $excecaoId,
        public string $tipo,
        public string $classe,
    ) {}
}
