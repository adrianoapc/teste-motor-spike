<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioEvento
{
    /**
     * Grava um evento no outbox. payload só com ids permitidos (§12, INV-7).
     *
     * @param  array<string, string>  $payload
     */
    public function gravar(string $nome, ?string $casoId, ?string $entregavelId, array $payload): void;
}
