<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

use App\Dominio\Fatos\Fato;

interface RepositorioFato
{
    /** Fato vigente (maior versão) de uma chave, ou null. */
    public function vigente(string $titularId, string $competencia, string $tipo, string $tributo): ?Fato;

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
    ): Fato;

    /**
     * Fatos vigentes do caso (mesmo titular e competência).
     *
     * @return list<Fato>
     */
    public function vigentesDoCaso(string $titularId, string $competencia): array;
}
