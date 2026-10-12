<?php

declare(strict_types=1);

namespace App\Dominio\Regras;

/** Pós-validação do E11 (§10.1): liberação e protocolo. */
final readonly class PosValidacao
{
    public function __construct(
        public string $liberacao,
        public ?string $disponibilizadoQuandoTipo,
    ) {}

    /** @param  array<string, mixed>  $pos */
    public static function de(array $pos): self
    {
        $disp = $pos['disponibilizado_quando'] ?? null;
        $tipo = (is_array($disp) && is_string($disp['tipo'] ?? null)) ? $disp['tipo'] : null;

        return new self(
            liberacao: is_string($pos['liberacao'] ?? null) ? $pos['liberacao'] : 'automatica',
            disponibilizadoQuandoTipo: $tipo,
        );
    }
}
