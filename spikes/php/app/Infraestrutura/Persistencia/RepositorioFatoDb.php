<?php

declare(strict_types=1);

namespace App\Infraestrutura\Persistencia;

use App\Aplicacao\Portas\RepositorioFato;
use App\Dominio\Fatos\Fato;
use App\Dominio\Fechamento\JsonCru;
use Illuminate\Database\ConnectionInterface;

final readonly class RepositorioFatoDb implements RepositorioFato
{
    public function __construct(private ConnectionInterface $conexao) {}

    public function travarChave(string $titularId, string $competencia, string $tipo, string $tributo): void
    {
        $chave = json_encode([$titularId, $competencia, $tipo, $tributo], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->conexao->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chave]);
    }

    public function travarIdempotencia(string $fonte, string $chaveIdempotencia): void
    {
        // §6.5: serializa publicações com a mesma (fonte, chave) mesmo entre titulares
        // diferentes, para que o índice único global recebimento_idempotencia nunca
        // seja violado. Chave = 'idem|' || fonte || '|' || chave, a mesma nas três spikes.
        $chave = 'idem|'.$fonte.'|'.$chaveIdempotencia;
        $this->conexao->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chave]);
    }

    public function vigente(string $titularId, string $competencia, string $tipo, string $tributo): ?Fato
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, hash,
                   fonte, observado_em, tipo_versao
            FROM fatos.fato_vigente
            WHERE titular_id = ? AND competencia = ? AND tipo = ? AND tributo = ?
            SQL,
            [$titularId, $competencia, $tipo, $tributo],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function porId(string $fatoId): ?Fato
    {
        $linha = $this->conexao->selectOne(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, hash,
                   fonte, observado_em, tipo_versao
            FROM fatos.fato
            WHERE id = ?
            SQL,
            [$fatoId],
        );

        return $linha === null ? null : $this->mapear($linha);
    }

    public function inserir(
        string $titularId,
        string $competencia,
        string $tipo,
        string $tributo,
        int $versao,
        ?int $valorCentavos,
        mixed $payload,
        string $fonte,
        ?string $origemRef,
        string $hash,
        ?string $substituiId,
        string $observadoEm,
        int $tipoVersao,
    ): Fato {
        // Payload só tem inteiros int64 (validado na borda); JsonCru evita qualquer passagem por float.
        $payloadJson = JsonCru::codificar($payload, false);

        $linha = $this->conexao->selectOne(
            <<<'SQL'
            INSERT INTO fatos.fato
                (titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, fonte, origem_ref, hash, substitui_id, observado_em, tipo_versao)
            VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?)
            RETURNING id::text, titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, hash, fonte, observado_em, tipo_versao
            SQL,
            [
                $titularId, $competencia, $tipo, $tributo, $versao, $valorCentavos,
                $payloadJson, $fonte, $origemRef, $hash, $substituiId, $observadoEm, $tipoVersao,
            ],
        );
        assert($linha !== null);

        return $this->mapear($linha);
    }

    /** @return list<Fato> */
    public function vigentesDoCaso(string $titularId, string $competencia): array
    {
        $linhas = $this->conexao->select(
            <<<'SQL'
            SELECT id::text, titular_id, competencia, tipo, tributo, versao, valor_centavos, payload, hash,
                   fonte, observado_em, tipo_versao
            FROM fatos.fato_vigente
            WHERE titular_id = ? AND competencia = ?
            SQL,
            [$titularId, $competencia],
        );

        $saida = [];
        foreach ($linhas as $linha) {
            $saida[] = $this->mapear($linha);
        }

        return $saida;
    }

    private function mapear(mixed $linha): Fato
    {
        /** @var array{id: string, titular_id: string, competencia: string, tipo: string, tributo: string, versao: int, valor_centavos: ?int, payload: string, hash: string, fonte: string, observado_em: ?string, tipo_versao: ?int} $row */
        $row = (array) $linha;
        // payload como objeto (preserva {} vs []) para o hash e para as fórmulas.
        $payload = json_decode($row['payload'], false, flags: JSON_THROW_ON_ERROR);

        return new Fato(
            id: $row['id'],
            titularId: $row['titular_id'],
            competencia: trim($row['competencia']),
            tipo: $row['tipo'],
            tributo: $row['tributo'],
            versao: (int) $row['versao'],
            valorCentavos: $row['valor_centavos'] === null ? null : (int) $row['valor_centavos'],
            payload: $payload,
            hash: $row['hash'],
            fonte: $row['fonte'],
            observadoEm: $row['observado_em'],
            tipoVersao: $row['tipo_versao'] === null ? null : (int) $row['tipo_versao'],
        );
    }
}
