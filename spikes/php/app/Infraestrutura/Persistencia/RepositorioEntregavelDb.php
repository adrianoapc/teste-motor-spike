<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioEntregavel;
use App\Dominio\Fechamento\Entregavel;
use App\Dominio\Fechamento\EstadoEntregavel;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioEntregavelDb implements RepositorioEntregavel
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function criar(string $casoId, string $tipo, EstadoEntregavel $estado, string $executor, string $area): Entregavel
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO fechamento.entregavel (caso_id, tipo, estado, executor, area)
            VALUES (?, ?, ?, ?, ?)
            RETURNING id::text, caso_id::text, tipo, estado, executor, area, version
            SQL,
            [$casoId, $tipo, $estado->value, $executor, $area],
        );
        assert($linha !== null);

        return $this->mapear($linha);
    }

    /** @return list<Entregavel> */
    public function doCasoParaAtualizar(string $casoId): array
    {
        // Trava as linhas do caso (§13) e devolve em ordem de tipo (E01, E02, …).
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id::text, caso_id::text, tipo, estado, executor, area, version
            FROM fechamento.entregavel
            WHERE caso_id = ?
            ORDER BY tipo
            FOR UPDATE
            SQL,
            [$casoId],
        );

        return $this->mapearLista($linhas);
    }

    /** @return list<Entregavel> */
    public function doCaso(string $casoId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id::text, caso_id::text, tipo, estado, executor, area, version
            FROM fechamento.entregavel WHERE caso_id = ? ORDER BY tipo
            SQL,
            [$casoId],
        );

        return $this->mapearLista($linhas);
    }

    public function porCasoTipo(string $casoId, string $tipo): ?Entregavel
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, caso_id::text, tipo, estado, executor, area, version
            FROM fechamento.entregavel WHERE caso_id = ? AND tipo = ?
            SQL,
            [$casoId, $tipo],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function porCasoTipoParaAtualizar(string $casoId, string $tipo): ?Entregavel
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, caso_id::text, tipo, estado, executor, area, version
            FROM fechamento.entregavel WHERE caso_id = ? AND tipo = ?
            FOR UPDATE
            SQL,
            [$casoId, $tipo],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function porId(string $entregavelId): ?Entregavel
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, caso_id::text, tipo, estado, executor, area, version
            FROM fechamento.entregavel WHERE id = ?
            SQL,
            [$entregavelId],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function salvarEstado(string $entregavelId, EstadoEntregavel $esperado, EstadoEntregavel $estado): bool
    {
        // estado_desde = instante da transição; version incrementado (§4.1).
        // Predicado de estado: não sobrescreve uma transição concorrente (§13).
        $afetadas = $this->conexao->update(
            <<<'SQL'
            UPDATE fechamento.entregavel
            SET estado = ?, estado_desde = clock_timestamp(), version = version + 1
            WHERE id = ? AND estado = ?
            SQL,
            [$estado->value, $entregavelId, $esperado->value],
        );

        return $afetadas === 1;
    }

    public function registrarTransicao(
        Entregavel $entregavel,
        ?EstadoEntregavel $de,
        EstadoEntregavel $para,
        string $motivo,
        ?string $causadoPorTipo,
        ?string $causadoPorId,
        string $ator,
        string $regraVersaoId,
    ): void {
        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_transicao
                (entregavel_id, caso_id, de_estado, para_estado, motivo, causado_por_tipo, causado_por_id, ator, regra_versao_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL,
            [
                $entregavel->id,
                $entregavel->casoId,
                $de?->value,
                $para->value,
                $motivo,
                $causadoPorTipo,
                $causadoPorId,
                $ator,
                $regraVersaoId,
            ],
        );
    }

    public function ligarFato(string $entregavelId, string $fatoId, string $papel): void
    {
        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fechamento.entregavel_fato (entregavel_id, fato_id, papel)
            VALUES (?, ?, ?)
            ON CONFLICT (entregavel_id, fato_id, papel) DO NOTHING
            SQL,
            [$entregavelId, $fatoId, $papel],
        );
    }

    /** @return list<Entregavel> */
    public function comFato(string $fatoId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT DISTINCT e.id::text, e.caso_id::text, e.tipo, e.estado, e.executor, e.area, e.version
            FROM fechamento.entregavel e
            JOIN fechamento.entregavel_fato ef ON ef.entregavel_id = e.id
            WHERE ef.fato_id = ?
            ORDER BY e.tipo
            SQL,
            [$fatoId],
        );

        return $this->mapearLista($linhas);
    }

    /**
     * @param  array<array-key, mixed>  $linhas
     * @return list<Entregavel>
     */
    private function mapearLista(array $linhas): array
    {
        $saida = [];
        foreach ($linhas as $linha) {
            $saida[] = $this->mapear($linha);
        }

        return $saida;
    }

    private function mapear(mixed $linha): Entregavel
    {
        /** @var array{id: string, caso_id: string, tipo: string, estado: string, executor: string, area: string, version: int} $row */
        $row = (array) $linha;

        return new Entregavel(
            id: $row['id'],
            casoId: $row['caso_id'],
            tipo: $row['tipo'],
            estado: EstadoEntregavel::from($row['estado']),
            executor: $row['executor'],
            area: $row['area'],
            version: (int) $row['version'],
        );
    }
}
