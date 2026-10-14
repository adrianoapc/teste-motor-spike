<?php

declare(strict_types=1);

namespace App\Aplicacao\Fechamento;

use App\Dominio\Fechamento\Caso;

/**
 * Tributo da guia de entrada do E11 e da apuração (CF-05, CF-07, §10.1), por regime: SN → DAS, LP → IRPJ.
 * A apuração e a guia compartilham o tributo, então há uma única função.
 */
final readonly class Tributos
{
    public static function guia(Caso $caso): string
    {
        return $caso->empresa->regime === 'LP' ? 'IRPJ' : 'DAS';
    }
}
