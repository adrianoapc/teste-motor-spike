<?php

declare(strict_types=1);

namespace App\Dominio\Prazos;

/**
 * As três regras de prazo em uso juntas (§18.1), com os ids de versão de cada uma. Só a de
 * `fechamento.prazos` é fixada no caso (prazosVersaoId); o calendário e o catálogo não são
 * fixados — quando mudam, AplicarCalendario (§18.3) recalcula. O trio alimenta o CalculoDePrazos.
 */
final readonly class RegrasDePrazo
{
    public function __construct(
        public string $calendarioVersaoId,
        public int $calendarioVersao,
        public string $catalogoVersaoId,
        public string $prazosVersaoId,
        public CalculoDePrazos $calculo,
    ) {}
}
