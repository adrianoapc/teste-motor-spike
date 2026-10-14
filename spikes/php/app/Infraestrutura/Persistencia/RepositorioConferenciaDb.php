<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioConferencia;
use App\Dominio\Conferencias\HashConferencia;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioConferenciaDb implements RepositorioConferencia
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function gravarSeNova(
        string $cf,
        string $casoId,
        string $entregavelId,
        string $regraVersaoId,
        array $fatosUsados,
        string $entradaHash,
        string $resultado,
        ?int $esperadoCentavos,
        ?int $obtidoCentavos,
        array $diferenca,
        string $severidade,
        string $classe,
    ): void {
        $fatosJson = json_encode($fatosUsados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        // `centavos` em string só ocorre para diferença acima de int64 (decimal exato): vira número no jsonb.
        if (isset($diferenca['centavos']) && is_string($diferenca['centavos']) && preg_match('/^[0-9]+$/D', $diferenca['centavos']) === 1) {
            $difJson = '{"centavos":'.$diferenca['centavos'].'}';
        } else {
            $difJson = json_encode((object) $diferenca, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO conferencias.conferencia
                (cf, caso_id, entregavel_id, regra_versao_id, fatos_usados, entrada_hash,
                 resultado, esperado_centavos, obtido_centavos, diferenca, severidade, classe, em, semantica_calculo)
            VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?::jsonb, ?, ?, clock_timestamp(), ?)
            ON CONFLICT (cf, entregavel_id, entrada_hash) DO NOTHING
            SQL,
            [
                $cf, $casoId, $entregavelId, $regraVersaoId, $fatosJson, $entradaHash,
                $resultado, $esperadoCentavos, $obtidoCentavos, $difJson, $severidade, $classe,
                HashConferencia::SEMANTICA_CALCULO,
            ],
        );
    }

    /** @return array{id: string, resultado: string, severidade: string, tem_override: bool}|null */
    public function vigente(string $entregavelId, string $cf): ?array
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, resultado, severidade, (override_autor IS NOT NULL) AS tem_override
            FROM conferencias.conferencia
            WHERE entregavel_id = ? AND cf = ?
            ORDER BY em DESC, id DESC
            LIMIT 1
            SQL,
            [$entregavelId, $cf],
        );
        if ($linha === null) {
            return null;
        }
        /** @var array{id: string, resultado: string, severidade: string, tem_override: bool} $row */
        $row = (array) $linha;

        return [
            'id' => $row['id'],
            'resultado' => $row['resultado'],
            'severidade' => $row['severidade'],
            'tem_override' => (bool) $row['tem_override'],
        ];
    }

    public function registrarOverride(string $conferenciaId, string $autor, string $motivo): void
    {
        $this->conexao->update(
            'UPDATE conferencias.conferencia SET override_autor = ?, override_motivo = ?, override_em = clock_timestamp() WHERE id = ?',
            [$autor, $motivo, $conferenciaId],
        );
    }

    /** @return list<string> */
    public function cfsDivergentesBloqueantes(string $entregavelId): array
    {
        return array_map(static fn (array $d): string => $d['cf'], $this->divergentesBloqueantes($entregavelId));
    }

    /** @return list<array{id: string, cf: string, classe: string}> */
    public function divergentesBloqueantes(string $entregavelId): array
    {
        // Para cada CF do entregável, a conferência vigente (maior em) que seja
        // divergente, severidade B e sem override.
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id, cf, classe FROM (
                SELECT DISTINCT ON (cf) id::text AS id, cf, classe, resultado, severidade, override_autor
                FROM conferencias.conferencia
                WHERE entregavel_id = ?
                ORDER BY cf, em DESC, id DESC
            ) v
            WHERE resultado = 'divergente' AND severidade = 'B' AND override_autor IS NULL
            ORDER BY cf
            SQL,
            [$entregavelId],
        );

        $saida = [];
        foreach ($linhas as $l) {
            /** @var array{id: string, cf: string, classe: string} $row */
            $row = (array) $l;
            $saida[] = ['id' => $row['id'], 'cf' => $row['cf'], 'classe' => $row['classe']];
        }

        return $saida;
    }
}
