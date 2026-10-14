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

    /**
     * Exige `prolabore_percentual_bp` inteiro entre 0 e 10000 (pontos-base). Ausente ou malformado é
     * {@see RegraInvalida}: nunca vira pró-labore 0%.
     *
     * @param  array<string, mixed>  $conteudo
     */
    public static function de(string $versaoId, array $conteudo): self
    {
        $bp = $conteudo['prolabore_percentual_bp'] ?? null;
        if (! is_int($bp)) {
            throw RegraInvalida::em('fiscal.parametros', $versaoId, "'prolabore_percentual_bp' ausente ou não é inteiro");
        }
        if ($bp < 0 || $bp > 10000) {
            throw RegraInvalida::em('fiscal.parametros', $versaoId, "'prolabore_percentual_bp' deve estar entre 0 e 10000");
        }

        return new self($versaoId, $bp);
    }
}
