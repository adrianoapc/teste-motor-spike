<?php

declare(strict_types=1);

namespace Tests\Unidade\Fakes;

use App\Aplicacao\Portas\RepositorioRecebimento;

final class RecebimentosMemoria implements RepositorioRecebimento
{
    /** @var list<array<string, mixed>> linhas gravadas, na ordem */
    public array $linhas = [];

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
        $this->linhas[] = [
            'titular_id' => $titularId, 'competencia' => $competencia, 'tipo' => $tipo, 'tributo' => $tributo,
            'fonte' => $fonte, 'chave_idempotencia' => $chaveIdempotencia, 'observado_em' => $observadoEm,
            'tipo_versao' => $tipoVersao, 'hash' => $hash, 'efeito' => $efeito, 'fato_id' => $fatoId,
            'payload' => $payload, 'valor_centavos' => $valorCentavos,
        ];
    }

    public function porIdempotencia(string $fonte, string $chaveIdempotencia): ?array
    {
        foreach ($this->linhas as $l) {
            if ($l['fonte'] === $fonte && $l['chave_idempotencia'] === $chaveIdempotencia) {
                /** @var string $fatoId */
                $fatoId = $l['fato_id'];
                /** @var string $efeito */
                $efeito = $l['efeito'];

                return ['efeito' => $efeito, 'fato_id' => $fatoId, 'versao' => 1];
            }
        }

        return null;
    }
}
