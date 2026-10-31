<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

/** As três datas de prazo de um entregável (§18.2): sempre as três juntas, acao ≤ meta ≤ vencimento. */
final readonly class Prazo
{
    public function __construct(
        public Data $vencimento,
        public Data $meta,
        public Data $acao,
    ) {}
}
