<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/**
 * Número JSON que não é inteiro int64 simples (fração, expoente, inteiro além de int64, "-0").
 * Marcador usado só para rejeitar o payload (422); guarda o lexema para a mensagem e nunca passa por float.
 */
final readonly class NumeroJson
{
    public function __construct(public string $lexema) {}
}
