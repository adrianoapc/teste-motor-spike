<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioTarefa
{
    public function abrir(string $casoId, string $entregavelId, string $tipo, string $responsavel): string;

    /** Id da tarefa aberta do entregável, ou null. */
    public function abertaDoEntregavel(string $entregavelId): ?string;

    /**
     * Tarefas abertas do caso (ordem de criação).
     *
     * @return list<TarefaAberta>
     */
    public function abertasDoCaso(string $casoId): array;

    public function concluir(string $tarefaId): void;

    public function cancelarAbertaDoEntregavel(string $entregavelId): void;
}
