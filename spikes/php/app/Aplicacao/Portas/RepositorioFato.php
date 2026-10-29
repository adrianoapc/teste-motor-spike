<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fatos\Fato;

interface RepositorioFato
{
    /**
     * Serializa, até o fim da transação, a alocação de versão da chave do fato
     * (pg_advisory_xact_lock). Deve ser chamado antes de vigente() ao publicar.
     */
    public function travarChave(string $titularId, string $competencia, string $tipo, string $tributo): void;

    /** Fato vigente (maior versão) de uma chave, ou null. */
    public function vigente(string $titularId, string $competencia, string $tipo, string $tributo): ?Fato;

    /** Fato por id (qualquer versão), ou null. Usado pela idempotência de requisição (§6.5). */
    public function porId(string $fatoId): ?Fato;

    /**
     * Insere uma nova versão do fato. $substituiId = id da versão anterior (ou null).
     *
     * @param  mixed  $payload  objeto/array preservado como veio (hash canônico)
     */
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
    ): Fato;

    /**
     * Fatos vigentes do caso (mesmo titular e competência).
     *
     * @return list<Fato>
     */
    public function vigentesDoCaso(string $titularId, string $competencia): array;
}
