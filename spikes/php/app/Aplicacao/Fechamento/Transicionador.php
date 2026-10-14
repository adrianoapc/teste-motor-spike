<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;

/**
 * Regra de ouro das transições (§4.1), numa única transação (chamada já dentro
 * da transação do comando): atualiza estado/estado_desde/version e grava a linha
 * em fechamento.entregavel_transicao com a versão de regra do caso.
 */
final readonly class Transicionador
{
    public function __construct(private RepositorioEntregavel $entregaveis) {}

    /**
     * @param  'fato'|'conferencia'|'tarefa'|'comando'|'dependencia'|null  $causadoPorTipo
     */
    public function transicionar(
        Entregavel $entregavel,
        EstadoEntregavel $para,
        string $motivo,
        string $ator,
        string $regraVersaoId,
        ?string $causadoPorTipo = null,
        ?string $causadoPorId = null,
    ): void {
        if (! $this->entregaveis->salvarEstado($entregavel->id, $entregavel->estado, $para)) {
            throw ErroDeNegocio::conflito('estado_concorrente', 'entregável mudou de estado concorrentemente');
        }

        $this->entregaveis->registrarTransicao(
            $entregavel, $entregavel->estado, $para, $motivo, $causadoPorTipo, $causadoPorId, $ator, $regraVersaoId,
        );
    }

    /**
     * Transição de criação (de_estado = NULL → aguardando_insumo), motivo abrir_competencia.
     */
    public function criar(Entregavel $entregavel, string $ator, string $regraVersaoId): void
    {
        $this->entregaveis->registrarTransicao(
            $entregavel, null, EstadoEntregavel::AguardandoInsumo, 'abrir_competencia', 'comando', null, $ator, $regraVersaoId,
        );
    }
}
