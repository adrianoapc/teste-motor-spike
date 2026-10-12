<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Bloco de acompanhamento depois do protocolo (§10.3), no E11. */
final readonly class Acompanhamento
{
    public function __construct(
        public ?string $falhaTipo,
        public ?string $confirmacaoTipo,
        public ?string $pendenciaTipo,
    ) {}

    /** @param  array<string, mixed>  $a */
    public static function de(array $a): self
    {
        return new self(
            falhaTipo: self::tipo($a, 'falha'),
            confirmacaoTipo: self::tipo($a, 'confirmacao'),
            pendenciaTipo: self::tipo($a, 'pendencia'),
        );
    }

    /** @param  array<string, mixed>  $a */
    private static function tipo(array $a, string $chave): ?string
    {
        $bloco = $a[$chave] ?? null;

        return (is_array($bloco) && is_string($bloco['tipo'] ?? null)) ? $bloco['tipo'] : null;
    }
}
