<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Regra 'fiscal.parametros' (CF-08). */
final readonly class Parametros
{
    public function __construct(
        public string $versaoId,
        public int $prolaborePercentualBp,
    ) {}

    /** @param  array<string, mixed>  $conteudo */
    public static function de(string $versaoId, array $conteudo): self
    {
        $bp = is_int($conteudo['prolabore_percentual_bp'] ?? null) ? $conteudo['prolabore_percentual_bp'] : 0;

        return new self($versaoId, $bp);
    }
}
