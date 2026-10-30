<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

interface RepositorioRecebimento
{
    /**
     * Grava uma linha de recebimento de toda publicação válida (§6.4), inclusive as
     * que não viram fato (sem_mudanca/obsoleto/conflito_fonte) e as saídas de tarefa.
     *
     * @param  mixed  $payload  o que chegou (objeto/array preservado)
     */
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
    ): void;

    /**
     * Recebimento anterior com a mesma (fonte, chave_idempotencia), para a
     * idempotência de requisição (§6.5). Null se não há. Retorna o efeito, fato_id e
     * a versão do fato daquela primeira vez.
     *
     * @return array{efeito: string, fato_id: string, versao: int}|null
     */
    public function porIdempotencia(string $fonte, string $chaveIdempotencia): ?array;
}
