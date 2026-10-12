<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

final readonly class CatalogoEntregavel
{
    public function __construct(
        public string $chave,
        public string $area,
        public ?string $eventoPublicado,
    ) {}
}
