<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioDependencia
{
    public function gravar(string $entregavelId, string $dependeDeId, string $estadoMinimo): void;

    /**
     * Dependências do entregável.
     *
     * @return list<array{depende_de_id: string, estado_minimo: string}>
     */
    public function doEntregavel(string $entregavelId): array;

    /**
     * Entregáveis do caso que dependem de um dado entregável.
     *
     * @return list<string> ids
     */
    public function dependentesDe(string $entregavelId): array;
}
