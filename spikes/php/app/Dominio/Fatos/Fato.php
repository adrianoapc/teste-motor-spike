<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

/** Fato vigente ou versão de fato (§1, §6). payload preserva {} vs []. */
final readonly class Fato
{
    public function __construct(
        public string $id,
        public string $titularId,
        public string $competencia,
        public string $tipo,
        public string $tributo,
        public int $versao,
        public ?int $valorCentavos,
        public mixed $payload,
        public string $hash,
        // v5 (§6.4): fonte e observado_em da vigente decidem obsoleto/conflito_fonte;
        // tipo_versao é o contrato em uso quando o fato foi gravado (null em fato pré-V009).
        public string $fonte = '',
        public ?string $observadoEm = null,
        public ?int $tipoVersao = null,
    ) {}
}
