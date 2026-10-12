<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Regra 'fiscal.tolerancia' (§9.2). Valores em centavos. */
final readonly class Tolerancia
{
    public function __construct(
        public string $versaoId,
        public int $okAteCentavos,
        public int $alertaAteCentavos,
    ) {}

    /** @param  array<string, mixed>  $conteudo */
    public static function de(string $versaoId, array $conteudo): self
    {
        $padrao = $conteudo['padrao'] ?? [];
        $ok = (is_array($padrao) && is_int($padrao['ok_ate_centavos'] ?? null)) ? $padrao['ok_ate_centavos'] : 0;
        $alerta = (is_array($padrao) && is_int($padrao['alerta_ate_centavos'] ?? null)) ? $padrao['alerta_ate_centavos'] : 0;

        return new self($versaoId, $ok, $alerta);
    }
}
