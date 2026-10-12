<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;
use Illuminate\Database\ConnectionInterface;

/**
 * Regra de ouro das transições (§4.1), numa única transação (chamada já dentro
 * da transação do comando): atualiza estado/estado_desde/version e grava a linha
 * em fechamento.entregavel_transicao com a versão de regra do caso.
 *
 * Usa SQL direto para a transição (tabela só-inserção, sem repositório próprio).
 */
final readonly class Transicionador
{
    public function __construct(
        private RepositorioEntregavel $entregaveis,
        private ConnectionInterface $conexao,
    ) {}

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
        $this->entregaveis->salvarEstado($entregavel->id, $para);

        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_transicao
                (entregavel_id, caso_id, de_estado, para_estado, motivo, causado_por_tipo, causado_por_id, ator, regra_versao_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            [
                $entregavel->id,
                $entregavel->casoId,
                $entregavel->estado->value,
                $para->value,
                $motivo,
                $causadoPorTipo,
                $causadoPorId,
                $ator,
                $regraVersaoId,
            ],
        );
    }

    /**
     * Transição de criação (de_estado = NULL → aguardando_insumo), motivo abrir_competencia.
     */
    public function criar(Entregavel $entregavel, string $ator, string $regraVersaoId): void
    {
        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_transicao
                (entregavel_id, caso_id, de_estado, para_estado, motivo, causado_por_tipo, causado_por_id, ator, regra_versao_id)
            VALUES (?, ?, NULL, ?, 'abrir_competencia', 'comando', NULL, ?, ?)
            SQL,
            [
                $entregavel->id,
                $entregavel->casoId,
                EstadoEntregavel::AguardandoInsumo->value,
                $ator,
                $regraVersaoId,
            ],
        );
    }
}
