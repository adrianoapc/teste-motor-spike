<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Aplicacao\Suporte\ErroDeNegocio;
use App\Dominio\Fechamento\Competencia;
use Illuminate\Database\ConnectionInterface;

/**
 * Consulta do caso (contrato CasoResponse): caso, entregáveis, tarefas abertas,
 * exceções abertas.
 */
final readonly class ConsultarCaso
{
    public function __construct(
        private RepositorioCaso $casos,
        private RepositorioEntregavel $entregaveis,
        private ConnectionInterface $conexao,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function consultar(string $titularId, string $competenciaValor): array
    {
        $caso = $this->casos->porTitularCompetencia($titularId, new Competencia($competenciaValor));
        if ($caso === null) {
            throw ErroDeNegocio::naoEncontrado("caso inexistente: {$titularId}/{$competenciaValor}");
        }

        $entregaveis = [];
        foreach ($this->entregaveis->doCaso($caso->id) as $e) {
            $entregaveis[] = [
                'entregavel_id' => $e->id,
                'tipo' => $e->tipo,
                'estado' => $e->estado->value,
                'executor' => $e->executor,
            ];
        }

        return [
            'caso_id' => $caso->id,
            'titular_id' => $caso->titularId,
            'competencia' => $caso->competencia->valor,
            'estado' => $caso->estado,
            'entregaveis' => $entregaveis,
            'tarefas_abertas' => $this->tarefasAbertas($caso->id),
            'excecoes_abertas' => $this->excecoesAbertas($caso->id),
        ];
    }

    /**
     * @return list<array{tarefa_id: string, entregavel_tipo: string}>
     */
    private function tarefasAbertas(string $casoId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT t.id::text AS tarefa_id, e.tipo AS entregavel_tipo
            FROM trabalho.tarefa_humana t
            JOIN fechamento.entregavel e ON e.id = t.entregavel_id
            WHERE t.caso_id = ? AND t.estado = 'aberta'
            ORDER BY e.tipo
            SQL,
            [$casoId],
        );
        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{tarefa_id: string, entregavel_tipo: string} $row */
            $row = (array) $l;
            $saida[] = ['tarefa_id' => $row['tarefa_id'], 'entregavel_tipo' => $row['entregavel_tipo']];
        }

        return $saida;
    }

    /**
     * @return list<array{excecao_id: string, tipo: string, classe: string}>
     */
    private function excecoesAbertas(string $casoId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id::text AS excecao_id, tipo, classe
            FROM trabalho.excecao
            WHERE caso_id = ? AND estado = 'aberta'
            ORDER BY aberta_em, id
            SQL,
            [$casoId],
        );
        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{excecao_id: string, tipo: string, classe: string} $row */
            $row = (array) $l;
            $saida[] = ['excecao_id' => $row['excecao_id'], 'tipo' => $row['tipo'], 'classe' => $row['classe']];
        }

        return $saida;
    }
}
