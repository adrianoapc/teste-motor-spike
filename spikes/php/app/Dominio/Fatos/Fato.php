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
    ) {}
}
