<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

final readonly class CatalogoConferencia
{
    public function __construct(
        public string $chave,
        public string $entregavelTipo,
        public string $severidade,
        public string $classePadrao,
    ) {}
}
