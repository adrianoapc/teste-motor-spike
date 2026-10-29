<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioCaso;
use App\Dominio\Fechamento\Caso;
use App\Dominio\Fechamento\Competencia;
use App\Dominio\Fechamento\Empresa;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioCasoDb implements RepositorioCaso
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function travarTitular(string $titularId): void
    {
        // §13 (v5): a chave é exatamente 'titular|' || titular_id, a mesma string nas três
        // spikes, para que uma ferramenta externa (consumidor de fila) tome o mesmo lock
        // sem saber a linguagem.
        $chave = 'titular|'.$titularId;
        $this->conexao->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chave]);
    }

    public function porTitularCompetencia(string $titularId, Competencia $competencia): ?Caso
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, snapshot, carteira,
                   regra_entregaveis_versao_id::text AS regra_id, estado
            FROM fechamento.caso_competencia
            WHERE titular_id = ? AND competencia = ?
            SQL,
            [$titularId, $competencia->valor],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function porId(string $casoId): ?Caso
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, snapshot, carteira,
                   regra_entregaveis_versao_id::text AS regra_id, estado
            FROM fechamento.caso_competencia WHERE id = ?
            SQL,
            [$casoId],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function criar(Competencia $competencia, Empresa $empresa, string $regraEntregaveisVersaoId): Caso
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO fechamento.caso_competencia
                (titular_id, competencia, snapshot, carteira, regra_entregaveis_versao_id, estado)
            VALUES (?, ?, ?::jsonb, ?, ?, 'aberto')
            RETURNING id::text, titular_id, competencia, snapshot, carteira,
                      regra_entregaveis_versao_id::text AS regra_id, estado
            SQL,
            [
                $empresa->titularId,
                $competencia->valor,
                json_encode($empresa->paraSnapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $empresa->carteira,
                $regraEntregaveisVersaoId,
            ],
        );
        assert($linha !== null);

        return $this->mapear($linha);
    }

    public function encerrar(string $casoId): void
    {
        $this->conexao->update(
            "UPDATE fechamento.caso_competencia SET estado = 'encerrado', encerrado_em = clock_timestamp(), version = version + 1 WHERE id = ?",
            [$casoId],
        );
    }

    /** @return list<Caso> */
    public function abertosDoTitular(string $titularId): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, snapshot, carteira,
                   regra_entregaveis_versao_id::text AS regra_id, estado
            FROM fechamento.caso_competencia
            WHERE titular_id = ? AND estado = 'aberto'
            ORDER BY competencia
            SQL,
            [$titularId],
        );

        $casos = [];
        foreach ($linhas as $linha) {
            $casos[] = $this->mapear($linha);
        }

        return $casos;
    }

    private function mapear(mixed $linha): Caso
    {
        /** @var array{id: string, titular_id: string, competencia: string, snapshot: string, carteira: string, regra_id: string, estado: string} $row */
        $row = (array) $linha;
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($row['snapshot'], true, flags: JSON_THROW_ON_ERROR);

        return new Caso(
            id: $row['id'],
            titularId: $row['titular_id'],
            competencia: new Competencia(trim($row['competencia'])),
            empresa: Empresa::deSnapshot($snapshot),
            carteira: $row['carteira'],
            regraEntregaveisVersaoId: $row['regra_id'],
            estado: $row['estado'],
        );
    }
}
