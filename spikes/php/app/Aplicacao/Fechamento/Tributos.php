<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Dominio\Fechamento\Caso;

/**
 * Tributo da guia de entrada do E11 e tributo da apuração, por regime:
 * SN → DAS, LP → IRPJ (§9.1 CF-05/CF-07, §10.1).
 */
final readonly class Tributos
{
    public static function guia(Caso $caso): string
    {
        return self::regime($caso) === 'LP' ? 'IRPJ' : 'DAS';
    }

    public static function apuracao(Caso $caso): string
    {
        return self::regime($caso) === 'LP' ? 'IRPJ' : 'DAS';
    }

    private static function regime(Caso $caso): string
    {
        return $caso->empresa->regime;
    }
}
