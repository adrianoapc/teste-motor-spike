<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/**
 * Número JSON que não pode virar int64 nativo sem perda (fração, expoente, inteiro além de int64, "-0").
 * Guarda o lexema EXATO enviado; nunca passa por float.
 */
final readonly class NumeroJson
{
    public function __construct(public string $lexema) {}
}
