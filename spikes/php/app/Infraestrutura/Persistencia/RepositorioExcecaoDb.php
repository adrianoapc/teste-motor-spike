<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioExcecao;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioExcecaoDb implements RepositorioExcecao
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function abrirUnica(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono): ?string
    {
        // Abertura única (§9.4): só abre se não houver outra aberta do mesmo tipo
        // para o alvo (entregável, ou caso quando entregavelId é null).
        if ($entregavelId === null) {
            $existe = $this->conexao->selectOne(
                "SELECT 1 FROM trabalho.excecao WHERE caso_id = ? AND entregavel_id IS NULL AND tipo = ? AND estado = 'aberta' LIMIT 1",
                [$casoId, $tipo],
            );
        } else {
            $existe = $this->conexao->selectOne(
                "SELECT 1 FROM trabalho.excecao WHERE entregavel_id = ? AND tipo = ? AND estado = 'aberta' LIMIT 1",
                [$entregavelId, $tipo],
            );
        }
        if ($existe !== null) {
            return null;
        }

        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, estado)
            VALUES (?, ?, ?, ?, ?, 'aberta')
            RETURNING id::text
            SQL,
            [$casoId, $entregavelId, $tipo, $classe, $dono],
        );
        assert($linha !== null);
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }

    public function resolverCfDoEntregavel(string $entregavelId, string $resolucao): void
    {
        $this->conexao->update(
            <<<'SQL'
            UPDATE trabalho.excecao
            SET estado = 'resolvida', resolucao = ?, resolvida_em = now()
            WHERE entregavel_id = ? AND estado = 'aberta' AND tipo LIKE 'CF-%'
            SQL,
            [$resolucao, $entregavelId],
        );
    }

    public function resolverPorTipo(string $entregavelId, string $tipo, string $resolucao): void
    {
        $this->conexao->update(
            <<<'SQL'
            UPDATE trabalho.excecao
            SET estado = 'resolvida', resolucao = ?, resolvida_em = now()
            WHERE entregavel_id = ? AND estado = 'aberta' AND tipo = ?
            SQL,
            [$resolucao, $entregavelId, $tipo],
        );
    }
}
