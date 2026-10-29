<?php

declare(strict_types=1);

namespace App\Dominio\Fatos;

/** Uma violação de contrato (§6.2): campo e regra quebrada. */
final readonly class Violacao
{
    /**
     * @param  'obrigatorio'|'proibido'|'fora_da_lista'|'tipo'|'esquema'  $regra
     */
    public function __construct(
        public string $campo,
        public string $regra,
    ) {}

    /** @return array{campo: string, regra: string} */
    public function paraArray(): array
    {
        return ['campo' => $this->campo, 'regra' => $this->regra];
    }
}
