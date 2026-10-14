<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioExcecao
{
    /**
     * Abre exceção se não houver outra aberta do mesmo tipo para o alvo
     * (entregavel_id, ou caso quando entregavelId é null) — §9.4 abertura única.
     * Devolve o id da exceção aberta (nova ou existente) ou null se nada mudou.
     */
    public function abrirUnica(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono): ?string;

    /** Resolve exceções abertas de um entregável cujo tipo começa com 'CF-'. */
    public function resolverCfDoEntregavel(string $entregavelId, string $resolucao): void;

    /** Resolve exceções abertas de um entregável por tipo exato. */
    public function resolverPorTipo(string $entregavelId, string $tipo, string $resolucao): void;
}
