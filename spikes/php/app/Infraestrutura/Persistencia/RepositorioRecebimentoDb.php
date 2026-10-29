<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioRecebimento;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioRecebimentoDb implements RepositorioRecebimento
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function gravar(
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        string $fonte,
        ?string $chaveIdempotencia,
        string $observadoEm,
        int $tipoVersao,
        string $hash,
        string $efeito,
        string $fatoId,
        mixed $payload,
        ?int $valorCentavos,
    ): void {
        // Payload só com inteiros int64 (validado na borda); JsonCru evita float.
        $payloadJson = JsonCru::codificar($payload, false);

        $this->conexao->statement(
            <<<'SQL'
            INSERT INTO fatos.recebimento
                (titular_id, competencia, tipo, tributo, fonte, chave_idempotencia, observado_em,
                 tipo_versao, hash, efeito, fato_id, payload, valor_centavos)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?)
            SQL,
            [
                $titularId, $competencia, $tipo, $tributo, $fonte, $chaveIdempotencia, $observadoEm,
                $tipoVersao, $hash, $efeito, $fatoId, $payloadJson, $valorCentavos,
            ],
        );
    }

    public function porIdempotencia(string $fonte, string $chaveIdempotencia): ?array
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT r.efeito, r.fato_id::text AS fato_id, f.versao
            FROM fatos.recebimento r
            JOIN fatos.fato f ON f.id = r.fato_id
            WHERE r.fonte = ? AND r.chave_idempotencia = ?
            ORDER BY r.recebido_em ASC
            LIMIT 1
            SQL,
            [$fonte, $chaveIdempotencia],
        );

        if ($linha === null) {
            return null;
        }

        /** @var array{efeito: string, fato_id: string, versao: int} $row */
        $row = (array) $linha;

        return ['efeito' => $row['efeito'], 'fato_id' => $row['fato_id'], 'versao' => (int) $row['versao']];
    }
}
