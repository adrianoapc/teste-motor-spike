<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Hora do banco (§13: nunca o relógio local para ordenar transições). */
interface Relogio
{
    /** Instante atual do banco (now()), em ISO-8601. */
    public function agora(): string;
}
