<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\DependenciaGravada;
use App\Aplicacao\Portas\RepositorioDependencia;
use App\Dominio\Fechamento\EstadoEntregavel;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioDependenciaDb implements RepositorioDependencia
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function gravar(string $entregavelId, string $dependeDeId, string $estadoMinimo): void
    {
        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_dependencia (entregavel_id, depende_de_id, estado_minimo)
            VALUES (?, ?, ?)
            ON CONFLICT (entregavel_id, depende_de_id) DO NOTHING
            SQL,
            [$entregavelId, $dependeDeId, $estadoMinimo],
        );
    }

    /** @return list<DependenciaGravada> */
    public function doEntregavel(string $entregavelId): array
    {
        $linhas = $this->conexao->select(
            'SELECT depende_de_id::text, estado_minimo FROM fechamento.entregavel_dependencia WHERE entregavel_id = ?',
            [$entregavelId],
        );

        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{depende_de_id: string, estado_minimo: string} $row */
            $row = (array) $l;
            $saida[] = new DependenciaGravada($row['depende_de_id'], EstadoEntregavel::from($row['estado_minimo']));
        }

        return $saida;
    }

    /** @return list<string> */
    public function dependentesDe(string $entregavelId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT dep.entregavel_id::text AS id
            FROM fechamento.entregavel_dependencia dep
            JOIN fechamento.entregavel e ON e.id = dep.entregavel_id
            WHERE dep.depende_de_id = ?
            ORDER BY e.tipo
            SQL,
            [$entregavelId],
        );

        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{id: string} $row */
            $row = (array) $l;
            $saida[] = $row['id'];
        }

        return $saida;
    }
}
