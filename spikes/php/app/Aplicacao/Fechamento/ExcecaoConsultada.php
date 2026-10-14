<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

final readonly class ExcecaoConsultada
{
    public function __construct(
        public string $excecaoId,
        public string $tipo,
        public string $classe,
    ) {}
}
