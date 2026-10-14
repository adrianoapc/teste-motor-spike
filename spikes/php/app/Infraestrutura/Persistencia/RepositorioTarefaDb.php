<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioTarefa;
use App\Aplicacao\Portas\TarefaAberta;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioTarefaDb implements RepositorioTarefa
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function abrir(string $casoId, string $entregavelId, string $tipo, string $responsavel): string
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO trabalho.tarefa_humana (caso_id, entregavel_id, tipo, responsavel, estado, criada_em)
            VALUES (?, ?, ?, ?, 'aberta', clock_timestamp())
            RETURNING id::text
            SQL,
            [$casoId, $entregavelId, $tipo, $responsavel],
        );
        assert($linha !== null);
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }

    public function abertaDoEntregavel(string $entregavelId): ?string
    {
        $linha = $this->conexao->selectOne(
            "SELECT id::text FROM trabalho.tarefa_humana WHERE entregavel_id = ? AND estado = 'aberta' ORDER BY criada_em DESC, id DESC LIMIT 1",
            [$entregavelId],
        );
        if ($linha === null) {
            return null;
        }
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }

    /** @return list<TarefaAberta> */
    public function abertasDoCaso(string $casoId): array
    {
        $linhas = $this->conexao->select(
            "SELECT id::text AS tarefa_id, entregavel_id::text AS entregavel_id FROM trabalho.tarefa_humana WHERE caso_id = ? AND estado = 'aberta' ORDER BY criada_em, id",
            [$casoId],
        );
        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{tarefa_id: string, entregavel_id: string} $row */
            $row = (array) $l;
            $saida[] = new TarefaAberta($row['tarefa_id'], $row['entregavel_id']);
        }

        return $saida;
    }

    public function concluir(string $tarefaId): void
    {
        $this->conexao->update(
            "UPDATE trabalho.tarefa_humana SET estado = 'concluida', concluida_em = clock_timestamp() WHERE id = ?",
            [$tarefaId],
        );
    }

    public function cancelarAbertaDoEntregavel(string $entregavelId): void
    {
        $this->conexao->update(
            "UPDATE trabalho.tarefa_humana SET estado = 'cancelada' WHERE entregavel_id = ? AND estado = 'aberta'",
            [$entregavelId],
        );
    }
}
