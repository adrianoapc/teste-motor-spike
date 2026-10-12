<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioTarefa
{
    public function abrir(string $casoId, string $entregavelId, string $tipo, string $responsavel): string;

    /**
     * Tarefa aberta do entregável, ou null.
     *
     * @return array{id: string}|null
     */
    public function abertaDoEntregavel(string $entregavelId): ?array;

    public function concluir(string $tarefaId): void;

    public function cancelarAbertaDoEntregavel(string $entregavelId): void;
}
