<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;

interface RepositorioEntregavel
{
    public function criar(string $casoId, string $tipo, EstadoEntregavel $estado, string $executor, string $area): Entregavel;

    /**
     * Entregáveis do caso, travados (FOR UPDATE), em ordem de tipo.
     *
     * @return list<Entregavel>
     */
    public function doCasoParaAtualizar(string $casoId): array;

    /**
     * Entregáveis do caso, sem trava.
     *
     * @return list<Entregavel>
     */
    public function doCaso(string $casoId): array;

    public function porCasoTipo(string $casoId, string $tipo): ?Entregavel;

    /** Como porCasoTipo, mas trava a linha (FOR UPDATE) até o fim da transação (§13). */
    public function porCasoTipoParaAtualizar(string $casoId, string $tipo): ?Entregavel;

    public function porId(string $entregavelId): ?Entregavel;

    /**
     * Como porId, para quem acabou de usar o id: a ausência é falha de integridade, não resultado.
     *
     * @throws \RuntimeException se não existir
     */
    public function obter(string $entregavelId): Entregavel;

    /**
     * Grava o novo estado (estado, estado_desde = instante da transição, version+1) só se
     * o estado atual ainda for $esperado. Devolve false se outra transação já o mudou.
     */
    public function salvarEstado(string $entregavelId, EstadoEntregavel $esperado, EstadoEntregavel $estado): bool;

    /**
     * Registra a linha de fechamento.entregavel_transicao (tabela só-inserção). `$de` é null na criação.
     *
     * @param  'fato'|'conferencia'|'tarefa'|'comando'|'dependencia'|null  $causadoPorTipo
     */
    public function registrarTransicao(
        Entregavel $entregavel,
        ?EstadoEntregavel $de,
        EstadoEntregavel $para,
        string $motivo,
        ?string $causadoPorTipo,
        ?string $causadoPorId,
        string $ator,
        string $regraVersaoId,
    ): void;

    /** Liga um fato ao entregável (papel entrada/saida). Idempotente pela PK. */
    public function ligarFato(string $entregavelId, string $fatoId, string $papel): void;

    /**
     * Entregáveis que têm um dado fato ligado (qualquer papel).
     *
     * @return list<Entregavel>
     */
    public function comFato(string $fatoId): array;
}
