<?php

declare(strict_types=1);

namespace App\Dominio\Fechamento;

/**
 * Condição `quando` (§3): lista de objetos.
 * - verdadeira se ALGUM objeto casa (OU);
 * - um objeto casa se TODOS os pares campo:valor forem iguais no contexto (E);
 * - lista vazia (ou campo ausente num objeto) → sempre verdadeira.
 */
final class Condicao
{
    /**
     * @param  list<array<string, scalar>>  $quando
     */
    public static function casa(array $quando, Contexto $ctx): bool
    {
        if ($quando === []) {
            return true;
        }

        foreach ($quando as $objeto) {
            if (self::objetoCasa($objeto, $ctx)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, scalar>  $objeto
     */
    private static function objetoCasa(array $objeto, Contexto $ctx): bool
    {
        foreach ($objeto as $campo => $valor) {
            if ($ctx->valor($campo) !== $valor) {
                return false;
            }
        }

        return true;
    }
}
