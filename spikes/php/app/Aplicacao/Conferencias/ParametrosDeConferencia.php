<?php

declare(strict_types=1);

namespace App\Aplicacao\Conferencias;

use App\Dominio\Regras\Tolerancia;

/**
 * Tudo o que as conferências de um caso precisam além dos fatos: as fórmulas (já com os parâmetros fiscais
 * em uso), a tolerância em uso e o tributo da guia do caso (SN → DAS, LP → IRPJ; §9.1 CF-05/06/07, §10.1).
 */
final readonly class ParametrosDeConferencia
{
    public function __construct(
        public Formulas $formulas,
        public Tolerancia $tolerancia,
        public string $tributoGuia,
    ) {}
}
