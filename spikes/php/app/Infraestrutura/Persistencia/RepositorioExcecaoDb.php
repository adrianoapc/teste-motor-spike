<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\ExcecaoAberta;
use App\Aplicacao\Portas\RepositorioExcecao;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioExcecaoDb implements RepositorioExcecao
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function existeAberta(string $casoId, ?string $entregavelId, string $tipo): bool
    {
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

        return $existe !== null;
    }

    public function abrir(string $casoId, ?string $entregavelId, string $tipo, string $classe, string $dono, ?string $prazo = null, ?string $motivoCodigo = null): string
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO trabalho.excecao (caso_id, entregavel_id, tipo, classe, dono, prazo, motivo_codigo, estado)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'aberta')
            RETURNING id::text
            SQL,
            [$casoId, $entregavelId, $tipo, $classe, $dono, $prazo, $motivoCodigo],
        );
        assert($linha !== null);
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }

    /** @return list<ExcecaoAberta> */
    public function abertasDoCaso(string $casoId): array
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
            $saida[] = new ExcecaoAberta($row['excecao_id'], $row['tipo'], $row['classe']);
        }

        return $saida;
    }

    public function resolverCfDoEntregavel(string $entregavelId, string $resolucao): void
    {
        $this->conexao->update(
            <<<'SQL'
            UPDATE trabalho.excecao
            SET estado = 'resolvida', resolucao = ?, resolvida_em = clock_timestamp()
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
            SET estado = 'resolvida', resolucao = ?, resolvida_em = clock_timestamp()
            WHERE entregavel_id = ? AND estado = 'aberta' AND tipo = ?
            SQL,
            [$resolucao, $entregavelId, $tipo],
        );
    }

    public function idAbertaDoEntregavel(string $entregavelId, string $tipo): ?string
    {
        $linha = $this->conexao->selectOne(
            "SELECT id::text FROM trabalho.excecao WHERE entregavel_id = ? AND tipo = ? AND estado = 'aberta' ORDER BY aberta_em DESC, id DESC LIMIT 1",
            [$entregavelId, $tipo],
        );
        if ($linha === null) {
            return null;
        }
        /** @var array{id: string} $row */
        $row = (array) $linha;

        return $row['id'];
    }
}
