<?php

declare(strict_types=1);

namespace App\Aplicacao\Portas;

/** Hora do banco (§13: nunca o relógio do PHP para gravar ou ordenar). */
interface Relogio
{
    /** Instante atual do banco (now()), como texto de timestamptz (ex.: 2026-10-14 19:22:47.123456+00). */
    public function agora(): string;
}
