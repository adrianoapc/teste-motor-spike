<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioTarefa;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioTarefaDb implements RepositorioTarefa
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function abrir(string $casoId, string $entregavelId, string $tipo, string $responsavel): string
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO trabalho.tarefa_humana (caso_id, entregavel_id, tipo, responsavel, estado)
            VALUES (?, ?, ?, ?, 'aberta')
            RETURNING id::text
            SQL,
            [$casoId, $entregavelId, $tipo, $responsavel],
        );
        assert($linha !== null);
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }

    /** @return array{id: string}|null */
    public function abertaDoEntregavel(string $entregavelId): ?array
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

        return ['id' => $row['id']];
    }

    public function concluir(string $tarefaId): void
    {
        $this->conexao->update(
            "UPDATE trabalho.tarefa_humana SET estado = 'concluida', concluida_em = now() WHERE id = ?",
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
