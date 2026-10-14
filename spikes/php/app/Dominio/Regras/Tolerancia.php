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

    /**
     * Exige `padrao.ok_ate_centavos` e `padrao.alerta_ate_centavos` inteiros, não negativos, com
     * ok_ate <= alerta_ate. Qualquer coisa fora disso é {@see RegraInvalida}: nunca vira tolerância 0/0.
     *
     * @param  array<string, mixed>  $conteudo
     */
    public static function de(string $versaoId, array $conteudo): self
    {
        $padrao = $conteudo['padrao'] ?? null;
        if (! is_array($padrao)) {
            throw RegraInvalida::em('fiscal.tolerancia', $versaoId, "'padrao' ausente ou não é objeto");
        }
        $ok = $padrao['ok_ate_centavos'] ?? null;
        $alerta = $padrao['alerta_ate_centavos'] ?? null;
        if (! is_int($ok)) {
            throw RegraInvalida::em('fiscal.tolerancia', $versaoId, "'padrao.ok_ate_centavos' ausente ou não é inteiro");
        }
        if (! is_int($alerta)) {
            throw RegraInvalida::em('fiscal.tolerancia', $versaoId, "'padrao.alerta_ate_centavos' ausente ou não é inteiro");
        }
        if ($ok < 0 || $alerta < $ok) {
            throw RegraInvalida::em('fiscal.tolerancia', $versaoId, 'exige 0 <= ok_ate_centavos <= alerta_ate_centavos');
        }

        return new self($versaoId, $ok, $alerta);
    }
}
